Feature: Health check
  As a system operator
  I want to verify the service is healthy
  So that I can monitor its availability

  Scenario: Health endpoint returns ok
    Given I send a GET request to "/health"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON node "status" should be equal to "ok"
