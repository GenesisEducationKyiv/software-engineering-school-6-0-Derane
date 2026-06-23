// k6 load test — gRPC path of the welcome-email send (Service B).
// Mirrors welcome-rest.js exactly (same payload, VUs, duration), hitting the SAME
// SendWelcomeEmailHandler over HTTP/2 + protobuf via the welcome.proto contract.
//
// Run (stack must be up; see Makefile `bench-grpc`):
//   docker run --rm --network host -v "$PWD:/work" -w /work grafana/k6 run \
//     -e VUS=50 -e DURATION=30s k6/welcome-grpc.js
//
// Uses a fixed subscription_id (distinct from the REST run's) so per-request work is
// the constant AlreadySent dedup branch after the first send — isolating transport.
import grpc from 'k6/net/grpc';
import { check } from 'k6';

const TARGET = __ENV.GRPC_TARGET || 'localhost:9002';

// welcome.proto has no imports; import root is the repo's proto/ dir (this script
// lives in k6/, so the path is one level up).
const client = new grpc.Client();
client.load(['../proto'], 'notification/welcome/v1/welcome.proto');

export const options = {
  vus: Number(__ENV.VUS || 50),
  duration: __ENV.DURATION || '30s',
  thresholds: {
    grpc_req_duration: ['p(99)<2000'],
  },
};

const request = {
  saga_id: '00000000-0000-0000-0000-00000000bef1',
  subscription_id: Number(__ENV.SUBSCRIPTION_ID || 4242422),
  email: 'bench@example.com',
  repository: 'octocat/hello-world',
};

export default function () {
  // One HTTP/2 connection per VU, reused across iterations (the whole point of gRPC).
  if (__ITER === 0) {
    client.connect(TARGET, { plaintext: true });
  }

  const res = client.invoke(
    'notification.welcome.v1.WelcomeEmailService/SendWelcomeEmail',
    request,
  );

  check(res, {
    'grpc OK': (r) => r && r.status === grpc.StatusOK,
    'outcome SENT': (r) => r && r.message && r.message.outcome === 'OUTCOME_SENT',
  });
}
