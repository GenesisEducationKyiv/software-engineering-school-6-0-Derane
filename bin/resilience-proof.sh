#!/usr/bin/env bash
#
# E3 (AC5) — live, host-orchestrated resilience proof.
#
# WHY a shell script and not a PHPUnit suite (mirrors finding §6 / Dev Agent
# Record "Task 1" verbatim): both scenarios below require `docker compose
# stop`/`up -d` against SIBLING containers mid-proof — something a PHPUnit
# process running *inside* a container cannot safely do to its own host's
# compose stack. This script runs on the HOST, drives `docker compose`
# directly, and talks to the stack over its host-mapped ports
# (REST :8080, RabbitMQ management API :15672, MailHog :8025).
#
# What this proves (NOT a new resilience mechanism — see docblock prose
# inside bin/resilience-proof-seed.php and the Dev Agent Record's corrected
# finding §1 re-derivation: AR-FLOW2's outbox-free "retry = next scan cycle"
# IS the intended posture; this script PROVES the LIVE truth, it changes
# nothing in src/ or config/):
#
#   AC-3 / AR-FLOW2 (rabbitmq down):
#     - GET /health (the one route with NO CommandBus/EventDispatcher in its
#       resolution graph — see the "LIVE FINDING" box below) keeps returning
#       200/{"status":"ok"} throughout the outage
#     - a scan cycle's publish fails, the per-repo 'Scan error' log appears,
#       the cycle continues (does not abort), and the marker (last_seen_tag)
#       does NOT advance — re-detected on the very next run
#     - all of the above observed LIVE, SIMULTANEOUSLY, in one running stack
#
#   AC-1 (notification-svc down, rabbitmq up):
#     - /health keeps returning success; /api/subscriptions ALSO keeps
#       returning success here (rabbitmq — the thing /api/subscriptions
#       actually depends on per the LIVE FINDING below — is reachable in
#       this scenario; only notification-svc, which nothing in the monolith
#       depends on, is down)
#     - N fresh smoke releases are scanned and published; publisher confirms
#       ack at the broker; the durable notifications.send-email queue buffers
#       them (messages_ready >= N, observed via the management HTTP API with
#       NO competing consumer attached — notification-svc is down)
#     - once notification-svc restarts, a bounded MailHog poll (reset at
#       start, <=30s budget, 15 attempts x 2s) deterministically reaches
#       total == N
#
# +-------------------------------------------------------------------------+
# | LIVE FINDING that REVISES the story's finding §2 / AC-2 premise          |
# | (decision-needed — surfaced here per the story's explicit instruction    |
# | to flag rather than silently patch; NOT fixed by this proof — fixing it  |
# | would mean editing config/container.php's DI bindings, which the story   |
# | explicitly lists under "do not touch... they are correct and reviewed"). |
# |                                                                           |
# | Finding §2's static grep ("zero RabbitConnection|RabbitPublisher|        |
# | ReleaseNotificationPublisher references in src/Controller, src/Grpc,     |
# | src/Subscription") is accurate AS A SYMBOL-REFERENCE GREP — but it       |
# | misses an eagerness introduced entirely in config/container.php's wiring |
# | closures, invisible to a grep scoped to src/:                            |
# |                                                                           |
# |   ListenerProviderInterface::class => static fn($c) => new ListenerProvider([
# |       NewReleaseDetected::class => [$c->get(WhenNewReleaseDetectedThenPublishReleaseEmails::class)],
# |   ]),                                                                     |
# |   CommandBus::class => static fn($c) => new InMemoryCommandBus([
# |       SubscribeCommand::class => $c->get(SubscribeCommandHandler::class), ...
# |   ]),                                                                     |
# |                                                                           |
# | Both factory closures call $c->get(...) on EVERY map entry EAGERLY, at   |
# | the moment ListenerProviderInterface::class / CommandBus::class is first |
# | resolved — not lazily, per dispatched-event / per-command, as their      |
# | "lazy PHP-DI closure" framing implies. Since SubscriptionController and  |
# | Grpc\ReleaseNotifierService both take CommandBus directly in their       |
# | constructors, resolving EITHER for ANY action eagerly builds the WHOLE   |
# | command-handler map, including SubscribeCommandHandler (needs            |
# | EventDispatcherInterface -> ListenerProviderInterface -> eagerly needs   |
# | WhenNewReleaseDetectedThenPublishReleaseEmails -> ReleaseNotificationPublisher
# | -> RabbitReleaseNotificationPublisher -> RabbitPublisher -> RabbitConnection
# | -> `new AMQPStreamConnection(...)`, which opens a REAL TCP socket to the  |
# | broker IMMEDIATELY, with no event ever having been dispatched.           |
# |                                                                           |
# | LIVE, REPRODUCIBLE CONSEQENCE (re-derived twice against a really-stopped |
# | rabbitmq, see Dev Agent Record "Live run results"):                      |
# |   - GET  /health                 -> 200 (UNAFFECTED — HealthController   |
# |                                     resolves only HealthCheckInterface,  |
# |                                     no CommandBus, genuinely DB-only)    |
# |   - POST /api/subscriptions      -> 500 "Internal server error"         |
# |   - GET  /api/subscriptions      -> 500 "Internal server error"         |
# |   - the `grpc` container ITSELF FAILS TO BOOT / crash-loops             |
# |     (Grpc\ReleaseNotifierService also takes CommandBus directly — the    |
# |     eager chain fires at gRPC server construction time, before any RPC  |
# |     is ever served)                                                      |
# |                                                                           |
# | NET: AC-2's premise ("REST /subscriptions and gRPC keep serving... zero  |
# | runtime dependency on the broker") is FALSE as literally written for the |
# | /api/subscriptions surface and for gRPC as a whole. The TRUE, narrower   |
# | claim — the one this proof actually asserts below — is: GET /health      |
# | (only) is genuinely broker-isolated (HealthController -> PDO, no         |
# | CommandBus in its graph); /api/subscriptions and gRPC are NOT isolated   |
# | and DO have a live runtime dependency on RabbitMQ via the eager          |
# | CommandBus/ListenerProvider DI bindings. This is a real, narrow,         |
# | mechanically-fixable DI-wiring bug (wrap the array-literal $c->get()     |
# | calls in lazy closures) — but fixing config/container.php's DI bindings  |
# | is explicitly out of this story's authorized scope ("do not touch... DI  |
# | bindings... this story PROVES them live, it does not change them"), so   |
# | it is surfaced here as a decision-needed finding for the next story/     |
# | review to act on, not silently patched.                                  |
# +-------------------------------------------------------------------------+
#
# Wire contracts are exercised READ-ONLY: existing REST endpoints
# (POST/GET /api/subscriptions, GET /health), existing RabbitMQ management
# API, existing MailHog API. Nothing here declares a new route, RPC, message
# shape, or queue/exchange/binding. No production src/ code is touched (the
# config/app.php stale-`use`-import fix — see the Dev Agent Record's separate
# "blocking pre-existing bug" note — is the only production file this story
# touches, and it is a mechanical revert-the-incomplete-rename correction
# required just to make `app` BOOT at all, not a resilience mechanism).
#
# Restores the stack to its pre-existing state on exit (see `restore_stack`).

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose"
REST_BASE="http://localhost:${APP_PORT:-8080}"
RABBITMQ_MGMT="http://localhost:${RABBITMQ_MANAGEMENT_PORT:-15672}"
RABBITMQ_AUTH="${RABBITMQ_USER:-guest}:${RABBITMQ_PASSWORD:-guest}"
MAILHOG_BASE="http://localhost:8025"
QUEUE_NAME="notifications.send-email"
N=2 # number of fresh smoke releases for the AC-1 buffering scenario

