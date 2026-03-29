<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQL\GraphQLController;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(GraphQLController::class)]
#[UsesClass(GraphQLExecutor::class)]
final class GraphQLControllerTest extends GraphQLTestCase
{
    private GraphQLController $controller;

    protected function setUp(): void
    {
        $executor = new GraphQLExecutor($this->makeHelloSchema());
        $this->controller = new GraphQLController($executor);
    }

    public function testReturns200WithData(): void
    {
        $request = $this->makePostRequest(['query' => '{ hello }']);

        $response = ($this->controller)($request);

        self::assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertSame(['hello' => 'world'], $body['data']);
    }

    public function testReturns400WhenQueryMissing(): void
    {
        $request = $this->makePostRequest([]);

        $response = ($this->controller)($request);

        self::assertSame(400, $response->status());
        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('errors', $body);
    }

    public function testReturns400WhenQueryIsEmpty(): void
    {
        $request = $this->makePostRequest(['query' => '']);

        $response = ($this->controller)($request);

        self::assertSame(400, $response->status());
    }

    public function testPassesVariablesToExecutor(): void
    {
        $request = $this->makePostRequest([
            'query' => 'query Greet($name: String!) { greet(name: $name) }',
            'variables' => ['name' => 'Bob'],
        ]);

        $response = ($this->controller)($request);

        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertSame(['greet' => 'Hello, Bob!'], $body['data']);
    }

    public function testPassesOperationNameToExecutor(): void
    {
        $request = $this->makePostRequest([
            'query' => 'query A { hello } query B { hello }',
            'operationName' => 'A',
        ]);

        $response = ($this->controller)($request);

        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertSame(['hello' => 'world'], $body['data']);
    }

    public function testIgnoresNonStringOperationName(): void
    {
        $request = $this->makePostRequest([
            'query' => '{ hello }',
            'operationName' => 123,
        ]);

        $response = ($this->controller)($request);

        self::assertSame(200, $response->status());
    }

    public function testIgnoresNonArrayVariables(): void
    {
        $request = $this->makePostRequest([
            'query' => '{ hello }',
            'variables' => 'invalid',
        ]);

        $response = ($this->controller)($request);

        self::assertSame(200, $response->status());
    }

    public function testResponseHasJsonContentType(): void
    {
        $request = $this->makePostRequest(['query' => '{ hello }']);

        $response = ($this->controller)($request);

        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        self::assertStringContainsString('application/json', $headers['content-type'] ?? '');
    }

    public function testReturnsGraphqlErrorsWithHttp200(): void
    {
        $request = $this->makePostRequest(['query' => '{ nonexistent }']);

        $response = ($this->controller)($request);

        // GraphQL spec: errors are reported in the body, not via HTTP status
        self::assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('errors', $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makePostRequest(array $body): Request
    {
        return new Request(
            method: 'POST',
            uri: '/graphql',
            body: $body,
            headers: ['content-type' => 'application/json'],
        );
    }
}
