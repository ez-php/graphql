<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQL\GraphQL;
use EzPhp\GraphQL\GraphQLExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

#[CoversClass(GraphQL::class)]
#[UsesClass(GraphQLExecutor::class)]
final class GraphQLTest extends GraphQLTestCase
{
    protected function tearDown(): void
    {
        GraphQL::resetExecutor();
    }

    public function testExecuteDelegatesToExecutor(): void
    {
        GraphQL::setExecutor(new GraphQLExecutor($this->makeHelloSchema()));

        $result = GraphQL::execute('{ hello }');

        self::assertSame(['data' => ['hello' => 'world']], $result);
    }

    public function testExecutePassesVariables(): void
    {
        GraphQL::setExecutor(new GraphQLExecutor($this->makeHelloSchema()));

        $result = GraphQL::execute(
            'query G($name: String!) { greet(name: $name) }',
            ['name' => 'Eve'],
        );

        self::assertSame(['data' => ['greet' => 'Hello, Eve!']], $result);
    }

    public function testExecutePassesContext(): void
    {
        GraphQL::setExecutor(new GraphQLExecutor($this->makeSchemaReadingContext()));

        $result = GraphQL::execute('{ whoAmI }', context: 'user-7');

        self::assertSame(['data' => ['whoAmI' => 'user-7']], $result);
    }

    private function makeSchemaReadingContext(): \GraphQL\Type\Schema
    {
        return new \GraphQL\Type\Schema([
            'query' => new \GraphQL\Type\Definition\ObjectType([
                'name' => 'Query',
                'fields' => [
                    'whoAmI' => [
                        'type' => \GraphQL\Type\Definition\Type::string(),
                        'resolve' => fn (mixed $root, array $args, mixed $context): mixed => $context,
                    ],
                ],
            ]),
        ]);
    }

    public function testThrowsWhenNotInitialised(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialised/');

        GraphQL::execute('{ hello }');
    }

    public function testResetClearsExecutor(): void
    {
        GraphQL::setExecutor(new GraphQLExecutor($this->makeHelloSchema()));
        GraphQL::resetExecutor();

        $this->expectException(RuntimeException::class);

        GraphQL::execute('{ hello }');
    }
}