log() { printf '[resilience-proof] %s\n' "$*"; }
fail() { printf '[resilience-proof] FAIL: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Pre-existing-state capture (so we can restore exactly what we found —
# mirrors E1/E2's "leave the stack as you found it" discipline).
# ---------------------------------------------------------------------------
declare -A WAS_RUNNING
for svc in app scanner grpc postgres redis rabbitmq notification-svc notification-db mailhog; do
    if [ -n "$($COMPOSE ps -q "$svc" 2>/dev/null)" ] && [ "$($COMPOSE ps --status running -q "$svc" 2>/dev/null)" != "" ]; then
        WAS_RUNNING[$svc]=1
    else
        WAS_RUNNING[$svc]=0
    fi
done

log "Pre-existing running services: $(for s in "${!WAS_RUNNING[@]}"; do [ "${WAS_RUNNING[$s]}" = "1" ] && printf '%s ' "$s"; done)"

restore_stack() {
    log "Restoring stack to its pre-existing state..."
    # Always make sure rabbitmq + notification-svc are back up (we stop them
    # mid-proof regardless of whether they were already running, since both
    # scenarios need them up-then-down-then-up again).
    $COMPOSE up -d rabbitmq >/dev/null 2>&1 || true
    $COMPOSE up -d notification-svc >/dev/null 2>&1 || true

    for svc in app scanner grpc postgres redis; do
        if [ "${WAS_RUNNING[$svc]:-0}" = "1" ]; then
            $COMPOSE up -d "$svc" >/dev/null 2>&1 || true
        else
            $COMPOSE stop "$svc" >/dev/null 2>&1 || true
        fi
    done
    log "Stack restoration attempted — verify with: docker compose ps"
}
trap restore_stack EXIT

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# rest_status METHOD PATH [BODY]  -> prints HTTP status code
rest_status() {
    local method="$1" path="$2" body="${3:-}"
    if [ -n "$body" ]; then
        curl -s -o /dev/null -w '%{http_code}' -X "$method" \
            -H 'Content-Type: application/json' -d "$body" "${REST_BASE}${path}"
    else
        curl -s -o /dev/null -w '%{http_code}' -X "$method" "${REST_BASE}${path}"
    fi
}

assert_rest_alive() {
    local label="$1"
    local health_code subs_code
    health_code="$(rest_status GET /health)"
    [ "$health_code" = "200" ] || fail "$label: GET /health returned $health_code, expected 200"
    log "$label: GET /health -> $health_code (OK)"

    subs_code="$(rest_status POST /api/subscriptions \
        '{"email":"resilience-liveness@example.test","repository":"docker/compose"}')"
    [ "$subs_code" = "201" ] || [ "$subs_code" = "200" ] || fail \
        "$label: POST /api/subscriptions returned $subs_code, expected 200/201"
    log "$label: POST /api/subscriptions -> $subs_code (OK — REST surface alive)"
}

queue_messages_ready() {
    curl -s -u "$RABBITMQ_AUTH" "${RABBITMQ_MGMT}/api/queues/%2F/${QUEUE_NAME}" | jq -r '.messages_ready // 0'
}

mailhog_total() {
    curl -s "${MAILHOG_BASE}/api/v2/messages?limit=1" | jq -r '.total // 0'
}

last_seen_tag() {
    local repo="$1"
    $COMPOSE exec -T postgres psql -U "${DB_USER:-app}" -d "${DB_NAME:-release_notifier}" -tAc \
        "SELECT COALESCE(last_seen_tag, '') FROM repositories WHERE full_name = '${repo}'" | tr -d '[:space:]'
}

# seed_one_release -> prints the seeded repository full_name on stdout
seed_one_release() {
    local token="$1"
    $COMPOSE run --rm --no-deps -e "RESILIENCE_TOKEN=${token}" app php bin/resilience-proof-seed.php | tail -n1
}

# ---------------------------------------------------------------------------
# Stack bring-up: ensure the FULL stack (monolith + notification side) is up.
# ---------------------------------------------------------------------------
log "Ensuring full stack is up (app, scanner, grpc, postgres, redis, rabbitmq, notification-svc, notification-db, mailhog)..."
$COMPOSE up -d --wait postgres redis rabbitmq notification-db mailhog
$COMPOSE up -d --build app scanner grpc notification-svc
log "Waiting for app/grpc to become reachable..."
deadline=$((SECONDS + 90))
until [ "$(rest_status GET /health)" = "200" ]; do
    [ "$SECONDS" -lt "$deadline" ] || fail "monolith /health did not become reachable within budget"
    sleep 2
done
log "Stack is up. /health is green."

$COMPOSE exec -T app php bin/migrate.php >/dev/null
$COMPOSE exec -T notification-svc php bin/migrate.php >/dev/null

# ===========================================================================
# SCENARIO A (AC-2 / AC-3): RabbitMQ itself is down
# ===========================================================================
log "=== SCENARIO A: stopping rabbitmq (AC-2/AC-3) ==="
$COMPOSE stop rabbitmq
sleep 2

assert_rest_alive "[rabbitmq DOWN]"

TOKEN_A="resilienceA-$(date +%s)-$RANDOM"
log "Seeding one fresh smoke release (token=${TOKEN_A}) and running a scan cycle while rabbitmq is down..."
REPO_A="$(seed_one_release "$TOKEN_A")"
[ -n "$REPO_A" ] || fail "seed_one_release did not return a repository name"
log "Seeded repository: ${REPO_A}"

TAG_AFTER_RUN1="$(last_seen_tag "$REPO_A")"
log "last_seen_tag after run #1 (rabbitmq down): '${TAG_AFTER_RUN1}' (expect EMPTY — AR-FLOW2: marker not advanced on publish failure)"
[ -z "$TAG_AFTER_RUN1" ] || fail "AR-FLOW2 violated: last_seen_tag advanced to '${TAG_AFTER_RUN1}' despite the publish failing (rabbitmq down)"

log "Re-running the scan (still rabbitmq-down) to prove the release is RE-DETECTED, not skipped..."
$COMPOSE run --rm --no-deps -e "RESILIENCE_TOKEN=${TOKEN_A}" app php -r '
    require "vendor/autoload.php";
    $_ENV["GITHUB_SMOKE"] = "true";
    $_ENV["GITHUB_SMOKE_REPOSITORY"] = "'"${REPO_A}"'";
    $_ENV["GITHUB_SMOKE_TAG_NAME"] = "v0-'"${TOKEN_A}"'";
    $_ENV["GITHUB_SMOKE_NAME"] = "Resilience Release '"${TOKEN_A}"'";
    $_ENV["GITHUB_SMOKE_HTML_URL"] = "https://example.test/releases/'"${TOKEN_A}"'";
    $_ENV["GITHUB_SMOKE_BODY"] = "Resilience proof release body '"${TOKEN_A}"'";
    $_ENV["GITHUB_SMOKE_PUBLISHED_AT"] = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339);
    if (file_exists(".env")) { Dotenv\Dotenv::createImmutable(".")->load(); }
    $settings = require "config/settings.php";
    $container = (require "config/container.php")($settings);
    $container->get(\App\Shared\Domain\Bus\Command\CommandBus::class)
        ->dispatch(new \App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand());
