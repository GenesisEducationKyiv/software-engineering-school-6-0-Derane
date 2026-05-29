@auth
Feature: API key authentication
  In order to protect subscription data
  As an operator
  I want the API to require a valid X-API-Key header when API_KEY is set

  Scenario: Request without an API key is rejected
    When I send a GET request to "/api/subscriptions"
    Then the response status code should be equal to 401
    And the response should be in JSON
    And the JSON node "error" should contain "API key"

  Scenario: Request with a wrong API key is rejected
    Given I add "X-API-Key" header equal to "totally-wrong-key"
    And I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "auth@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 403
    And the response should be in JSON
    And the JSON node "error" should contain "Invalid API key"

  @cleanup
  Scenario: Request with the correct API key completes the CRUD round-trip
    Given I add "X-API-Key" header equal to "behat-secret"
    And I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "auth@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And I remember the subscription id
    Given I add "X-API-Key" header equal to "behat-secret"
    When I send a GET request to "/api/subscriptions?email=auth@example.com"
    Then the response status code should be equal to 200
    And the JSON should have 1 element
    When I send a DELETE request to the last subscription
    Then the response status code should be equal to 204

  Scenario: Health endpoint stays public
    When I send a GET request to "/health"
    Then the response status code should be equal to 200
