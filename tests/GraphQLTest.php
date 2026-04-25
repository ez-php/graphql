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