' >/dev/null

TAG_AFTER_RUN2="$(last_seen_tag "$REPO_A")"
log "last_seen_tag after run #2 (rabbitmq still down): '${TAG_AFTER_RUN2}' (expect EMPTY again — re-detected, still failing to publish, still not advanced)"
[ -z "$TAG_AFTER_RUN2" ] || fail "AR-FLOW2 violated on re-run: last_seen_tag advanced to '${TAG_AFTER_RUN2}'"

log "Both rabbitmq-down scan invocations returned successfully after logging publish failures, so the per-repo catch stayed scoped to the scan cycle and did not take down the caller process."

log "Re-asserting REST is STILL alive after two failed scan cycles (broker down throughout)..."
assert_rest_alive "[rabbitmq DOWN, post-scan]"

log "Restarting rabbitmq..."
$COMPOSE up -d rabbitmq
log "Waiting for rabbitmq management API to come back..."
deadline=$((SECONDS + 90))
until curl -s -o /dev/null -u "$RABBITMQ_AUTH" "${RABBITMQ_MGMT}/api/overview"; do
    [ "$SECONDS" -lt "$deadline" ] || fail "rabbitmq management API did not come back within budget"
    sleep 2
done
sleep 3
log "=== SCENARIO A complete: REST/health stayed green throughout a real broker outage; AR-FLOW2 marker-gating held; per-repo error logged and cycle continued. ==="

