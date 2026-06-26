# Troubleshooting Code Organization Issues

Common problems and solutions when organizing code.

## Problem 1: "I don't know where my class belongs"

### Symptoms

- Class has vague name like `Helper`, `Utils`, `Manager`, generic `Service`
- Unsure which directory to put it in
- Class seems to do multiple things

### Solution

1. **Identify primary responsibility**:

   ```text
   What is the ONE thing this class does?
   - Creates objects / maps payloads → Factory
   - Reads/writes Redis (GitHub-API cache) → Cache
   - Reads/writes Postgres via PDO → Persistence
   - Reacts to a domain event → Listener (PSR-14)
   - Serializes a wire message → Serialization
   - Etc.
   ```

2. **If it does multiple things, split it**:

   ```php
   // ❌ Bad: One class doing everything
   class GitHubHelper
   {
       public function validateRepositoryName() { }
       public function buildReleaseFromPayload() { }
       public function cacheKey() { }
   }

   // ✅ Good: Split into focused classes (and reuse the VO for validation)
   // - RepositoryName VO self-validates (Shared/Domain/ValueObject/)
   final readonly class ReleaseFactory implements ReleaseFactoryInterface { /* builds Release from payload */ }
   final readonly class GitHubReleaseCache implements LatestReleaseCacheInterface { /* owns its cache key */ }
   ```

3. **Use the decision tree** from [SKILL.md](../SKILL.md)

---

## Problem 2: "Namespace doesn't match directory"

### Symptoms

- Psalm errors about class not found
- IDE shows wrong namespace
- Class imports fail

### Example

```php
// ❌ File: src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
namespace App\Releases\Sourcing\Infrastructure\Persistence;  // WRONG!

final readonly class RedisGitHubCache implements GitHubCacheInterface { }
```

### Solution

