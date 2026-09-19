# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/graphql

## Source structure

```
src/
├── GraphQL.php                     — static facade: execute(), setExecutor(), resetExecutor()
├── GraphQLController.php           — invokable HTTP handler for POST /graphql
├── GraphQLExecutor.php             — wraps webonyx execution; handles debug mode and PHP-level exceptions
├── GraphQLServiceProvider.php      — binds executor, registers POST /graphql route
├── SchemaBuilder.php               — fluent builder for webonyx Schema (query + mutation)
├── SchemaException.php             — thrown when build() is called without query fields
└── DataLoaderRegistry.php          — per-request keyed registry of ez-php/dataloader DataLoader instances

tests/
├── TestCase.php                    — abstract base; makeHelloSchema() factory for a reusable test schema
├── DataLoaderRegistryTest.php      — registry tests: get/has/dispatchAll/clear, shared-loader identity across "resolvers"
├── GraphQLTest.php                 — facade tests: delegation, fail-fast, reset
├── GraphQLExecutorTest.php         — execution tests: valid queries, variables, errors, debug mode, resolver exception
├── GraphQLControllerTest.php       — HTTP layer tests: 200/400 status, variables, operationName, content-type
└── SchemaBuilderTest.php           — builder tests: query-only, query+mutation, immutability, missing query guard
```

---

## Key classes and responsibilities

### SchemaBuilder (`src/SchemaBuilder.php`)

Fluent, immutable builder for webonyx `Schema`. Clone-based withers (`query()`, `mutation()`) ensure that sharing a base builder does not cause cross-test contamination.

`build()` wraps the provided field maps in `ObjectType('Query', ...)` and `ObjectType('Mutation', ...)`, then constructs a `SchemaConfig`-based `Schema`. Only the `query` type is mandatory — calling `build()` without `query()` throws `SchemaException`.

For advanced schemas (interfaces, unions, enums, custom scalars, directives) users must construct the webonyx `Schema` directly and bind it in their own service provider. `SchemaBuilder` targets the common single-object-type case only.

---

### GraphQLExecutor (`src/GraphQLExecutor.php`)

Thin wrapper around `webonyx\GraphQL::executeQuery`. Two responsibilities:

1. **Delegate** query execution to webonyx, passing variables only when non-empty (webonyx expects `null`, not `[]`, to indicate "no variables").
2. **Contain PHP exceptions** — if a resolver throws an uncaught PHP exception, it is caught and returned as a single `errors` entry rather than propagating up the call stack.

Debug mode (`$debug = true`) passes `DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE` to `toArray()` and exposes the original exception message. Production mode (`$debug = false`) passes `DebugFlag::NONE` and returns a generic "Internal server error" message.

GraphQL-level errors (invalid field names, failed type coercions) are handled natively by webonyx — they appear in `$result->errors` and are never rethrown.

`execute()`'s optional `mixed $context = null` parameter (added alongside `DataLoaderRegistry`) is passed straight through as webonyx's per-request `$contextValue`, reaching every resolver as their third argument — e.g. a `DataLoaderRegistry` constructed fresh per request. `GraphQLExecutor` itself has no opinion on what `$context` is; it is purely a passthrough.

---

### GraphQLController (`src/GraphQLController.php`)

Invokable controller (`__invoke(Request): Response`). Extracts `query`, `variables`, and `operationName` from the parsed request body via `$request->all()`. Non-string query values and non-array variable values are silently coerced to their defaults (`null`/`[]`) rather than rejected — this matches common client behaviour where omitted optional fields may arrive as `null` or a wrong type.

