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

`ez-php/graphql` has no built-in DataLoader wiring — schema fields are resolved with plain
closures, and it composes with `ez-php/dataloader` at the resolver level rather than as a
module dependency. Create one `DataLoader` per entity type in your schema provider and
close over it from the resolvers that need it:

```php
use EzPhp\DataLoader\DataLoader;
use EzPhp\GraphQL\SchemaBuilder;
use GraphQL\Type\Definition\Type;

$userLoader = new DataLoader(fn(array $ids): array => Db::query(
    'SELECT * FROM users WHERE id IN (?)',
    [$ids],
)->keyBy('id'));

$schema = SchemaBuilder::create()
    ->query([
        'post' => [
            'type' => Type::string(),
            'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
            'resolve' => function ($root, array $args) use ($userLoader): array {
                $post = findPost($args['id']);

                // queues the author id; the batch call only fires once every
                // sibling field in this selection set has queued its own key
                $authorDeferred = $userLoader->load($post['author_id']);

                return ['title' => $post['title'], 'author' => $authorDeferred->get()];
            },
        ],
    ])
    ->build();
```

Every `load()` call within the same resolver pass queues its key on the loader without
running the batch function; `Deferred::get()` triggers `dispatch()` the first time a value
is actually needed, so N sibling posts resolving the same `$userLoader` produce one query
for all their authors instead of N queries. See `modules/dataloader/README.md` for the
loader's full API (`prime()`/`clear()`/`clearAll()`, the batch function contract).

**Caveat:** `SchemaBuilder`/`GraphQLServiceProvider` bind `Schema` (and therefore any
`DataLoader` captured by its resolver closures) once, at boot. A loader built this way is
**process-lifetime, not request-scoped** — memoized values and in-flight batches persist
across requests within the same worker process. This is safe for read-through caches with
short TTLs or stateless batch functions, but wrong for anything that must not leak between
requests (e.g. a loader keyed by the current user). A request-scoped registry that creates
fresh loaders per request and injects them via webonyx's resolver `$context` argument is a
larger change — see `TODO.md` ("Architecture / Tooling" → `DataLoaderRegistry`) for that
follow-up, which would live in this module.

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
];
```

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
