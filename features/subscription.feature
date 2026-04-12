Feature: Subscription management
  As a user
  I want to subscribe to GitHub repository release notifications
  So that I get notified about new releases

  @cleanup
  Scenario: Subscribe to a valid repository
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "behat@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And the response should be in JSON
    And the JSON node "id" should exist
    And the JSON node "email" should be equal to "behat@example.com"
    And the JSON node "repository" should be equal to "docker/compose"
    And the JSON node "created_at" should exist

  @cleanup
  Scenario: Subscribe with invalid email returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "not-an-email", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Subscribe with invalid repository format returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "behat@example.com", "repository": "invalid-repo"}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Subscribe to non-existent repository returns 404
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "behat@example.com", "repository": "nonexistent999/repo999"}
    """
    Then the response status code should be equal to 404
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Subscribe with missing fields returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "behat@example.com"}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  @cleanup
  Scenario: List subscriptions
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "list-test@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    When I send a GET request to "/api/subscriptions"
    Then the response status code should be equal to 200
    And the response should be in JSON

  @cleanup
  Scenario: Filter subscriptions by email
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "filter-test@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    When I send a GET request to "/api/subscriptions?email=filter-test@example.com"
    Then the response status code should be equal to 200
    And the response should be in JSON

  @cleanup
  Scenario: Get subscription by ID
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "get-test@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And I remember the subscription id
    When I send a GET request to the last subscription
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON node "email" should be equal to "get-test@example.com"

  Scenario: Get non-existent subscription returns 404
    Given I send a GET request to "/api/subscriptions/999999"
    Then the response status code should be equal to 404
    And the response should be in JSON
    And the JSON node "error" should exist

  @cleanup
  Scenario: Delete subscription
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "delete-test@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And I remember the subscription id
    When I send a DELETE request to the last subscription
    Then the response status code should be equal to 204

  Scenario: Delete non-existent subscription returns 404
    Given I send a DELETE request to "/api/subscriptions/999999"
    Then the response status code should be equal to 404
    And the response should be in JSON
    And the JSON node "error" should exist