Returns HTTP 400 only when `query` is missing or empty. All other responses (including GraphQL errors) use HTTP 200, per the [GraphQL over HTTP spec](https://graphql.github.io/graphql-over-http/rfcs/GraphQLOverHTTP.html).

---

### GraphQL (`src/GraphQL.php`)

Static facade following the `Health`/`Flag`/`Metrics` pattern. Holds `private static ?GraphQLExecutor $executor`. Initialised by `GraphQLServiceProvider::boot()`. Throws `RuntimeException` (fail-fast) when called before initialisation. `resetExecutor()` clears the singleton for test `tearDown`.

---

### DataLoaderRegistry (`src/DataLoaderRegistry.php`)

Per-request keyed registry of `EzPhp\DataLoader\DataLoader` instances. `get(key, batchLoadFn)` creates a loader on first call for a key and returns the same instance on every later call for that key (ignoring the batch function passed on later calls), so multiple resolvers batching the same kind of data (e.g. `post.author` and `comment.author` both loading `User` rows) share one `DataLoader` and one batch call instead of each maintaining its own. `has()`, `dispatchAll()` (dispatches every registered loader in one call), and `clear()` round out the API. See Design Decisions for why this lives here and not in `ez-php/dataloader`.

---

### GraphQLServiceProvider (`src/GraphQLServiceProvider.php`)

`register()` binds `GraphQLExecutor` lazily — requires `GraphQL\Type\Schema` to already be bound (fail-fast if not). The `Schema` binding is the user's responsibility and must be registered in a provider that runs before `GraphQLServiceProvider`.

`boot()` initialises the static facade and registers `POST /graphql` (or the configured endpoint). Route registration is guarded by `$this->app->has(Router::class)` for CLI safety, rather than a `try/catch` probe. The endpoint URI is read from `graphql.endpoint` config with `/graphql` as the default.

---

## Design decisions and constraints

- **webonyx/graphql-php as the engine.** Implementing a GraphQL lexer, parser, type system, and executor from scratch would be a significant independent project with no framework value. webonyx is the de-facto standard PHP GraphQL library (15 million downloads/month), actively maintained, and fully typed.
- **Schema is user-defined.** The module deliberately does not ship a default schema. The `Schema` binding is the application's responsibility — `GraphQLServiceProvider` fails fast if it is missing. This is the correct design: a GraphQL API without a schema is meaningless, and there is no safe default.
- **No automatic type discovery or code generation.** Annotation-based or reflection-based schema generation adds magic that is hard to trace. Users define their types explicitly using webonyx's native API or `SchemaBuilder`. This keeps the module explicit and dependency-free of reflection libraries.
- **HTTP 200 for GraphQL errors.** The GraphQL over HTTP spec states that partial success responses (data + errors) and full error responses (no data) should use HTTP 200. Only a missing/empty query field (a protocol error, not a GraphQL error) returns HTTP 400.
- **`SchemaBuilder` covers simple schemas only.** The fluent builder wraps webonyx's `ObjectType` and `Schema` for the single-query-root, single-mutation-root case. Advanced schemas (multiple types, interfaces, unions) use webonyx directly. This is an explicit scope limit — adding full schema DSL functionality would duplicate webonyx.
- **Variables passed as `null` when empty.** webonyx treats `null` as "no variables provided" and `[]` as "empty variables object". Passing `null` for empty variables produces correct behaviour with all webonyx validators.
- **`ez-php/framework` required for route registration.** The Router lives in `ez-php/framework`. Guarding registration with `$this->app->has(Router::class)` ensures the module can be used in contexts where only contracts + http are present (e.g. custom dispatchers), but the route simply won't be registered.
- **`DataLoaderRegistry` lives here, not in `ez-php/dataloader`, per that module's own documented boundary.** `ez-php/dataloader`'s `CLAUDE.md` explicitly excludes "GraphQL-specific resolver wiring (e.g. a `DataLoaderRegistry` keyed by GraphQL field, request-scoped loader lifecycle tied to `GraphQLExecutor`)" and points to `ez-php/graphql`. `ez-php/dataloader` is a hard `require` dependency (not soft/`require-dev`) — unlike the soft-dependency bridges elsewhere in this monorepo, `DataLoaderRegistry` is a first-class, always-available part of this module's resolver-wiring surface, and `ez-php/graphql` is already not a zero-dependency module (`webonyx/graphql-php`, `ez-php/framework`).
- **`DataLoaderRegistry` is not container-managed and not wired into `GraphQLServiceProvider`.** It must be constructed fresh per request and discarded afterward — a shared long-lived instance (e.g. a container singleton) would leak cached values and pending batch keys across unrelated requests. Applications construct one per request and pass it as `GraphQLExecutor::execute()`'s new optional `$context` parameter, which reaches every resolver via webonyx's per-request context — `GraphQLExecutor` stays a thin, opinion-free passthrough for it (see its own section above), matching this module's existing "schema is user-defined, executor stays thin" design.
- **The N+1-solving primitive itself still does not belong here.** The "What Does NOT Belong" DataLoader entry below is about `DataLoader`'s batching/deferred-resolution *algorithm*, which stays in `ez-php/dataloader` — `DataLoaderRegistry` only composes it, it does not reimplement or fork it.

---

## Testing approach

No external infrastructure required — all tests run in-process.

- `TestCase` provides `makeHelloSchema()`, a reusable webonyx schema with `hello` (no args, returns `'world'`) and `greet` (requires `$name`, returns `"Hello, $name!"`).
- `SchemaBuilderTest` — builds schemas, validates mutation presence/absence, tests immutability of builder clones, asserts exception when query is missing.
- `GraphQLExecutorTest` — covers happy path, variables, operation name selection, invalid field errors, syntax errors, debug vs production error format, resolver exception containment.
- `GraphQLControllerTest` — constructs `Request` objects directly (`new Request('POST', '/graphql', body: [...])`), asserts HTTP status, response body, content-type header, and correct delegation of variables/operationName.
- `GraphQLTest` — facade delegation, fail-fast on uninitialised access, `resetExecutor()` for test isolation.
- `DataLoaderRegistryTest` — `get()` creates on first call and returns the same instance for a repeat key (simulating two resolvers sharing one loader), the batch function on a repeat call is ignored, different keys produce different loaders, `has()`/`clear()`, and `dispatchAll()` dispatching two independently-pending loaders in one call.

All test classes declare `#[CoversClass]` and `#[UsesClass]` attributes for strict coverage metadata.

---

## What does not belong in this module

- **Schema definition DSL / annotations** — use webonyx's API directly; annotation magic is not in scope.
- **GraphQL subscriptions** — subscriptions require a persistent connection layer (WebSocket/SSE); use `ez-php/websocket` and `ez-php/broadcast` for real-time features.
- **Persisted queries** — query ID → document mapping belongs in application middleware, not this module.
- **Authentication / authorisation guards on resolvers** — use context injection via webonyx's context parameter and application-level auth logic.
- **Rate limiting on the GraphQL endpoint** — apply `ez-php/rate-limiter`'s `ThrottleMiddleware` to the `/graphql` route.
- **N+1 batching/deferred-resolution algorithm itself** — that's `ez-php/dataloader`'s `DataLoader`; this module's `DataLoaderRegistry` only composes it (see Design Decisions).
- **Schema introspection disabling** — disable via webonyx's `Schema::$assumeValid` or a custom validation rule at the application level.
- **Multi-schema / schema stitching** — bind a combined `Schema` in the application service provider; the module always uses whichever `Schema` is bound.
