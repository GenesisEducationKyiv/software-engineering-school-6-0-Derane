# LikeC4 DSL Syntax Reference

Trimmed cheat-sheet of the LikeC4 DSL constructs actually used in this project. Full docs: <https://likec4.dev/dsl/>.

## File layout

A LikeC4 workspace is a set of `.c4` files in one directory. Each file may contain one or more top-level blocks:

```
specification { ... }   // element kinds, styles, relationship kinds
model { ... }           // elements and relationships
views { ... }           // diagrams generated from the model
```

In this project we split them across files for readability:

| File | Block |
|---|---|
| `specification.c4` | `specification { ... }` |
| `landscape.c4` | `model { ... }` (top-level elements) |
| `containers.c4` | `model { extend notifier { ... } }` |
| `components.c4` | `model { extend notifier.<container> { ... } }` |
| `views.c4` | `views { ... }` |

LikeC4 merges all files at parse time, so the order of files does not matter.

## Specification

Declares element kinds (custom for this project, since LikeC4 has no built-in C4 vocabulary):

```
specification {
    element actor {
        style {
            shape person
            color green
        }
    }
    element system { ... }
    element container { ... }
    element component { ... }
    element database {
        style {
            shape cylinder
            color amber
        }
    }
}
```

Allowed shapes include `person`, `rectangle`, `cylinder`, `browser`, `mobile`, `pipe`, `queue`, `storage`. Allowed colors: `primary`, `secondary`, `muted`, `red`, `green`, `blue`, `sky`, `amber`, `indigo`, `slate`.

## Model — declaring elements

```
model {
    user = actor "Subscriber" {
        description "Optional human-readable description."
    }

    notifier = system "GitHub Release Notifier" {
        description "..."
    }
}
```

Identifier on the left (`user`, `notifier`) is what you reference everywhere else. Title in quotes is what shows up on diagrams.

### Nested elements

```
extend notifier {
    httpApi = container "HTTP API" {
        description "..."
        technology "PHP, Slim 4"
    }
}
```

`extend X` re-opens an element so you can add children. Required when elements are split across files.

### Components inside containers

```
extend notifier.httpApi {
    subscriptionCtrl = component "SubscriptionController" {
        description "..."
        technology "PHP, Slim 4"
    }
}
```

The path `notifier.httpApi` is the FQN of the container.

## Model — relationships

```
source -> target "label" "technology / protocol"
```

The third quoted string is optional but useful for protocols (`HTTPS`, `SQL/TCP`, `RESP`, `SMTP`, `gRPC`).

### Scoping rules (the #1 source of validation errors)

- **Inside `extend X { ... }`**, sibling elements can be referenced by short identifier.
- **Outside that scope**, all references must be FQN.

Example — inside `extend notifier.httpApi`:
```
subscriptionCtrl -> subscriptionSvc "delegates"     // OK, both are siblings
subscriptionSvc -> notifier.redis "reads cache"     // FQN required: redis lives in notifier, not httpApi
```

Same relationship written outside the `extend` block:
```
notifier.httpApi.subscriptionCtrl -> notifier.httpApi.subscriptionSvc "delegates"
notifier.httpApi.subscriptionSvc -> notifier.redis "reads cache"
```

## Views

### Landscape view (top of system context)

```
view index {
    title "System Landscape"
    description "..."
    include *
}
```

`view index` is special — it is the entry point view shown by default.

### Element view (drill into one element)

```
view containers of notifier {
    title "Container View"
    include *
}

view httpApiComponents of notifier.httpApi {
    title "Component View — HTTP API"
    include
        *,
        user,
        notifier.db,
        notifier.redis,
        github
}
```

`include *` adds direct children of the target. Add additional FQNs to bring siblings/externals into the picture.

### Dynamic view (sequence of steps)

```
dynamic view createSubscriptionFlow {
    title "Dynamic — Create Subscription"
    description "..."

    user -> notifier.httpApi.apiKeyMw "POST /api/subscriptions"
    notifier.httpApi.apiKeyMw -> notifier.httpApi.subscriptionCtrl "Forward"
    notifier.httpApi.subscriptionCtrl -> notifier.httpApi.subscriptionSvc "create(...)"
    // each step renders as a numbered arrow
}
```

Dynamic-view steps **must reference elements by FQN** — they are not inside an `extend` scope.

## Common idioms

- **Multi-instance class**: when the same PHP class runs in two containers (e.g. `GitHubService` in `httpApi` and `scanner`), declare two distinct components with different identifiers. The model represents runtime instances.
- **Cross-container call** (e.g. gRPC reusing HTTP business logic): write the relationship outside any `extend` block, with FQNs on both sides.
- **External system call**: external systems are top-level (declared in `model { ... }` outside any `extend`); reference them by their short identifier (`github`, `smtp`).

## Validate / preview

```bash
make c4-validate   # parse + FQN check
make c4-up         # http://localhost:5173 live preview
make c4-down       # stop preview
```

For one-off image exports, use the **Export** button in the LikeC4 web UI.
