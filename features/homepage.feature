Feature: Homepage
  As a user
  I want to open the subscription page in the browser
  So that I can manage my subscriptions through the UI

  Scenario: Homepage returns HTML
    When I send a GET request to "/"
    Then the response status code should be equal to 200
    And the response header "Content-Type" should contain "text/html"
    And the response body should contain "<title>GitHub Release Notifier</title>"
    And the response body should contain "subscribeForm"
