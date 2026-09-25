<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseFactory;

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
 * **Automatic Persisted Queries** (opt-in, when a `PersistedQueryStore` is injected): the request may
 * carry `extensions.persistedQuery = {version: 1, sha256Hash}`. With a hash and no `query` the stored
 * text is executed, or a `PERSISTED_QUERY_NOT_FOUND` error asks the client to resend with the text;
 * with both, the hash must match the text and the query is stored. Without a store, such a request
 * gets `PERSISTED_QUERY_NOT_SUPPORTED` so clients fall back to sending full queries.
 *
 * Always returns HTTP 200 with a JSON body. GraphQL-level errors are reported
 * in the `errors` key of the response, not via HTTP status codes (per GraphQL spec).
 *
 * @package EzPhp\GraphQL
 */
final class GraphQLController
{
    /**
     * GraphQLController Constructor
     *
     * @param GraphQLExecutor          $executor
     * @param PersistedQueryStore|null $persistedQueries
     */
    public function __construct(
        private readonly GraphQLExecutor $executor,
        private readonly ?PersistedQueryStore $persistedQueries = null,
    ) {
    }

    /**
     * Execute a GraphQL request and return the JSON response.
     */
    public function __invoke(Request $request): Response
    {
        $body = $request->all();

        $query = isset($body['query']) && is_string($body['query']) ? $body['query'] : null;

        $persisted = $this->persistedQueryHash($body);

        if ($persisted !== null) {
            $error = $this->resolvePersistedQuery($persisted, $query);

            if ($error !== null) {
                return $error;
            }
        }

        if ($query === null || $query === '') {
            return ResponseFactory::json(
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

        return ResponseFactory::json($result);
    }

    /**
     * Extract the APQ hash from `extensions.persistedQuery`, or null when the request has none.
     *
     * @param array<string, mixed> $body
     *
     * @return array{version: int, hash: string}|null
     */
    private function persistedQueryHash(array $body): ?array
    {
        $extensions = $body['extensions'] ?? null;
        $persisted = is_array($extensions) ? ($extensions['persistedQuery'] ?? null) : null;

        if (!is_array($persisted)) {
            return null;
        }

        $version = $persisted['version'] ?? null;
        $hash = $persisted['sha256Hash'] ?? null;

        return ['version' => is_int($version) ? $version : 0, 'hash' => is_string($hash) ? $hash : ''];
    }

    /**
     * Apply the APQ protocol. Fills `$query` (by reference) when it has to be looked up.
     *
     * @param array{version: int, hash: string} $persisted
     * @param string|null                       $query     Query text from the request; replaced by the stored text on a hash-only request.
     *
     * @return Response|null An error response, or null to continue with `$query`.
     */
    private function resolvePersistedQuery(array $persisted, ?string &$query): ?Response
    {
        if ($this->persistedQueries === null) {
            return $this->persistedError('PersistedQueryNotSupported', 'PERSISTED_QUERY_NOT_SUPPORTED');
        }

        if ($persisted['version'] !== 1) {
            return $this->persistedError('Unsupported persisted query version.', 'PERSISTED_QUERY_NOT_SUPPORTED');
        }

        $hash = strtolower($persisted['hash']);

        if ($query === null || $query === '') {
            $stored = $this->persistedQueries->find($hash);

            if ($stored === null) {
                return $this->persistedError('PersistedQueryNotFound', 'PERSISTED_QUERY_NOT_FOUND');
            }

            $query = $stored;

            return null;
        }

        if (!PersistedQueryStore::matches($hash, $query)) {
            return $this->persistedError('provided sha does not match query', 'PERSISTED_QUERY_HASH_MISMATCH');
        }

        // Best effort: an over-sized query is still executed, it just is not stored.
        $this->persistedQueries->register($hash, $query);

        return null;
    }

    /**
     * @param string $message
     * @param string $code
     *
     * @return Response
     */
    private function persistedError(string $message, string $code): Response
    {
        return ResponseFactory::json(['errors' => [['message' => $message, 'extensions' => ['code' => $code]]]]);
    }
}
