# Common Mistakes & Fixes

These are the LikeC4 errors most likely to bite you, with the exact fix.

## 1. `FqnRef "X" is empty`

**Symptom:**
```
WARN likec4.server.ModelParser  Error on 'Relation': FqnRef "redis" is empty
  at components.c4:102:18
```

**Cause:** A relationship inside `extend notifier.httpApi { ... }` references an element (`redis`) that lives outside that scope (it is `notifier.redis`, not `notifier.httpApi.redis`).

**Fix:** Either use the FQN, or move the relationship outside the `extend` block.

```
// inside extend notifier.httpApi { ... }
githubSvc -> redis "reads cache"            // BROKEN
githubSvc -> notifier.redis "reads cache"   // FIX A: FQN
```

```
// outside the extend block
notifier.httpApi.githubSvc -> notifier.redis "reads cache"   // FIX B: move out, use full FQNs on both sides
```

## 2. `Invalid reference to source` in dynamic views

**Symptom:**
```
WARN likec4.server.ViewsParser  Invalid reference to source
  at views.c4 (dynamic view block)
```

**Cause:** Dynamic view steps are NOT inside any `extend` scope, so they need FQNs on both sides of every `->`.

**Fix:**
```
// BROKEN
dynamic view foo {
    user -> apiKeyMw "POST /..."
}

// CORRECT
dynamic view foo {
    user -> notifier.httpApi.apiKeyMw "POST /..."
}
```

## 3. Duplicate identifiers across containers

**Symptom:** Validation error about duplicate identifier or name collision.

**Cause:** Same identifier (`githubSvc`) declared in two `extend` blocks.

**Fix:** Use distinct identifiers per container — these are different runtime instances. We use:
- `githubSvc` (in `notifier.httpApi`)
- `scannerGithub` (in `notifier.scanner`)

The titles on the diagram can still be the same (`"GitHubService"`).

## 4. Forgetting to declare an element kind

**Symptom:**
```
Error: Unknown element kind 'queue'
```

**Cause:** Used `something = queue "Foo"` but `queue` was never declared in `specification.c4`.

**Fix:** Add to `specification.c4`:
```
specification {
    element queue {
        style {
            shape queue
            color indigo
        }
    }
}
```

## 5. Using snake_case or kebab-case identifiers

LikeC4 identifiers must be valid camelCase or PascalCase. `api_key_mw` and `api-key-mw` will not parse. Use `apiKeyMw`.

## 6. Missing comma in `include` list

```
view foo of bar {
    include
        *
        user           // BROKEN — comma missing on previous line
        notifier.db
}
```

**Fix:** All items in `include` must be comma-separated:
```
include
    *,
    user,
    notifier.db
```

## 7. Manually crafted `autolayout` directives

LikeC4 auto-layouts diagrams via Graphviz. Do not write explicit position hints — they are not part of the DSL. If a diagram is hard to read, use `view` filters (`include`, `exclude`) to reduce density rather than fighting the layout engine.

## 8. Stale references after a rename

When renaming an identifier, you must update **every** reference, including in `views.c4`. `make c4-validate` will tell you which lines still point at the old name.

## 9. Description strings span multiple lines without quotes

Multi-line descriptions need triple-quoted-style block (single string spanning lines is allowed only inside `"..."`):

```
description "
    Multi-line text is fine
    inside a single pair of quotes.
"
```

Do not break out of the quotes mid-paragraph.

## 10. Editing the model without running validate

The fastest dev loop is:
```bash
make c4-validate    # < 2s
```
Run this before every commit. Broken models break the live preview and any future build.
