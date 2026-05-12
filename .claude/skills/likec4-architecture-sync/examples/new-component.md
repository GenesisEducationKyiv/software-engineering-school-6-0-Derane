# Example — Adding a new HTTP component

**Scenario:** A new class `RateLimitMiddleware` is added in `src/Middleware/RateLimitMiddleware.php`. It sits in the HTTP request pipeline between `ApiKeyMiddleware` and the controllers, and reads/writes counters to Redis.

## Step 1: Identify the C4 level

It is a PHP class inside the `httpApi` container, so it belongs at **component level** in `components.c4`, under `extend notifier.httpApi { ... }`.

## Step 2: Edit `components.c4`

Add the new component declaration alongside the other middleware:

```
extend notifier.httpApi {
    apiKeyMw = component "ApiKeyMiddleware" { ... }
    errorMw  = component "ErrorHandlerMiddleware" { ... }

    rateLimitMw = component "RateLimitMiddleware" {
        description "Per-API-key request rate limiting; backed by Redis counters."
        technology "PHP, PSR-15 middleware"
    }

    // ...rest of the components...
}
```

## Step 3: Wire up its relationships

The middleware sits between `apiKeyMw` and the controllers, and talks to Redis. Add inside the same `extend` block (sibling references are allowed):

```
extend notifier.httpApi {
    // ...declarations above...

    apiKeyMw    -> rateLimitMw      "forwards authenticated request"
    rateLimitMw -> subscriptionCtrl "forwards rate-allowed request"
}
```

And the cross-scope relationship to Redis (write outside the `extend` block, with FQNs on both sides):

```
// near the bottom of components.c4
notifier.httpApi.rateLimitMw -> notifier.redis "reads/writes rate counters" "RESP"
```

## Step 4: Update the existing relationship from `apiKeyMw`

The old relationship `apiKeyMw -> subscriptionCtrl` is now wrong (rate limiter sits in between). Either:

- **Delete** the old `apiKeyMw -> subscriptionCtrl` line, or
- **Re-label** it if `apiKeyMw` still calls `subscriptionCtrl` directly for some endpoints (rare — usually delete).

## Step 5: Update dynamic view if affected

`views.c4` has `dynamic view createSubscriptionFlow`. Insert the rate-limit step:

```
dynamic view createSubscriptionFlow {
    user -> notifier.httpApi.apiKeyMw "POST /api/subscriptions with X-API-Key"
    notifier.httpApi.apiKeyMw -> notifier.httpApi.rateLimitMw "forward"
    notifier.httpApi.rateLimitMw -> notifier.redis "INCR counter"
    notifier.httpApi.rateLimitMw -> notifier.httpApi.subscriptionCtrl "forward (under limit)"
    // ...rest unchanged...
}
```

## Step 6: Validate

```bash
make c4-validate
```

Expected output: `✓ Valid (5 files)`. If any FQN error appears, see `reference/common-mistakes.md`.

## Step 7: Visually check (optional, recommended)

```bash
make c4-up
```

Open `http://localhost:5173` → switch to **Component View — HTTP API** and confirm `RateLimitMiddleware` shows up between auth and the controllers, with an arrow to Redis.

## Diff summary

You should have changed:

- `docs/architecture/components.c4` — added 1 component, added 2-3 relationships
- `docs/architecture/views.c4` — added 2 lines in `createSubscriptionFlow` dynamic view

Commit alongside the PHP code change.
