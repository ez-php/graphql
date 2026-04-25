<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;

/**
 * Class SchemaBuilder
 *
 * Fluent builder for webonyx GraphQL schemas.
 *
 * Usage:
 *
 *   use GraphQL\Type\Definition\Type;
 *
 *   $schema = SchemaBuilder::create()
 *       ->query([
 *           'hello' => ['type' => Type::string(), 'resolve' => fn() => 'world'],
 *       ])
 *       ->mutation([
 *           'echo' => [
 *               'type' => Type::string(),
 *               'args' => ['message' => ['type' => Type::nonNull(Type::string())]],
 *               'resolve' => fn($root, array $args): string => $args['message'],
 *           ],
 *       ])
 *       ->build();
 *
 * For advanced schemas, construct webonyx's Schema directly and bind it in
 * your service provider without using this builder.
 *
 * @package EzPhp\GraphQL
 */
final class SchemaBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $queryFields = null;

    /** @var array<string, mixed>|null */
    private ?array $mutationFields = null;

    /**
     * Create a new builder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Define the Query type fields.
     *
     * @param array<string, mixed> $fields Field definitions accepted by webonyx ObjectType.
     */
    public function query(array $fields): self
    {
        $clone = clone $this;
        $clone->queryFields = $fields;

        return $clone;
    }

    /**
     * Define the Mutation type fields.
     *
     * @param array<string, mixed> $fields Field definitions accepted by webonyx ObjectType.
     */
    public function mutation(array $fields): self
    {
        $clone = clone $this;
        $clone->mutationFields = $fields;

        return $clone;
    }

    /**
     * Build and return the webonyx Schema.
     *
     * @throws SchemaException When no query fields have been defined.
     */
    public function build(): Schema
    {
        if ($this->queryFields === null) {
            throw new SchemaException('A Query type with at least one field is required to build a schema.');
        }

        $config = SchemaConfig::create();

        $config->setQuery(new ObjectType([
            'name' => 'Query',
            'fields' => $this->queryFields,
        ]));

        if ($this->mutationFields !== null) {
            $config->setMutation(new ObjectType([
                'name' => 'Mutation',
                'fields' => $this->mutationFields,
            ]));
        }

        return new Schema($config);
    }
}
