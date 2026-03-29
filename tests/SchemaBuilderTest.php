<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQL\SchemaBuilder;
use EzPhp\GraphQL\SchemaException;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SchemaBuilder::class)]
#[UsesClass(SchemaException::class)]
final class SchemaBuilderTest extends GraphQLTestCase
{
    public function testBuildReturnsSchema(): void
    {
        $schema = SchemaBuilder::create()
            ->query(['hello' => ['type' => Type::string(), 'resolve' => fn (): string => 'world']])
            ->build();

        self::assertInstanceOf(Schema::class, $schema);
    }

    public function testBuildThrowsWithoutQueryFields(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Query type/');

        SchemaBuilder::create()->build();
    }

    public function testQueryIsExecutable(): void
    {
        $schema = SchemaBuilder::create()
            ->query(['ping' => ['type' => Type::string(), 'resolve' => fn (): string => 'pong']])
            ->build();

        $result = \GraphQL\GraphQL::executeQuery($schema, '{ ping }')->toArray();

        self::assertSame(['data' => ['ping' => 'pong']], $result);
    }

    public function testMutationIsIncludedWhenDefined(): void
    {
        $schema = SchemaBuilder::create()
            ->query(['noop' => ['type' => Type::string(), 'resolve' => fn (): string => 'ok']])
            ->mutation([
                'echo' => [
                    'type' => Type::string(),
                    'args' => ['msg' => ['type' => Type::nonNull(Type::string())]],
                    'resolve' => fn (mixed $root, array $args): string => (string) $args['msg'],
                ],
            ])
            ->build();

        $result = \GraphQL\GraphQL::executeQuery($schema, 'mutation { echo(msg: "hi") }')->toArray();

        self::assertSame(['data' => ['echo' => 'hi']], $result);
    }

    public function testMutationIsAbsentWhenNotDefined(): void
    {
        $schema = SchemaBuilder::create()
            ->query(['noop' => ['type' => Type::string(), 'resolve' => fn (): string => 'ok']])
            ->build();

        self::assertNull($schema->getMutationType());
    }

    public function testBuilderIsImmutable(): void
    {
        $base = SchemaBuilder::create();
        $withQuery = $base->query(['a' => ['type' => Type::string(), 'resolve' => fn (): string => '']]);

        // Adding a query field to $withQuery must not affect $base
        $this->expectException(SchemaException::class);
        $base->build();
    }
}
