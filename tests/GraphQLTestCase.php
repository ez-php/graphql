<?php

declare(strict_types=1);

namespace Tests;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * GraphQL-specific base test case.
 *
 * Provides a reusable in-memory schema for tests that need to execute queries.
 */
abstract class GraphQLTestCase extends TestCase
{
    /**
     * Build a minimal schema with `hello` and `greet` query fields.
     *
     *   { hello }               → "world"
     *   { greet(name: "X") }    → "Hello, X!"
     */
    protected function makeHelloSchema(): Schema
    {
        return new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'hello' => [
                        'type' => Type::string(),
                        'resolve' => fn (): string => 'world',
                    ],
                    'greet' => [
                        'type' => Type::string(),
                        'args' => ['name' => ['type' => Type::nonNull(Type::string())]],
                        'resolve' => fn (mixed $root, array $args): string => 'Hello, ' . $args['name'] . '!',
                    ],
                ],
            ]),
        ]);
    }
}
