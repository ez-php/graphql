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
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
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

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^1.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| `ez-php/queue` | 3310 | 6381 |
| `ez-php/rate-limiter` | — | 6382 |
| **next free** | **3311** | **6383** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

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
└── SchemaException.php             — thrown when build() is called without query fields

tests/
├── TestCase.php                    — abstract base; makeHelloSchema() factory for a reusable test schema
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

---

### GraphQLController (`src/GraphQLController.php`)

Invokable controller (`__invoke(Request): Response`). Extracts `query`, `variables`, and `operationName` from the parsed request body via `$request->all()`. Non-string query values and non-array variable values are silently coerced to their defaults (`null`/`[]`) rather than rejected — this matches common client behaviour where omitted optional fields may arrive as `null` or a wrong type.

Returns HTTP 400 only when `query` is missing or empty. All other responses (including GraphQL errors) use HTTP 200, per the [GraphQL over HTTP spec](https://graphql.github.io/graphql-over-http/rfcs/GraphQLOverHTTP.html).

---

### GraphQL (`src/GraphQL.php`)

Static facade following the `Health`/`Flag`/`Metrics` pattern. Holds `private static ?GraphQLExecutor $executor`. Initialised by `GraphQLServiceProvider::boot()`. Throws `RuntimeException` (fail-fast) when called before initialisation. `resetExecutor()` clears the singleton for test `tearDown`.

---

### GraphQLServiceProvider (`src/GraphQLServiceProvider.php`)

`register()` binds `GraphQLExecutor` lazily — requires `GraphQL\Type\Schema` to already be bound (fail-fast if not). The `Schema` binding is the user's responsibility and must be registered in a provider that runs before `GraphQLServiceProvider`.

`boot()` initialises the static facade and registers `POST /graphql` (or the configured endpoint). Route registration is wrapped in `try/catch` for CLI safety. The endpoint URI is read from `graphql.endpoint` config with `/graphql` as the default.

---

## Design decisions and constraints

- **webonyx/graphql-php as the engine.** Implementing a GraphQL lexer, parser, type system, and executor from scratch would be a significant independent project with no framework value. webonyx is the de-facto standard PHP GraphQL library (15 million downloads/month), actively maintained, and fully typed.
- **Schema is user-defined.** The module deliberately does not ship a default schema. The `Schema` binding is the application's responsibility — `GraphQLServiceProvider` fails fast if it is missing. This is the correct design: a GraphQL API without a schema is meaningless, and there is no safe default.
- **No automatic type discovery or code generation.** Annotation-based or reflection-based schema generation adds magic that is hard to trace. Users define their types explicitly using webonyx's native API or `SchemaBuilder`. This keeps the module explicit and dependency-free of reflection libraries.
- **HTTP 200 for GraphQL errors.** The GraphQL over HTTP spec states that partial success responses (data + errors) and full error responses (no data) should use HTTP 200. Only a missing/empty query field (a protocol error, not a GraphQL error) returns HTTP 400.
- **`SchemaBuilder` covers simple schemas only.** The fluent builder wraps webonyx's `ObjectType` and `Schema` for the single-query-root, single-mutation-root case. Advanced schemas (multiple types, interfaces, unions) use webonyx directly. This is an explicit scope limit — adding full schema DSL functionality would duplicate webonyx.
- **Variables passed as `null` when empty.** webonyx treats `null` as "no variables provided" and `[]` as "empty variables object". Passing `null` for empty variables produces correct behaviour with all webonyx validators.
- **`ez-php/framework` required for route registration.** The Router lives in `ez-php/framework`. Wrapping the registration in `try/catch` ensures the module can be used in contexts where only contracts + http are present (e.g. custom dispatchers), but the route simply won't be registered.

---

## Testing approach

No external infrastructure required — all tests run in-process.

- `TestCase` provides `makeHelloSchema()`, a reusable webonyx schema with `hello` (no args, returns `'world'`) and `greet` (requires `$name`, returns `"Hello, $name!"`).
- `SchemaBuilderTest` — builds schemas, validates mutation presence/absence, tests immutability of builder clones, asserts exception when query is missing.
- `GraphQLExecutorTest` — covers happy path, variables, operation name selection, invalid field errors, syntax errors, debug vs production error format, resolver exception containment.
- `GraphQLControllerTest` — constructs `Request` objects directly (`new Request('POST', '/graphql', body: [...])`), asserts HTTP status, response body, content-type header, and correct delegation of variables/operationName.
- `GraphQLTest` — facade delegation, fail-fast on uninitialised access, `resetExecutor()` for test isolation.

All test classes declare `#[CoversClass]` and `#[UsesClass]` attributes for strict coverage metadata.

---

## What does not belong in this module

- **Schema definition DSL / annotations** — use webonyx's API directly; annotation magic is not in scope.
- **GraphQL subscriptions** — subscriptions require a persistent connection layer (WebSocket/SSE); use `ez-php/websocket` and `ez-php/broadcast` for real-time features.
- **Persisted queries** — query ID → document mapping belongs in application middleware, not this module.
- **Authentication / authorisation guards on resolvers** — use context injection via webonyx's context parameter and application-level auth logic.
- **Rate limiting on the GraphQL endpoint** — apply `ez-php/rate-limiter`'s `ThrottleMiddleware` to the `/graphql` route.
- **N+1 query solving (DataLoader)** — batching/deferred resolution belongs in a separate `ez-php/dataloader` module or a webonyx extension.
- **Schema introspection disabling** — disable via webonyx's `Schema::$assumeValid` or a custom validation rule at the application level.
- **Multi-schema / schema stitching** — bind a combined `Schema` in the application service provider; the module always uses whichever `Schema` is bound.
