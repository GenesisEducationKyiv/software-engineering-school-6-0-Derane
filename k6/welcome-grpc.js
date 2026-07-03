// k6 load test — gRPC path of the welcome-email send (Service B), over HTTP/2 + protobuf.
// Uses a fixed subscription_id (distinct from the REST run's) so per-request work is the
// constant AlreadySent dedup branch after the first send — isolating transport.
import grpc from 'k6/net/grpc';
import { check } from 'k6';

const TARGET = __ENV.GRPC_TARGET || 'localhost:9002';

// Import root is the repo's proto/ dir (this script lives in k6/, so one level up).
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
  // One HTTP/2 connection per VU, reused across iterations.
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
