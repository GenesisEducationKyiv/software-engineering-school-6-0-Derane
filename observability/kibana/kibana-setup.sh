#!/bin/sh
# Provisions the Kibana data view for the app's structured logs so it exists on the
# first boot of `make obs-up`, instead of being created by hand. Runs as a one-shot
# service (`kibana-setup`) in docker-compose.observability.yml. Idempotent: a data
# view that already exists is treated as success, so re-running obs-up is safe.
set -eu

KIBANA_URL="${KIBANA_URL:-http://kibana:5601}"
DATA_VIEW_TITLE="${DATA_VIEW_TITLE:-release-notifier-logs-*}"
DATA_VIEW_NAME="${DATA_VIEW_NAME:-Release Notifier Logs}"

echo "kibana-setup: waiting for Kibana at ${KIBANA_URL} ..."
until curl -sf -o /dev/null "${KIBANA_URL}/api/status"; do
  sleep 5
done
echo "kibana-setup: Kibana is up."

# Idempotent: if a data view with this title already exists, do nothing. Kibana
# allows multiple data views with the same title, so without this check a repeated
# `make obs-up` would keep adding duplicates.
existing="$(curl -s "${KIBANA_URL}/api/data_views" -H 'kbn-xsrf: true' || true)"
if printf '%s' "${existing}" | grep -qF "\"title\":\"${DATA_VIEW_TITLE}\""; then
  echo "kibana-setup: data view '${DATA_VIEW_TITLE}' already exists; nothing to do."
  exit 0
fi

# allowNoIndex lets the data view be created before any log index exists yet
# (Filebeat creates release-notifier-logs-* once the first line ships).
body="{\"data_view\":{\"title\":\"${DATA_VIEW_TITLE}\",\"name\":\"${DATA_VIEW_NAME}\",\"timeFieldName\":\"@timestamp\",\"allowNoIndex\":true}}"

i=1
while [ "$i" -le 10 ]; do
  # /api/status can report ready slightly before the saved-objects API serves writes.
  response="$(curl -s -w '\n%{http_code}' -X POST "${KIBANA_URL}/api/data_views/data_view" \
    -H 'kbn-xsrf: true' \
    -H 'Content-Type: application/json' \
    -d "${body}" || true)"
  code="$(printf '%s' "${response}" | tail -n1)"
  payload="$(printf '%s' "${response}" | sed '$d')"

  case "${code}" in
    200 | 201)
      echo "kibana-setup: data view '${DATA_VIEW_TITLE}' created."
      exit 0
      ;;
    *)
      # Idempotent re-run: Kibana rejects a duplicate title — that means it is done.
      if printf '%s' "${payload}" | grep -qi 'duplicate data view\|already exists'; then
        echo "kibana-setup: data view '${DATA_VIEW_TITLE}' already exists; nothing to do."
        exit 0
      fi
      echo "kibana-setup: attempt ${i}/10 failed (HTTP ${code}); retrying in 5s ..."
      [ -n "${payload}" ] && echo "${payload}"
      sleep 5
      ;;
  esac
  i=$((i + 1))
done

echo "kibana-setup: gave up creating the data view after 10 attempts." >&2
exit 1
