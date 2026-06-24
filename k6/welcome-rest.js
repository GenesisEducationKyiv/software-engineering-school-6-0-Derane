// k6 load test — REST baseline of the welcome-email send (Service B).
// Mirrors welcome-grpc.js so the two are apples-to-apples: identical payload, VUs,
// and duration, hitting the SAME SendWelcomeEmailHandler over HTTP/1.1 + JSON.
//
// Run (stack must be up; see Makefile `bench-rest`):
//   docker run --rm --network host -v "$PWD:/work" -w /work grafana/k6 run \
//     -e VUS=50 -e DURATION=30s k6/welcome-rest.js
//
// A fixed subscription_id means the first request sends a real email and every
// subsequent one hits the ledger's AlreadySent dedup branch — so per-request work
// is constant and the measured latency isolates the transport, not mail delivery.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.REST_BASE_URL || 'http://localhost:8081';

export const options = {
  vus: Number(__ENV.VUS || 50),
  duration: __ENV.DURATION || '30s',
  // p50/p95/p99 are reported automatically; this just flags a regression.
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(99)<2000'],
  },
};

// The REST endpoint's JSON contract uses camelCase keys (WelcomeEmailFactory::fromArray).
const payload = JSON.stringify({
  sagaId: '00000000-0000-0000-0000-00000000bef0',
  subscriptionId: Number(__ENV.SUBSCRIPTION_ID || 4242421),
  email: 'bench@example.com',
  repository: 'octocat/hello-world',
});

export default function () {
  const res = http.post(`${BASE}/internal/welcome-emails`, payload, {
    headers: { 'Content-Type': 'application/json' },
  });
  // Strict, symmetric with welcome-grpc.js's `outcome === 'OUTCOME_SENT'`: parse the JSON
  // and require outcome:sent, so identical per-request work is certified on both transports
  // and a regression to outcome:failed fails the check instead of passing on a substring.
  check(res, {
    'http 200': (r) => r.status === 200,
    'outcome sent': (r) => {
      try {
        return JSON.parse(r.body).outcome === 'sent';
      } catch (_e) {
        return false;
      }
    },
  });
}
