<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL as WebonixGraphQL;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * Class GraphQLExecutor
 *
 * Executes GraphQL queries against a schema using the webonyx/graphql-php engine.
 *
 * Handles both GraphQL-level errors (invalid fields, failed resolvers) and
 * PHP-level exceptions, always returning a well-formed response array.
 *
 * Query depth and complexity limits guard against maliciously nested or
 * expensive documents (a denial-of-service vector). Depth is bounded by
 * default; complexity is opt-in (schemas weight fields differently).
 *
 * @package EzPhp\GraphQL
 */
final class GraphQLExecutor
{
    /**
     * Default maximum query nesting depth. Generous enough for typical APIs
     * while still rejecting pathologically deep documents out of the box.
     */
    public const int DEFAULT_MAX_QUERY_DEPTH = 15;

    /**
     * @param Schema $schema             The compiled GraphQL schema.
     * @param bool   $debug              When true, includes debug messages and stack traces in error output.
     * @param int    $maxQueryDepth      Maximum allowed query nesting depth; `0` disables the limit.
     * @param int    $maxQueryComplexity Maximum allowed query complexity score; `0` (default) disables the limit.
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly bool $debug = false,
        private readonly int $maxQueryDepth = self::DEFAULT_MAX_QUERY_DEPTH,
        private readonly int $maxQueryComplexity = 0,
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
                null,
                $this->validationRules(),
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

    /**
     * Builds the validation rule set for a query execution.
     *
     * Returns `null` (webonyx applies its full default rule set) when neither a
     * depth nor a complexity limit is configured. Otherwise it starts from the
     * complete default rule set and only overrides the depth/complexity rules,
     * so all standard validation (unknown fields, type checks, …) is preserved
     * — passing a partial rule list to webonyx would replace the defaults.
     *
     * @return array<string, \GraphQL\Validator\Rules\ValidationRule>|null
     */
    private function validationRules(): ?array
    {
        if ($this->maxQueryDepth <= 0 && $this->maxQueryComplexity <= 0) {
            return null;
        }

        $rules = DocumentValidator::allRules();

        if ($this->maxQueryDepth > 0) {
            $rules[QueryDepth::class] = new QueryDepth($this->maxQueryDepth);
        }

        if ($this->maxQueryComplexity > 0) {
            $rules[QueryComplexity::class] = new QueryComplexity($this->maxQueryComplexity);
        }

        return $rules;
    }
}
