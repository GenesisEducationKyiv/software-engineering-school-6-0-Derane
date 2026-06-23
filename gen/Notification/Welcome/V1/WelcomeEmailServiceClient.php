<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Notification\Welcome\V1;

/**
 * The single synchronous inter-service RPC. Service A (monolith saga relay) calls
 * Service B (notification) and BLOCKS for the sent|failed outcome — the deliberate
 * caller-waits semantic shift on the opt-in transports (PRD DC1). The async rabbit
 * path remains the default and is unaffected by this contract.
 */
class WelcomeEmailServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Render and send the welcome email for a confirmed-pending subscription, then
     * return whether it was sent or failed. Business sent|failed is a normal OK
     * response field; infrastructure/validation errors surface as gRPC STATUS codes
     * (PRD FR6 table), never in-band.
     * @param \Notification\Welcome\V1\SendWelcomeEmailRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SendWelcomeEmail(\Notification\Welcome\V1\SendWelcomeEmailRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/notification.welcome.v1.WelcomeEmailService/SendWelcomeEmail',
        $argument,
        ['\Notification\Welcome\V1\SendWelcomeEmailResponse', 'decode'],
        $metadata, $options);
    }

}
