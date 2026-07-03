// k6 load test — REST baseline of the welcome-email send (Service B), over HTTP/1.1 + JSON.
// A fixed subscription_id means only the first request sends a real email; the rest hit the
// ledger's AlreadySent dedup branch, so per-request work is constant and latency isolates the
// transport, not mail delivery.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.REST_BASE_URL || 'http://localhost:8081';

export const options = {
  vus: Number(__ENV.VUS || 50),
  duration: __ENV.DURATION || '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(99)<2000'],
  },
};

// The REST endpoint's JSON contract uses camelCase keys.
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
  // The endpoint returns HTTP 200 even for outcome:failed, so assert the body, not just the status.
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
