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
  Scenario: List subscriptions when none exist returns empty array
    When I send a GET request to "/api/subscriptions"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON should have 0 elements

  @cleanup
  Scenario: List subscriptions returns the created subscription
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "list-test@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    When I send a GET request to "/api/subscriptions"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON should have 1 element
    And the JSON node "[0].email" should be equal to "list-test@example.com"
    And the JSON node "[0].repository" should be equal to "docker/compose"

  @cleanup
  Scenario: Filter subscriptions by email returns only matching rows
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "filter-test@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "other-user@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    When I send a GET request to "/api/subscriptions?email=filter-test@example.com"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON should have 1 element
    And the JSON node "[0].email" should be equal to "filter-test@example.com"

  @cleanup
  Scenario: List subscriptions respects limit and offset
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "page1@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "page2@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    When I send a GET request to "/api/subscriptions?limit=1&offset=0"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON should have 1 element
    When I send a GET request to "/api/subscriptions?limit=1&offset=1"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON should have 1 element

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

  @cleanup
  Scenario: Duplicate subscription is idempotent
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "dup@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And I remember the subscription id
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "dup@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And the JSON node "id" should be equal to the remembered subscription id
    When I send a GET request to "/api/subscriptions?email=dup@example.com"
    Then the response status code should be equal to 200
    And the JSON should have 1 element

  Scenario: Subscribe with only repository field returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"repository": "docker/compose"}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Subscribe with empty repository value returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "blank@example.com", "repository": ""}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Subscribe with whitespace-only email returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "   ", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Subscribe with empty JSON object returns 400
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {}
    """
    Then the response status code should be equal to 400
    And the response should be in JSON
    And the JSON node "error" should exist

  @cleanup
  Scenario: Subscribe ignores unknown fields
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "extra@example.com", "repository": "docker/compose", "stranger": "ignored"}
    """
    Then the response status code should be equal to 201
    And the JSON node "email" should be equal to "extra@example.com"
    And the JSON node "repository" should be equal to "docker/compose"

  @cleanup
  Scenario: Filter subscriptions by unknown email returns empty array
    When I send a GET request to "/api/subscriptions?email=nobody@example.com"
    Then the response status code should be equal to 200
    And the response should be in JSON
    And the JSON should have 0 elements

  @cleanup
  Scenario: Deleted subscription cannot be fetched
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "gone@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And I remember the subscription id
    When I send a DELETE request to the last subscription
    Then the response status code should be equal to 204
    When I send a GET request to the last subscription
    Then the response status code should be equal to 404
    And the JSON node "error" should exist

  @cleanup
  Scenario: Deleting a subscription twice returns 404 on the second attempt
    Given I add "Content-Type" header equal to "application/json"
    And I send a POST request to "/api/subscriptions" with body:
    """
    {"email": "twice@example.com", "repository": "docker/compose"}
    """
    Then the response status code should be equal to 201
    And I remember the subscription id
    When I send a DELETE request to the last subscription
    Then the response status code should be equal to 204
    When I send a DELETE request to the last subscription
    Then the response status code should be equal to 404
    And the JSON node "error" should exist

  Scenario: Get with non-numeric id returns 404
    Given I send a GET request to "/api/subscriptions/abc"
    Then the response status code should be equal to 404
    And the response should be in JSON
    And the JSON node "error" should exist

  Scenario: Delete with non-numeric id returns 404
    Given I send a DELETE request to "/api/subscriptions/abc"
    Then the response status code should be equal to 404
    And the response should be in JSON
    And the JSON node "error" should exist
