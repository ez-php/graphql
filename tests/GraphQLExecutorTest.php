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
