<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\GraphQL\GraphQLController;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQL\GraphQLServiceProvider;
use EzPhp\GraphQL\PersistedQueryStore;
use EzPhp\Http\Request;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * Automatic Persisted Queries: the store, the controller protocol and the provider wiring.
 */
#[CoversClass(PersistedQueryStore::class)]
#[CoversClass(GraphQLController::class)]
#[CoversClass(GraphQLServiceProvider::class)]
#[UsesClass(GraphQLExecutor::class)]
final class GraphQLPersistedQueryTest extends GraphQLTestCase
{
    private const string QUERY = '{ hello }';

    private ArrayDriver $cache;

    private PersistedQueryStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new ArrayDriver();
        $this->store = new PersistedQueryStore($this->cache);
    }

    private function hash(string $query = self::QUERY): string
    {
        return hash('sha256', $query);
    }

    private function controller(?PersistedQueryStore $store): GraphQLController
    {
        return new GraphQLController(new GraphQLExecutor($this->makeHelloSchema()), $store);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(GraphQLController $controller, array $body): array
    {
        $response = $controller(new Request(method: 'POST', uri: '/graphql', body: $body, headers: []));
        $decoded = json_decode($response->body(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array{message: string, code: string}
     */
    private function firstError(array $result): array
    {
        $errors = $result['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        $extensions = $error['extensions'] ?? [];
        $code = is_array($extensions) ? ($extensions['code'] ?? '') : '';
        $message = $error['message'] ?? '';

        return ['message' => is_string($message) ? $message : '', 'code' => is_string($code) ? $code : ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function ext(string $hash, int $version = 1): array
    {
        return ['persistedQuery' => ['version' => $version, 'sha256Hash' => $hash]];
    }

    public function testUnknownHashAsksTheClientToResend(): void
    {
        $result = $this->call($this->controller($this->store), ['extensions' => $this->ext($this->hash())]);

        self::assertSame('PersistedQueryNotFound', $this->firstError($result)['message']);
        self::assertSame('PERSISTED_QUERY_NOT_FOUND', $this->firstError($result)['code']);
    }

    public function testQueryWithHashIsExecutedAndRegistered(): void
    {
        $controller = $this->controller($this->store);

        $result = $this->call($controller, ['query' => self::QUERY, 'extensions' => $this->ext($this->hash())]);

        self::assertSame(['hello' => 'world'], $result['data']);
        self::assertSame(self::QUERY, $this->store->find($this->hash()));
    }

    public function testHashAloneRunsTheStoredQuery(): void
    {
        $controller = $this->controller($this->store);
        $this->call($controller, ['query' => self::QUERY, 'extensions' => $this->ext($this->hash())]);

        $result = $this->call($controller, ['extensions' => $this->ext($this->hash())]);

        self::assertSame(['hello' => 'world'], $result['data']);
    }

    public function testHashIsCaseInsensitive(): void
    {
        $controller = $this->controller($this->store);
        $this->call($controller, ['query' => self::QUERY, 'extensions' => $this->ext($this->hash())]);

        $result = $this->call($controller, ['extensions' => $this->ext(strtoupper($this->hash()))]);

        self::assertSame(['hello' => 'world'], $result['data']);
    }

    public function testMismatchingHashIsRejectedAndNothingIsStored(): void
    {
        $wrong = $this->hash('{ other }');

        $result = $this->call($this->controller($this->store), ['query' => self::QUERY, 'extensions' => $this->ext($wrong)]);

        self::assertSame('PERSISTED_QUERY_HASH_MISMATCH', $this->firstError($result)['code']);
        self::assertNull($this->store->find($wrong));
        self::assertNull($this->store->find($this->hash()));
    }

    public function testMalformedHashIsNeverFound(): void
    {
        $result = $this->call($this->controller($this->store), ['extensions' => $this->ext('not-a-hash')]);

        self::assertSame('PERSISTED_QUERY_NOT_FOUND', $this->firstError($result)['code']);
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $result = $this->call($this->controller($this->store), ['extensions' => $this->ext($this->hash(), 2)]);

        self::assertSame('PERSISTED_QUERY_NOT_SUPPORTED', $this->firstError($result)['code']);
    }

    public function testWithoutAStoreClientsAreToldToSendFullQueries(): void
    {
        $result = $this->call($this->controller(null), ['extensions' => $this->ext($this->hash())]);

        self::assertSame('PersistedQueryNotSupported', $this->firstError($result)['message']);
        self::assertSame('PERSISTED_QUERY_NOT_SUPPORTED', $this->firstError($result)['code']);
    }

    public function testPlainRequestsAreUnaffected(): void
    {
        $result = $this->call($this->controller($this->store), ['query' => self::QUERY]);

        self::assertSame(['hello' => 'world'], $result['data']);
    }

    public function testOversizedQueryIsExecutedButNotStored(): void
    {
        $padding = str_repeat(' ', PersistedQueryStore::MAX_QUERY_BYTES);
        $query = '{ hello }' . $padding;

        $result = $this->call($this->controller($this->store), ['query' => $query, 'extensions' => $this->ext($this->hash($query))]);

        self::assertSame(['hello' => 'world'], $result['data']);
        self::assertNull($this->store->find($this->hash($query)));
    }

    public function testStoreHonoursTheTtlArgument(): void
    {
        $store = new PersistedQueryStore($this->cache, 60);

        self::assertTrue($store->register($this->hash(), self::QUERY));
        self::assertSame(self::QUERY, $store->find($this->hash()));
    }

    public function testProviderEnablesPersistedQueriesFromConfig(): void
    {
        $container = new FakeContainer(new FakeConfig(['graphql.persisted_queries' => true]));
        $container->instance(Schema::class, $this->makeHelloSchema());
        $container->instance(\EzPhp\Cache\CacheInterface::class, $this->cache);
        (new GraphQLServiceProvider($container))->register();

        $controller = $container->make(GraphQLController::class);
        self::assertInstanceOf(GraphQLController::class, $controller);

        $this->call($controller, ['query' => self::QUERY, 'extensions' => $this->ext($this->hash())]);

        self::assertSame(self::QUERY, $this->store->find($this->hash()));
    }

    public function testProviderLeavesPersistedQueriesOffByDefault(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $container->instance(Schema::class, $this->makeHelloSchema());
        $container->instance(\EzPhp\Cache\CacheInterface::class, $this->cache);
        (new GraphQLServiceProvider($container))->register();

        $controller = $container->make(GraphQLController::class);
        self::assertInstanceOf(GraphQLController::class, $controller);

        $result = $this->call($controller, ['extensions' => $this->ext($this->hash())]);

        self::assertSame('PERSISTED_QUERY_NOT_SUPPORTED', $this->firstError($result)['code']);
    }
}