Update namespace to match directory (PSR-4 root `App\` → `src/`):

```php
// ✅ File: src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
namespace App\Releases\Sourcing\Infrastructure\Cache;  // CORRECT!

final readonly class RedisGitHubCache implements GitHubCacheInterface { }
```

**Commands**:

```bash
# Check for mismatches
grep -r "^namespace" src/ --include="*.php" | grep -v "Tests"

# Verify
make lint
```

---

## Problem 3: "Class is in wrong directory"

### Symptoms

- Class name ends with "Cache" but is in `Persistence/` directory
- Code review feedback: "This belongs in X/"
- Directory name doesn't match class responsibility

### Example

```php
// ❌ File: src/Releases/Sourcing/Infrastructure/Persistence/RedisGitHubCache.php
final readonly class RedisGitHubCache implements GitHubCacheInterface { }  // It's a CACHE adapter, not Persistence!
```

### Solution

1. **Move the file**:

   ```bash
   mv src/Releases/Sourcing/Infrastructure/Persistence/RedisGitHubCache.php \
      src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
   ```

2. **Update namespace**:

   ```php
   // Change from:
   namespace App\Releases\Sourcing\Infrastructure\Persistence;

   // To:
   namespace App\Releases\Sourcing\Infrastructure\Cache;
   ```

3. **Find all usages**:

   ```bash
   grep -r "Infrastructure\\\\Persistence\\\\RedisGitHubCache" src/ tests/ config/
   ```

4. **Update imports** in all files (incl. `config/container.php`):

   ```php
   // Change from:
   use App\Releases\Sourcing\Infrastructure\Persistence\RedisGitHubCache;

   // To:
   use App\Releases\Sourcing\Infrastructure\Cache\RedisGitHubCache;
   ```

5. **Move test file**:

   ```bash
   mv tests/Unit/Releases/Sourcing/Infrastructure/Persistence/RedisGitHubCacheTest.php \
      tests/Unit/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCacheTest.php
   ```

6. **Update test namespace**:

   ```php
   namespace Tests\Unit\Releases\Sourcing\Infrastructure\Cache;
   ```

7. **Run quality checks**:
   ```bash
   make lint
   make psalm
   make test
   ```

---

## Problem 4: "Variable name is too vague"

### Symptoms

- Variables named `$factory`, `$cache`, `$data`
- Not clear what they build/cache/contain
- Code review feedback about naming

### Example

```php
// ❌ Vague
private ReleaseFactoryInterface $factory;      // Factory of what?
private GitHubCacheInterface $cache;           // Cache of what?
private array $data;                           // What data?
```

### Solution

Make names specific:

```php
// ✅ Specific
private ReleaseFactoryInterface $releaseFactory;
private GitHubCacheInterface $githubCache;
private array $payload;
```

**Search and replace**:

```bash
# Find vague names
grep -r "private.*\$factory;" src/
grep -r "private.*\$cache;" src/
grep -r "private.*\$data;" src/

# Use IDE refactoring to rename
```

---

## Problem 5: "Parameter name misleading"

### Symptoms

- Parameter named `$tagName` but accepts the whole payload `array`
- Parameter named `$string` but accepts `mixed`
- Type hint doesn't match parameter name

### Example

```php
// ❌ Misleading
public function fromGitHubPayload(array $tagName): Release  // Accepts the whole payload, not a tag!
{
    return new Release(
        (string) ($tagName['tag_name'] ?? null),
        // ... reads many keys, not just a tag
    );
}
```

### Solution

Name the parameter for what it actually is:

```php
// ✅ Accurate
public function fromGitHubPayload(array $payload): Release  // Accurate: the full GitHub release payload
{
    return new Release(
        (string) ($payload['tag_name'] ?? null),
        // ...
    );
}
```

---

## Problem 6: "Default instantiation in constructor"

### Symptoms

- Constructor has optional parameters with `null` default
- Default instantiation using `??` operator
- Hard to test with mocks
- Hidden dependencies

### Example

```php
// ❌ Default instantiation
public function __construct(
    ?ReleaseFactoryInterface $releaseFactory = null
) {
    $this->releaseFactory = $releaseFactory ?? new ReleaseFactory();
}
```

### Solution

Make dependencies required and inject them:

```php
// ✅ Required injection
public function __construct(
    private ReleaseFactoryInterface $releaseFactory
) {
}
```

**Wire in `config/container.php`** (bind interfaces only):

```php
GitHubReleaseCache::class => static fn($c) => new GitHubReleaseCache(
    $c->get(GitHubCacheInterface::class),
    $c->get(ReleaseFactoryInterface::class),
    $settings['redis']['cache_ttl'],
),
```

---

## Problem 7: "Static methods hard to test / `from*` on a DTO"

### Symptoms

- Methods defined as `static` on a class that has injected behavior
- A `from*` static named constructor on an anemic DTO snapshot
- Can't mock in tests; tight coupling
- Code review feedback about testability / the `from*` ban

### Example

```php
// ❌ from* static on an anemic DTO snapshot (forbidden)
final readonly class Release
{
    public static function fromGitHubPayload(array $payload): self
    {
        return new self(/* ... */);
    }
}
```

### Solution

Move the mapping into an injectable Factory; build through its interface:

```php
// ✅ Factory (instance method, injectable, mockable)
final readonly class ReleaseFactory implements ReleaseFactoryInterface
{
    #[\Override]
    public function fromGitHubPayload(array $payload): Release
    {
        return new Release(/* ... */);
    }
}
```

**Update usage**:

```php
// Before
$release = Release::fromGitHubPayload($payload);

// After (inject the factory in the constructor)
public function __construct(private ReleaseFactoryInterface $releaseFactory) {}
$release = $this->releaseFactory->fromGitHubPayload($payload);
```

> NOTE: This `from*` ban targets anemic DTO snapshots. **Self-validating value objects** (`EmailAddress`, `RepositoryName`, `ReleaseTag`) MAY keep `fromString()` named constructors — for them, construction IS the validation.

---

## Problem 8: "Not using constructor property promotion"

### Symptoms

- Properties declared separately
- Assignment in constructor body
- More boilerplate code
- Code review feedback

### Example

```php
// ❌ Old style
final class SubscribeCommandHandler
{
    private SubscriptionRepository $repository;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        SubscriptionRepository $repository,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->repository = $repository;
        $this->eventDispatcher = $eventDispatcher;
    }
}
```

### Solution

Use constructor property promotion + `final readonly`:

```php
// ✅ Modern style
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }
}
```

---

## Problem 9: "Tests failing after moving class"

### Symptoms

- Tests pass locally but fail in CI
- Class not found errors
- Namespace errors in tests

### Checklist

- [ ] Updated class file location
- [ ] Updated class namespace
- [ ] Updated all imports in src/
- [ ] Updated all imports in tests/
- [ ] Updated DI references in `config/container.php`
- [ ] Moved test file to match new location
- [ ] Updated test file namespace
- [ ] Ran `make lint`
- [ ] Ran `make psalm`
- [ ] Ran `make test`

### Common missed steps

1. **Test file not moved**:

   ```bash
   # Ensure test file mirrors source structure
   src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
   tests/Unit/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCacheTest.php
   ```

2. **Test namespace not updated**:

   ```php
   // Update from:
   namespace Tests\Unit\Releases\Sourcing\Infrastructure\Persistence;

   // To:
   namespace Tests\Unit\Releases\Sourcing\Infrastructure\Cache;
   ```

3. **Imports in test not updated**:

   ```php
   // Update from:
   use App\Releases\Sourcing\Infrastructure\Persistence\RedisGitHubCache;

   // To:
   use App\Releases\Sourcing\Infrastructure\Cache\RedisGitHubCache;
   ```

---

## Problem 10: "Bare array of domain objects instead of a typed collection"

### Symptoms

- Method passes/returns a bare `SubscriberRef[]` / `list<SubscriberRef>`
- Psalm flags untyped or weakly typed `array`
- A dedicated collection type already exists but isn't used

### Example

```php
// ❌ Bare array threaded through the publisher
public function findSubscribersByRepository(RepositoryName $repository): array
{
    // ...
    return $subscriberRefs;  // list<SubscriberRef>
}
```

### Solution

Use the dedicated typed collection:

```php
// ✅ Typed collection (IteratorAggregate<int, SubscriberRef> + Countable)
public function findSubscribersByRepository(RepositoryName $repository): SubscriberCollection
{
    // ...
    return new SubscriberCollection($subscriberRefs);
}
```

The collection encapsulates iteration/count and gives Psalm a single typed shape to reason about. Internal storage inside the collection class may still use `array`.

---

## Problem 11: "Circular dependencies"

### Symptoms

- Class A depends on B, B depends on A
- DI container errors
- Hard to test

### Example

```php
// ❌ Circular dependency
class A {
    public function __construct(private B $b) {}
}