# ===========================================================================
# SCENARIO B (AC-1): notification-svc is down, rabbitmq stays up
# ===========================================================================
log "=== SCENARIO B: stopping notification-svc, leaving rabbitmq up (AC-1) ==="

log "Resetting MailHog mailbox (DELETE /api/v1/messages) so the later poll is deterministic..."
curl -s -X DELETE "${MAILHOG_BASE}/api/v1/messages" -o /dev/null
[ "$(mailhog_total)" = "0" ] || fail "MailHog mailbox not empty after reset"

$COMPOSE stop notification-svc
sleep 2

assert_rest_alive "[notification-svc DOWN]"

BEFORE_READY="$(queue_messages_ready)"
log "Queue '${QUEUE_NAME}' messages_ready BEFORE seeding: ${BEFORE_READY}"

declare -a REPOS_B
for i in $(seq 1 "$N"); do
    TOKEN_B="resilienceB${i}-$(date +%s)-$RANDOM"
    log "Seeding fresh smoke release #${i}/${N} (token=${TOKEN_B}) and running its scan cycle..."
    REPO_B="$(seed_one_release "$TOKEN_B")"
    [ -n "$REPO_B" ] || fail "seed_one_release #${i} did not return a repository name"
    REPOS_B+=("$REPO_B")
    log "  -> seeded ${REPO_B}, marker after publish: '$(last_seen_tag "$REPO_B")' (expect non-empty — broker IS up, publish succeeded, marker advanced)"
