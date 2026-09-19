<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\DataLoader\DataLoader;
use EzPhp\GraphQL\DataLoaderRegistry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class DataLoaderRegistryTest
 *
 * @package Tests
 */
#[CoversClass(DataLoaderRegistry::class)]
final class DataLoaderRegistryTest extends TestCase
{
    public function testGetCreatesADataLoaderForANewKey(): void
    {
        $registry = new DataLoaderRegistry();

        $loader = $registry->get('users', static fn (array $keys): array => array_combine($keys, $keys));

        $this->assertInstanceOf(DataLoader::class, $loader);
    }

    public function testGetReturnsTheSameLoaderInstanceForTheSameKey(): void
    {
        // Simulates two resolvers (e.g. `post.author` and `comment.author`)
        // both requesting the "users" loader — they must share one DataLoader
        // so their pending keys are batched into a single load, not one per resolver.
        $registry = new DataLoaderRegistry();
        $batchFn = static fn (array $keys): array => array_combine($keys, $keys);

        $fromResolverA = $registry->get('users', $batchFn);
        $fromResolverB = $registry->get('users', $batchFn);

        $this->assertSame($fromResolverA, $fromResolverB);
    }

    public function testGetIgnoresTheBatchFunctionOnASubsequentCallForTheSameKey(): void
    {
        $registry = new DataLoaderRegistry();
        $calls = 0;

        $registry->get('users', static function (array $keys) use (&$calls): array {
            $calls++;

            return array_combine($keys, $keys);
        });

        $loader = $registry->get('users', static function (array $keys) use (&$calls): array {
            $calls++;

            return array_combine($keys, $keys);
        });

        $loader->load('1');
        $loader->dispatch();

        $this->assertSame(1, $calls);
    }

    public function testDifferentKeysProduceDifferentLoaders(): void
    {
        $registry = new DataLoaderRegistry();
        $batchFn = static fn (array $keys): array => array_combine($keys, $keys);

        $users = $registry->get('users', $batchFn);
        $posts = $registry->get('posts', $batchFn);

        $this->assertNotSame($users, $posts);
    }

    public function testHasReturnsFalseForAnUnknownKey(): void
    {
        $registry = new DataLoaderRegistry();

        $this->assertFalse($registry->has('users'));
    }

    public function testHasReturnsTrueAfterGet(): void
    {
        $registry = new DataLoaderRegistry();
        $registry->get('users', static fn (array $keys): array => array_combine($keys, $keys));

        $this->assertTrue($registry->has('users'));
    }

    public function testDispatchAllDispatchesEveryRegisteredLoader(): void
    {
        // Two independent loaders (e.g. "users" and "posts"), each with its own
        // pending key, both resolved by one dispatchAll() call.
        $registry = new DataLoaderRegistry();
        $usersLoaded = [];
        $postsLoaded = [];

        $users = $registry->get('users', static function (array $keys) use (&$usersLoaded): array {
            $usersLoaded = $keys;

            return array_combine($keys, $keys);
        });
        $posts = $registry->get('posts', static function (array $keys) use (&$postsLoaded): array {
            $postsLoaded = $keys;

            return array_combine($keys, $keys);
        });

        $users->load('u1');
        $posts->load('p1');
        $registry->dispatchAll();

        $this->assertSame(['u1'], $usersLoaded);
        $this->assertSame(['p1'], $postsLoaded);
    }

    public function testClearRemovesAllLoaders(): void
    {
        $registry = new DataLoaderRegistry();
        $registry->get('users', static fn (array $keys): array => array_combine($keys, $keys));

        $registry->clear();

        $this->assertFalse($registry->has('users'));
    }
}