class B {
    public function __construct(private A $a) {}
}
```

### Solution

1. **Extract interface / port** (matches our per-consumer ISP):

   ```php
   interface AInterface { }
   interface BInterface { }

   class A implements AInterface {
       public function __construct(private BInterface $b) {}
   }

   class B implements BInterface {
       public function __construct(private AInterface $a) {}
   }
   ```

2. **Use the PSR-14 event plane** instead of a direct dependency:

   ```php
   // Instead of a direct dependency, dispatch a domain event
   class A {
       public function __construct(private EventDispatcherInterface $eventDispatcher) {}

       public function doSomething(): void {
           $this->eventDispatcher->dispatch(new SubscriptionCreated(/* ... */));
       }
   }

   // A dedicated PSR-14 listener (When...Then...) reacts to the event
   ```

3. **Refactor responsibilities**:
   - Often circular dependencies indicate wrong responsibilities or a misplaced context boundary
   - Consider extracting shared logic into the Shared kernel or a third class

---

## Quick Diagnostic Commands

### Check namespace consistency

```bash
# Find files where the declared namespace doesn't match the directory path (App\ -> src/)
find src/ -name "*.php" -exec sh -c 'grep "^namespace" {} | grep -v "$(echo {} | sed "s|src/||" | sed "s|\.php||" | sed "s|/|\\\\|g" | sed "s|^|App\\\\|")"' \;
```

### Find vague class names

```bash
grep -r "class.*Helper" src/
grep -r "class.*Utils" src/
grep -r "class.*Manager" src/
```

### Find classes with default instantiation

```bash
grep -r "= new " src/ | grep "public function __construct"
grep -r "?? new" src/
```

### Find `from*` static methods on DTOs (excluding self-validating VOs)

```bash
# Anemic DTO snapshots must NOT have from* static methods (use a *FactoryInterface)
grep -rn "public static function from" src/ | grep -v "src/Shared/Domain/ValueObject/"
```

### Find missing #[\Override]

```bash
# Every interface-implementation method needs #[\Override] — psalm/phpcs catch most cases
grep -rL "#\[\\\\Override\]" src/ --include="*.php" | head
```

### Check test coverage for moved class

```bash
# Find test file for a class
CLASS="RedisGitHubCache"
find tests/ -name "*${CLASS}Test.php"
```

---

## Prevention Tips

### Before creating a new class

1. **Choose a specific name**: Not `Helper`, `Utils`, `Manager`, generic `Service`
2. **Identify ONE responsibility**: What does it do?
3. **Determine correct directory**: Use the decision tree in SKILL.md
4. **Ensure namespace will match**: Plan directory structure (`App\` → `src/`)
5. **Plan dependencies**: What will it need? Will they be injected? Add `#[\Override]` for interface methods.

### Before moving a class

1. **Backup or use git**: Easy to revert if needed
2. **Find all usages first**: `grep -r "ClassName" src/ tests/ config/`
3. **Update test file too**: Don't forget the test!
4. **Update `config/container.php`**: If the class was explicitly bound/aliased
5. **Run checks after each step**: lint, psalm, test
6. **Commit separately**: Makes it easy to track changes

### Code review checklist

- [ ] Class in correct directory for its type?
- [ ] Namespace matches directory (`App\` → `src/`)?
- [ ] Class name specific and clear?
- [ ] Variable names specific?
- [ ] No default instantiation in constructors?
- [ ] No `from*` static on anemic DTOs (use `*FactoryInterface`)?
- [ ] No inline `filter_var`/regex validation (use self-validating VOs)?
- [ ] Constructor property promotion + `final readonly` used?
- [ ] `#[\Override]` on every interface-implementation method?
- [ ] Typed collections instead of bare arrays of domain objects?
- [ ] Test file location matches source file?
- [ ] All quality checks pass (`make lint && make psalm && make deptrac && make test`)?