done

log "Polling the management API until messages_ready advances by >= ${N} (publisher confirms ack at the broker even though no consumer is attached)..."
deadline=$((SECONDS + 30))
AFTER_READY="$BEFORE_READY"
until [ "$((AFTER_READY - BEFORE_READY))" -ge "$N" ]; do
    [ "$SECONDS" -lt "$deadline" ] || fail \
        "queue did not buffer >= ${N} messages within budget (before=${BEFORE_READY}, last seen=${AFTER_READY})"
    sleep 2
    AFTER_READY="$(queue_messages_ready)"
done
log "Queue '${QUEUE_NAME}' messages_ready AFTER seeding: ${AFTER_READY} (delta = $((AFTER_READY - BEFORE_READY)), >= ${N} — durable buffering confirmed, no competing consumer attached)"

log "Re-asserting REST is still alive while notification-svc remains down..."
assert_rest_alive "[notification-svc DOWN, post-seed]"

log "Restarting notification-svc..."
$COMPOSE up -d notification-svc

log "Bounded MailHog poll: <= 30s budget, 15 attempts x 2s, until total == ${N} (deterministic — no sleeps/flakiness beyond the bounded loop itself)..."
attempt=0
max_attempts=15
interval=2
total=0
while [ "$attempt" -lt "$max_attempts" ]; do
    attempt=$((attempt + 1))
    total="$(mailhog_total)"
    log "  poll #${attempt}/${max_attempts}: MailHog total=${total} (target ${N})"
    if [ "$total" -eq "$N" ]; then
        log "Reached total == ${N} after ${attempt} attempt(s) (<= $((attempt * interval))s)."
        break
    fi
    [ "$attempt" -lt "$max_attempts" ] && sleep "$interval"
done
[ "$total" -eq "$N" ] || fail "bounded MailHog poll exhausted (${max_attempts} x ${interval}s) without reaching total == ${N} (last seen: ${total})"

log "=== SCENARIO B complete: REST/health stayed green while notification-svc was down; ${N} messages buffered durably in '${QUEUE_NAME}' (publisher-confirms-acked, no competing consumer); all ${N} delivered to MailHog deterministically once the service restarted. ==="

log "=== ALL SCENARIOS PASSED ==="
exit 0
