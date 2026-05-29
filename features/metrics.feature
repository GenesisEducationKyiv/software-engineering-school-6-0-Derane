Feature: Prometheus metrics
  As a system operator
  I want to access Prometheus metrics
  So that I can monitor the service

  Scenario: Metrics endpoint returns Prometheus format
    Given I send a GET request to "/metrics"
    Then the response status code should be equal to 200
    And the response header "Content-Type" should contain "text/plain"
    And the response body should contain "# HELP app_subscriptions_total"
    And the response body should contain "# TYPE app_subscriptions_total gauge"
    And the response body should contain "app_repositories_total"
    And the response body should contain "app_info"
