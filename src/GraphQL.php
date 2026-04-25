<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use RuntimeException;

/**
 * Class GraphQL
 *
 * Static facade for executing GraphQL queries.
 *
 * Usage:
 *
 *   $result = GraphQL::execute('{ hello }');
 *   $result = GraphQL::execute('query GetUser($id: ID!) { user(id: $id) { name } }', ['id' => '1']);
 *
 * Must be initialised by `GraphQLServiceProvider::boot()` before use.
 * Throws `RuntimeException` when called before initialisation (fail-fast).
 *
 * @package EzPhp\GraphQL
 */
final class GraphQL
{
    private static ?GraphQLExecutor $executor = null;

    /**
     * Initialise the facade with the resolved executor instance.
     * Called by `GraphQLServiceProvider::boot()`.
     */
    public static function setExecutor(GraphQLExecutor $executor): void
    {
        self::$executor = $executor;
    }

    /**
     * Reset the facade — used in test tearDown to prevent state leaking.
     */
    public static function resetExecutor(): void
    {
        self::$executor = null;
    }

    /**
     * Execute a GraphQL query and return the result array.
     *
     * @param string               $query         GraphQL query or mutation document.
     * @param array<string, mixed> $variables     Optional variable map.
     * @param string|null          $operationName Optional operation name.
     *
     * @return array<string, mixed>
     */
    public static function execute(string $query, array $variables = [], ?string $operationName = null): array
    {
        return self::executor()->execute($query, $variables, $operationName);
    }

    /**
     * Resolve the executor singleton, throwing when not initialised.
     */
    private static function executor(): GraphQLExecutor
    {
        if (self::$executor === null) {
            throw new RuntimeException(
                'GraphQL facade is not initialised. Add GraphQLServiceProvider to your application.'
            );
        }

        return self::$executor;
    }
}
