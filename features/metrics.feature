Feature: Prometheus metrics
  As a system operator
  I want to access Prometheus metrics
  So that I can monitor the service

  Scenario: Metrics endpoint returns Prometheus format
    Given I send a GET request to "/metrics"
    Then the response status code should be equal to 200
