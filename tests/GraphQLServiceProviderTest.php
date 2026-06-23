<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQL\GraphQL;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQL\GraphQLServiceProvider;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * Smoke test: GraphQLServiceProvider registers and boots its bindings in a
 * minimal container context (with a Schema seeded) without error.
 *
 * @uses \Tests\Support\FakeConfig
 * @uses \Tests\Support\FakeContainer
 */
#[CoversClass(GraphQLServiceProvider::class)]
#[UsesClass(GraphQLExecutor::class)]
#[UsesClass(GraphQL::class)]
final class GraphQLServiceProviderTest extends GraphQLTestCase
{
    protected function tearDown(): void
    {
        GraphQL::resetExecutor();
        parent::tearDown();
    }

    /**
     * @return FakeContainer
     */
    private function containerWithSchema(): FakeContainer
    {
        $container = new FakeContainer(new FakeConfig([]));
        $container->instance(Schema::class, $this->makeHelloSchema());

        return $container;
    }

    public function test_register_binds_executor(): void
    {
        $container = $this->containerWithSchema();
        $provider = new GraphQLServiceProvider($container);

        $provider->register();

        $this->assertTrue($container->wasBound(GraphQLExecutor::class));
        $this->assertInstanceOf(GraphQLExecutor::class, $container->make(GraphQLExecutor::class));
    }

    public function test_boot_initialises_facade(): void
    {
        $container = $this->containerWithSchema();
        $provider = new GraphQLServiceProvider($container);

        $provider->register();
        $provider->boot(); // Router not bound — route registration is skipped silently.

        // The facade is wired and executes against the seeded schema.
        $this->assertSame(['data' => ['hello' => 'world']], GraphQL::execute('{ hello }'));
    }
}
