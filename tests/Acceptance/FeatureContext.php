<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\MinkExtension\Context\RawMinkContext;

class FeatureContext extends RawMinkContext implements Context
{
    private string $baseUrl;
    private ?int $lastSubscriptionId = null;
    private string $apiKey;

    public function __construct(string $baseUrl = 'http://localhost:8080')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = (string) ($_ENV['API_KEY'] ?? '');
    }

    /**
     * @BeforeScenario @cleanup
     */
    public function cleanupSubscriptions(BeforeScenarioScope $scope): void
    {
        $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);
        if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new \RuntimeException('Refusing destructive cleanup outside local test environment');
        }

        $headers = [];
        if ($this->apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        $response = @file_get_contents(
            $this->baseUrl . '/api/subscriptions',
            false,
            stream_context_create(['http' => ['header' => implode("\r\n", $headers)]])
        );
        if ($response === false) {
            return;
        }

        $subscriptions = json_decode($response, true);
        if (!is_array($subscriptions)) {
            return;
        }

        foreach ($subscriptions as $sub) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'DELETE',
                    'header' => implode("\r\n", $headers),
                ],
            ]);
            @file_get_contents($this->baseUrl . '/api/subscriptions/' . $sub['id'], false, $context);
        }
    }

    /**
     * @Then I remember the subscription id
     */
    public function iRememberTheSubscriptionId(): void
    {
        $content = $this->getSession()->getDriver()->getContent();
        $data = json_decode($content, true);

        if (isset($data['id'])) {
            $this->lastSubscriptionId = (int) $data['id'];
        }
    }

    /**
     * @Given I send a GET request to the last subscription
     */
    public function iSendAGetRequestToTheLastSubscription(): void
    {
        if ($this->lastSubscriptionId === null) {
            throw new \RuntimeException('No subscription id stored');
        }

        $this->getSession()->visit($this->baseUrl . '/api/subscriptions/' . $this->lastSubscriptionId);
    }

    /**
     * @Given I send a DELETE request to the last subscription
     */
    public function iSendADeleteRequestToTheLastSubscription(): void
    {
        if ($this->lastSubscriptionId === null) {
            throw new \RuntimeException('No subscription id stored');
        }

        /** @var \Behat\Mink\Driver\BrowserKitDriver $driver */
        $driver = $this->getSession()->getDriver();
        $client = $driver->getClient();
        $client->request('DELETE', $this->baseUrl . '/api/subscriptions/' . $this->lastSubscriptionId);
    }
}
