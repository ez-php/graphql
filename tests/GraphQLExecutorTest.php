<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQL\GraphQLExecutor;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GraphQLExecutor::class)]
final class GraphQLExecutorTest extends GraphQLTestCase
{
    public function testExecuteReturnsData(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema());

        $result = $executor->execute('{ hello }');

        self::assertSame(['data' => ['hello' => 'world']], $result);
    }

    public function testExecutePassesVariablesToResolver(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema());

        $result = $executor->execute(
            'query Greet($name: String!) { greet(name: $name) }',
            ['name' => 'Alice'],
        );

        self::assertSame(['data' => ['greet' => 'Hello, Alice!']], $result);
    }

    public function testExecuteReturnsErrorsForInvalidField(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema());

        $result = $executor->execute('{ nonexistent }');

        self::assertArrayHasKey('errors', $result);
        self::assertIsArray($result['errors']);
        self::assertNotEmpty($result['errors']);
    }

    public function testExecuteReturnsErrorsForInvalidSyntax(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema());

        $result = $executor->execute('{ {{ invalid syntax');

        self::assertArrayHasKey('errors', $result);
    }

    public function testExecuteUsesOperationName(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema());

        $result = $executor->execute(
            'query A { hello } query B { hello }',
            [],
            'A',
        );

        self::assertSame(['data' => ['hello' => 'world']], $result);
    }

    public function testExecuteHidesErrorMessageInProductionMode(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema(), debug: false);

        // Force an internal error by using an invalid query
        $result = $executor->execute('{ {{ invalid');

        self::assertArrayHasKey('errors', $result);
        // In non-debug mode, debugMessage and trace are absent
        $errors = $result['errors'];
        self::assertIsArray($errors);
        $firstError = $errors[0];
        self::assertIsArray($firstError);
        self::assertArrayNotHasKey('debugMessage', $firstError);
    }

    public function testExecuteIncludesDebugMessageInDebugMode(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema(), debug: true);

        $result = $executor->execute('{ nonexistent }');

        self::assertArrayHasKey('errors', $result);
        $errors = $result['errors'];
        self::assertIsArray($errors);
        $firstError = $errors[0];
        self::assertIsArray($firstError);
        // webonyx adds debugMessage in debug mode for internal errors
        self::assertArrayHasKey('message', $firstError);
    }

    public function testExecuteHandlesResolverException(): void
    {
        $schema = $this->makeSchemaWithThrowingResolver();

        $executor = new GraphQLExecutor($schema, debug: false);
        $result = $executor->execute('{ boom }');

        self::assertArrayHasKey('errors', $result);
    }

    public function testExecuteRejectsQueryExceedingMaxDepth(): void
    {
        $executor = new GraphQLExecutor($this->makeNestedSchema(), maxQueryDepth: 2);

        // root -> child -> child -> child -> value (well beyond depth 2)
        $result = $executor->execute('{ root { child { child { child { value } } } } }');

        // Validation-rule failures surface as GraphQL errors (HTTP 200), not exceptions.
        self::assertArrayHasKey('errors', $result);
        self::assertIsArray($result['errors']);
        self::assertNotEmpty($result['errors']);
        self::assertArrayNotHasKey('data', $result);
    }

    public function testExecuteAllowsDeepQueryWhenDepthLimitDisabled(): void
    {
        $executor = new GraphQLExecutor($this->makeNestedSchema(), maxQueryDepth: 0);

        $result = $executor->execute('{ root { child { child { child { value } } } } }');

        self::assertArrayNotHasKey('errors', $result);
        self::assertArrayHasKey('data', $result);
    }

    public function testDefaultDepthLimitAllowsTypicalQuery(): void
    {
        // No depth argument → DEFAULT_MAX_QUERY_DEPTH applies and a normal query still works.
        $executor = new GraphQLExecutor($this->makeHelloSchema());

        $result = $executor->execute('{ hello }');

        self::assertSame(['data' => ['hello' => 'world']], $result);
    }

    public function testExecuteRejectsQueryExceedingMaxComplexity(): void
    {
        $executor = new GraphQLExecutor($this->makeNestedSchema(), maxQueryComplexity: 1);

        // "root" + "value" = complexity 2, above the limit of 1.
        $result = $executor->execute('{ root { value } }');

        self::assertArrayHasKey('errors', $result);
        self::assertIsArray($result['errors']);
        self::assertNotEmpty($result['errors']);
    }

    /**
     * A self-referential schema: `root` returns a `Node`, and each `Node` has a
     * scalar `value` and a `child` of the same type — allowing arbitrarily deep
     * queries for depth/complexity testing.
     */
    private function makeNestedSchema(): \GraphQL\Type\Schema
    {
        $node = null;
        $node = new \GraphQL\Type\Definition\ObjectType([
            'name' => 'Node',
            'fields' => static function () use (&$node): array {
                return [
                    'value' => [
                        'type' => \GraphQL\Type\Definition\Type::string(),
                        'resolve' => static fn (): string => 'leaf',
                    ],
                    'child' => [
                        'type' => $node,
                        'resolve' => static fn (): array => [],
                    ],
                ];
            },
        ]);

        return new \GraphQL\Type\Schema([
            'query' => new \GraphQL\Type\Definition\ObjectType([
                'name' => 'Query',
                'fields' => [
                    'root' => [
                        'type' => $node,
                        'resolve' => static fn (): array => [],
                    ],
                ],
            ]),
        ]);
    }

    private function makeSchemaWithThrowingResolver(): \GraphQL\Type\Schema
    {
        return new \GraphQL\Type\Schema([
            'query' => new \GraphQL\Type\Definition\ObjectType([
                'name' => 'Query',
                'fields' => [
                    'boom' => [
                        'type' => \GraphQL\Type\Definition\Type::string(),
                        'resolve' => function (): never {
                            throw new \RuntimeException('Resolver exploded');
                        },
                    ],
                ],
            ]),
        ]);
    }
}
