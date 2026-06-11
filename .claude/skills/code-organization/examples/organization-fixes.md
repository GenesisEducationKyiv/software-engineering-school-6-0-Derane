# Code Organization Fix Examples

Real-world examples from PR code reviews showing how to apply code organization principles.

> **Core principle: "Directory X contains ONLY class type X"**

## PR Review Workflow for Organization Issues

When a reviewer comments on code organization:

1. **Identify the issue type** (wrong directory, vague naming, helper class, namespace mismatch)
2. **Consult the main SKILL.md** for complete rules and decision tree
3. **Apply the fix** using examples below
4. **Commit separately** with reference to the review comment
5. **Verify** with `make lint && make psalm && make deptrac && make test`

## Example 1: Class in Wrong Directory Type

### Review Comment

```text
RedisGitHubCache should be in Cache/ directory, not Persistence/.
Persistence is for PDO/Postgres repositories, not the Redis GitHub-API cache.
```

### Fix

```bash
# Move file
mv src/Releases/Sourcing/Infrastructure/Persistence/RedisGitHubCache.php \
   src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php

# Update namespace in moved file:
#   App\Releases\Sourcing\Infrastructure\Persistence  ->  App\Releases\Sourcing\Infrastructure\Cache
# Update all imports:
grep -r "use App\\\\Releases\\\\Sourcing\\\\Infrastructure\\\\Persistence\\\\RedisGitHubCache" src/ tests/ config/

# Verify
make lint && make psalm && make test
```

### Commit Message

```
Apply review suggestion: move RedisGitHubCache to Cache/ directory

RedisGitHubCache is a Redis-backed GitHub-API cache adapter, so it belongs in
Cache/ not Persistence/ (which holds PDO/Postgres repositories).

Ref: https://github.com/owner/repo/pull/XX#discussion_rYYYYYYY
```

## Example 2: Vague Variable Names

### Review Comment

```text
Variable name `$factory` is too vague. Factory of what?
Use specific names: `$releaseFactory` for the Release factory.
```

### Fix

```php
// Before: private ReleaseFactoryInterface $factory;
// After:  private ReleaseFactoryInterface $releaseFactory;

// Update all usages in the class methods (and the constructor-promoted param).
```

### Commit Message

```
Apply review suggestion: rename $factory to $releaseFactory

Makes variable name more specific per code-organization principles.

Ref: https://github.com/owner/repo/pull/XX#discussion_rYYYYYYY
```

## Example 3: Use-Case Class in Wrong Layer/Folder

### Review Comment

```text
FetchLatestReleaseHandler is a query handler — it belongs under
Application/FetchLatestRelease/ alongside its Query and Response, not in Infrastructure/.
```

### Fix

```bash
# Move file
mv src/Releases/Sourcing/Infrastructure/FetchLatestReleaseHandler.php \
   src/Releases/Sourcing/Application/FetchLatestRelease/FetchLatestReleaseHandler.php

# Update namespace and all imports (incl. the QueryBus wiring in config/container.php)
# Verify
make lint && make psalm && make deptrac && make test
```

## Example 4: Helper Class Code Smell

### Review Comment

```text
`GitHubHelper` is a code smell. Extract specific responsibilities:
- Repository-name validation → already covered by the RepositoryName VO (construction IS validation)
- Building a Release from a GitHub payload → ReleaseFactory
- Cache-key construction → move inside the relevant *Cache adapter
```

### Fix

1. Validate via the self-validating `RepositoryName` VO (`Shared/Domain/ValueObject/`) — do not add a standalone validator
2. Build `Release` via `ReleaseFactory` implementing `ReleaseFactoryInterface` (`Releases/Sourcing/Infrastructure/Factory/`)
3. Fold cache-key logic into the owning `*Cache` adapter (`Cache/`)
4. Update all usages to use the specific classes
5. Delete `GitHubHelper`
6. Run full test suite

### Commit Message

```
Apply review suggestion: dissolve GitHubHelper into specific classes

Extracted responsibilities per code-organization principles:
- validation via RepositoryName VO (no standalone validator — VOs self-validate)
- ReleaseFactory (Infrastructure/Factory/) builds Release from the GitHub payload
- cache-key logic folded into the *Cache adapter (Infrastructure/Cache/)

Deleted GitHubHelper.php.

Ref: https://github.com/owner/repo/pull/XX#discussion_rYYYYYYY
```

## Example 5: `from*` Static on an Anemic DTO

### Review Comment

```text
Release is an anemic readonly VO snapshot — it must not have a `Release::fromGitHubPayload()`
static method. Build it through ReleaseFactoryInterface instead.
(Self-validating VOs like EmailAddress MAY use fromString(); anemic DTO snapshots may not.)
```

### Fix

```php
// ❌ Before: static named constructor on the anemic DTO
$release = Release::fromGitHubPayload($payload);

// ✅ After: build through the injected factory
$release = $this->releaseFactory->fromGitHubPayload($payload);
```

Remove the static method from `Release`; move the mapping into `ReleaseFactory`
(`Releases/Sourcing/Infrastructure/Factory/`).

### Commit Message

```
Apply review suggestion: build Release via ReleaseFactory, drop from* static

Anemic DTO snapshots are constructed through a *FactoryInterface — no from* static
methods (that idiom is reserved for self-validating value objects).

Ref: https://github.com/owner/repo/pull/XX#discussion_rYYYYYYY
```

## Verification After Any Fix

```bash
# ALWAYS run after organization changes:
make lint     # PHPCS PSR-12 + deptrac
make psalm    # Catch namespace/import/type issues (errorLevel 1)
make deptrac  # Verify architecture compliance
make test     # Unit suite
make ci       # Full CI check before pushing → "✅ CI checks successfully passed!"
```

## Quick Reference

**For detailed rules, decision tree, and verification checklist:**
👉 See `SKILL.md` in this directory

**Common organization issues:**

- Class in wrong directory → Consult directory type table in SKILL.md
- Vague naming → Use specific names per verification checklist
- Helper/Util classes → Extract responsibilities per defined patterns
- `from*` on an anemic DTO → Build via `*FactoryInterface`
- Namespace mismatch → Must match directory structure exactly

**Related skills:**

- `deptrac-fixer` - Fix layer/context boundary violations
- `implementing-ddd-architecture` - DDD/CQRS naming and Clean Architecture structure
- `code-review` - Uses these examples during PR review workflow
