# Example — Adding a new external system

**Scenario:** Product wants Slack notifications in addition to email. The scanner now posts release notifications to a Slack incoming webhook via a new `SlackNotifierService`.

This change touches two C4 levels:
- A new **external system** (Slack) at landscape level.
- A new **component** (`SlackNotifierService`) inside the `scanner` container.

## Step 1: Add the external system in `landscape.c4`

```
slack = externalSystem "Slack" {
    description "Team chat. Receives release notifications via incoming webhook."
    style {
        icon https://cdn.jsdelivr.net/gh/devicons/devicon/icons/slack/slack-original.svg
    }
}

notifier -> slack "Posts release notifications" "HTTPS / Webhook"
```

## Step 2: Add the component in `components.c4`

```
extend notifier.scanner {
    // ...existing components...

    slackNotifierSvc = component "SlackNotifierService" {
        description "Posts release notifications to Slack incoming webhook."
        technology "PHP, Guzzle"
    }

    scannerSvc -> slackNotifierSvc "Dispatches Slack post on new release"
}
```

Add the cross-scope relationship to the external `slack` system at the bottom of the file:

```
notifier.scanner.slackNotifierSvc -> slack "POST /webhook" "HTTPS"
```

## Step 3: Update the Container view if Slack should appear there

Container view (`views.c4 → containers`) uses `include *`, which already pulls in any external system that has a relationship with the `notifier` system. So no change needed — Slack will appear automatically.

If you used a more restrictive `include`, add `slack` explicitly.

## Step 4: Update the Component view for Scanner

`views.c4 → scannerComponents` lists the externals to include. Add `slack`:

```
view scannerComponents of notifier.scanner {
    title "Component View — Scanner Worker"
    include
        *,
        notifier.db,
        notifier.redis,
        github,
        smtp,
        slack
}
```

## Step 5: Update the dynamic scan flow

In `views.c4 → scanFlow`, insert the Slack dispatch step after the email send (or in parallel — pick the actual order):

```
dynamic view scanFlow {
    // ...existing steps...
    notifier.scanner.scannerSvc -> notifier.scanner.notifierSvc "send(email, release)"
    notifier.scanner.notifierSvc -> smtp "Deliver email"

    notifier.scanner.scannerSvc -> notifier.scanner.slackNotifierSvc "post(release) if slack channel configured"
    notifier.scanner.slackNotifierSvc -> slack "POST /webhook"

    // ...remaining steps...
}
```

## Step 6: Validate and preview

```bash
make c4-validate
make c4-up    # confirm Slack shows up in landscape and scanner component views
```

## Diff summary

- `docs/architecture/landscape.c4` — +1 external system, +1 relationship
- `docs/architecture/components.c4` — +1 component, +2 relationships
- `docs/architecture/views.c4` — +1 line in `scannerComponents.include`, +2 lines in `scanFlow`
