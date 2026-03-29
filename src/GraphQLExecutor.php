<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL as WebonixGraphQL;
use GraphQL\Type\Schema;

/**
 * Class GraphQLExecutor
 *
 * Executes GraphQL queries against a schema using the webonyx/graphql-php engine.
 *
 * Handles both GraphQL-level errors (invalid fields, failed resolvers) and
 * PHP-level exceptions, always returning a well-formed response array.
 *
 * @package EzPhp\GraphQL
 */
final class GraphQLExecutor
{
    /**
     * @param Schema $schema The compiled GraphQL schema.
     * @param bool   $debug  When true, includes debug messages and stack traces in error output.
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * Execute a GraphQL query and return the result as an array.
     *
     * GraphQL-level errors (e.g. unknown fields, failed resolvers) are returned in
     * the `errors` key of the result array — they do not throw.
     *
     * PHP-level exceptions during execution are caught and returned as a single
     * top-level error, preserving the HTTP 200 contract for GraphQL responses.
     *
     * @param string               $query         GraphQL query or mutation document.
     * @param array<string, mixed> $variables     Optional variable map.
     * @param string|null          $operationName Optional operation name for multi-operation documents.
     *
     * @return array<string, mixed>
     */
    public function execute(string $query, array $variables = [], ?string $operationName = null): array
    {
        try {
            $result = WebonixGraphQL::executeQuery(
                $this->schema,
                $query,
                null,
                null,
                $variables !== [] ? $variables : null,
                $operationName,
            );

            $debugFlag = $this->debug
                ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
                : DebugFlag::NONE;

            return $result->toArray($debugFlag);
        } catch (\Throwable $e) {
            return [
                'errors' => [
                    ['message' => $this->debug ? $e->getMessage() : 'Internal server error'],
                ],
            ];
        }
    }
}
