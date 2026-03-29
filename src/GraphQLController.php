<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use EzPhp\Http\Request;
use EzPhp\Http\Response;

/**
 * Class GraphQLController
 *
 * Handles HTTP requests to the GraphQL endpoint (POST /graphql).
 *
 * Accepts a JSON body with the following fields:
 *   - `query`         (string, required) — GraphQL document.
 *   - `variables`     (object, optional) — variable map.
 *   - `operationName` (string, optional) — operation to execute in multi-operation documents.
 *
 * Always returns HTTP 200 with a JSON body. GraphQL-level errors are reported
 * in the `errors` key of the response, not via HTTP status codes (per GraphQL spec).
 *
 * @package EzPhp\GraphQL
 */
final class GraphQLController
{
    public function __construct(private readonly GraphQLExecutor $executor)
    {
    }

    /**
     * Execute a GraphQL request and return the JSON response.
     */
    public function __invoke(Request $request): Response
    {
        $body = $request->all();

        $query = isset($body['query']) && is_string($body['query']) ? $body['query'] : null;

        if ($query === null || $query === '') {
            return Response::json(
                ['errors' => [['message' => 'No GraphQL query provided.']]],
                400,
            );
        }

        $variables = [];
        $rawVariables = $body['variables'] ?? null;

        if (is_array($rawVariables)) {
            /** @var array<string, mixed> $variables */
            $variables = $rawVariables;
        }

        $operationName = null;
        $rawOperation = $body['operationName'] ?? null;

        if (is_string($rawOperation) && $rawOperation !== '') {
            $operationName = $rawOperation;
        }

        $result = $this->executor->execute($query, $variables, $operationName);

        return Response::json($result);
    }
}
