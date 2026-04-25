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
