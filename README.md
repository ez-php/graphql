# ez-php/graphql

GraphQL module for the ez-php framework. Provides a `POST /graphql` HTTP endpoint, schema builder, query executor, and static facade — powered by [webonyx/graphql-php](https://github.com/webonyx/graphql-php).

## Installation

```bash
composer require ez-php/graphql
```

## Setup

### 1. Define your schema

Create a service provider that binds a `GraphQL\Type\Schema`:

```php
// app/Providers/GraphQLSchemaProvider.php
use EzPhp\Contracts\ServiceProvider;
use EzPhp\GraphQL\SchemaBuilder;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

final class GraphQLSchemaProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Schema::class, function (): Schema {
            return SchemaBuilder::create()
                ->query([
                    'hello' => [
                        'type' => Type::string(),
                        'resolve' => fn(): string => 'Hello, World!',
                    ],
                    'user' => [
                        'type' => Type::string(),
                        'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
                        'resolve' => fn($root, array $args): string => 'User ' . $args['id'],
                    ],
                ])
                ->build();
        });
    }

    public function boot(): void {}
}
```

### 2. Register providers

In `provider/modules.php`, register your schema provider **before** `GraphQLServiceProvider`:

```php
$app->register(GraphQLSchemaProvider::class);
$app->register(\EzPhp\GraphQL\GraphQLServiceProvider::class);
```

## HTTP Endpoint

`POST /graphql` — accepts JSON:

```json
{
  "query": "{ hello }",
  "variables": {},
  "operationName": null
}
```

Response:

```json
{
  "data": {
    "hello": "Hello, World!"
  }
}
```

## Recipes

### N+1 batching with `ez-php/dataloader`

`ez-php/graphql` depends on `ez-php/dataloader` and ships `DataLoaderRegistry` — a
request-scoped, keyed registry of `DataLoader` instances, so resolvers that batch the same
kind of data (e.g. a `post.author` field and a `comment.author` field, both loading `User`
rows) share one loader and one batch call instead of each maintaining its own.

Construct one registry per request and pass it through as webonyx's execution context so
every resolver can reach it via `$context`:

```php
use EzPhp\GraphQL\DataLoaderRegistry;
use EzPhp\GraphQL\SchemaBuilder;
use GraphQL\Type\Definition\Type;

$schema = SchemaBuilder::create()
    ->query([
        'post' => [
            'type' => Type::string(),
            'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
            'resolve' => function ($root, array $args, DataLoaderRegistry $context): array {
                $post = findPost($args['id']);
                $userLoader = $context->get('users', fn(array $ids): array => Db::query(
                    'SELECT * FROM users WHERE id IN (?)',
                    [$ids],
                )->keyBy('id'));

                // queues the author id; the batch call only fires once every
                // sibling field in this selection set has queued its own key
                $authorDeferred = $userLoader->load($post['author_id']);

                return ['title' => $post['title'], 'author' => $authorDeferred->get()];
            },
        ],
    ])
    ->build();

// per request:
$registry = new DataLoaderRegistry();
$executor->execute($query, $variables, context: $registry);
```

`DataLoaderRegistry::get(key, batchLoadFn)` creates a `DataLoader` on first call for a key
and returns the same instance on every later call for that key within the same registry —
the batch function passed on a later call is ignored, since the first resolver to reach a
key defines it. `Deferred::get()` triggers `dispatch()` the first time a value is actually
needed, so N sibling posts resolving the same `users` loader produce one query for all
their authors instead of N queries. See `modules/dataloader/README.md` for `DataLoader`'s
full API (`prime()`/`clear()`/`clearAll()`, the batch function contract).

**Always construct a fresh `DataLoaderRegistry` per request and discard it afterward** — a
shared, long-lived instance (e.g. a container singleton) would leak cached values and
pending batch keys across unrelated requests. `DataLoaderRegistry` is deliberately not
wired into `GraphQLServiceProvider` for this reason; pass a fresh one as `execute()`'s
`$context` argument yourself, per request, from your own controller or middleware.

### Subscriptions over `ez-php/websocket` + `ez-php/broadcast`

`ez-php/graphql` has no subscription protocol — there is no persistent-connection layer
here, by design (see CLAUDE.md "What does not belong in this module"). Real-time updates
are wired at the application layer instead: a mutation resolver broadcasts an event after
it writes, and clients that want the update subscribe over a plain WebSocket channel
rather than a GraphQL `subscription` operation:

```php
use EzPhp\Broadcast\Broadcast;
use EzPhp\GraphQL\SchemaBuilder;
use GraphQL\Type\Definition\Type;

$schema = SchemaBuilder::create()
    ->mutation([
        'createComment' => [
            'type' => Type::string(),
            'args' => ['postId' => ['type' => Type::nonNull(Type::id())], 'body' => ['type' => Type::nonNull(Type::string())]],
            'resolve' => function ($root, array $args): string {
                $comment = createComment($args['postId'], $args['body']);

                // Clients subscribed to `post.{id}` over ez-php/websocket receive this
                // as a plain WS message; there's no GraphQL-level `subscription` field.
                Broadcast::to('post.' . $args['postId'], 'comment.created', ['id' => $comment['id'], 'body' => $comment['body']]);

                return $comment['id'];
            },
        ],
    ])
    ->build();
```

On the client side this is two separate connections: a GraphQL HTTP request for the
mutation, and a WebSocket connection (see `modules/websocket/README.md`) subscribed to
`post.{id}` for the resulting push. There is no single subscription query that does both —
composing them is the application's job, not this module's.

## Static Facade

```php
use EzPhp\GraphQL\GraphQL;

$result = GraphQL::execute('{ hello }');
// ['data' => ['hello' => 'Hello, World!']]

$result = GraphQL::execute(
    'query GetUser($id: ID!) { user(id: $id) }',
    ['id' => '42'],
);
```

## Schema Builder

`SchemaBuilder` wraps webonyx's schema API for common cases:

```php
use EzPhp\GraphQL\SchemaBuilder;
use GraphQL\Type\Definition\Type;

$schema = SchemaBuilder::create()
    ->query([
        'posts' => [
            'type'    => Type::listOf(Type::string()),
            'resolve' => fn(): array => ['Post 1', 'Post 2'],
        ],
    ])
    ->mutation([
        'createPost' => [
            'type' => Type::string(),
            'args' => ['title' => ['type' => Type::nonNull(Type::string())]],
            'resolve' => fn($root, array $args): string => $args['title'],
        ],
    ])
    ->build();
```

For advanced schemas (interfaces, unions, enums, custom scalars) construct the webonyx `Schema` directly.

## Configuration

Optional `config/graphql.php`:

```php
return [
    // URI for the GraphQL endpoint. Default: '/graphql'
    'endpoint' => '/graphql',

    // Limits against expensive documents (0 disables). Defaults: 15 / 200.
    'max_query_depth' => 15,
    'max_query_complexity' => 200,

    // Automatic Persisted Queries (needs a bound ez-php/cache CacheInterface). Default: off.
    'persisted_queries' => false,
    'persisted_queries_ttl' => 0,   // seconds a stored query lives; 0 = no expiry
];
```

### Persisted queries

With `persisted_queries => true` the endpoint speaks the Apollo APQ protocol: a client may send `extensions.persistedQuery = {version: 1, sha256Hash}` instead of the query text.

| Request | Result |
|---|---|
| hash only, known | the stored query runs |
| hash only, unknown | `PERSISTED_QUERY_NOT_FOUND` — the client resends hash **and** text |
| hash + text | the hash must be the SHA-256 of the text (else `PERSISTED_QUERY_HASH_MISMATCH`); the query is stored and runs |
| hash while the feature is off | `PERSISTED_QUERY_NOT_SUPPORTED` — the client falls back to full queries |

Queries larger than 64 KiB run but are not stored. The store is `PersistedQueryStore` (entries are `graphql:apq:<sha256>` in the cache).

Debug mode is read from `app.debug`. When enabled, error responses include `debugMessage` and stack traces.

## Error handling

GraphQL-level errors (unknown fields, failed resolvers) are returned with HTTP 200 in the `errors` array, per the GraphQL spec:

```json
{
  "errors": [
    { "message": "Cannot query field \"nonexistent\" on type \"Query\"." }
  ]
}
```

A missing or empty `query` field returns HTTP 400.

## Testing

No external services required.

```bash
composer test
```

## License

MIT
