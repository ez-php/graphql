<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Routing\Router;
use GraphQL\Type\Schema;

/**
 * Class GraphQLServiceProvider
 *
 * Registers the GraphQL executor and HTTP endpoint in the application container.
 *
 * Register in provider/modules.php:
 *
 *   $app->register(GraphQLServiceProvider::class);
 *
 * Prerequisite: bind a `GraphQL\Type\Schema` instance in a custom service provider
 * before registering this provider:
 *
 *   $this->app->bind(Schema::class, function (): Schema {
 *       return SchemaBuilder::create()
 *           ->query(['hello' => ['type' => Type::string(), 'resolve' => fn() => 'world']])
 *           ->build();
 *   });
 *
 * Configuration keys (config/graphql.php or environment):
 *
 *   graphql.endpoint   — URI for the GraphQL endpoint (default: '/graphql')
 *   app.debug          — bool, enables detailed error output in responses
 *
 * @package EzPhp\GraphQL
 */
final class GraphQLServiceProvider extends ServiceProvider
{
    /**
     * Bind GraphQLExecutor into the container.
     *
     * Requires a `GraphQL\Type\Schema` binding — throws if not present (fail-fast).
     */
    public function register(): void
    {
        $this->app->bind(GraphQLExecutor::class, function (ContainerInterface $app): GraphQLExecutor {
            $schema = $app->make(Schema::class);

            $debug = false;

            try {
                $config = $app->make(ConfigInterface::class);
                $raw = $config->get('app.debug', false);
                $debug = is_bool($raw) ? $raw : false;
            } catch (\Throwable) {
                // Config not bound — default to non-debug mode.
            }

            return new GraphQLExecutor($schema, $debug);
        });
    }

    /**
     * Initialise the facade and register the HTTP route.
     */
    public function boot(): void
    {
        GraphQL::setExecutor($this->app->make(GraphQLExecutor::class));

        try {
            $router = $this->app->make(Router::class);

            $endpoint = '/graphql';

            try {
                $config = $this->app->make(ConfigInterface::class);
                $raw = $config->get('graphql.endpoint', '/graphql');
                $endpoint = is_string($raw) ? $raw : '/graphql';
            } catch (\Throwable) {
                // Config not bound — use default endpoint.
            }

            $router->post($endpoint, [GraphQLController::class, '__invoke']);
        } catch (\Throwable) {
            // Router not available in CLI or test contexts.
        }
    }
}
