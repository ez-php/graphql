<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use EzPhp\DataLoader\DataLoader;

/**
 * Class DataLoaderRegistry
 *
 * Keyed registry of `EzPhp\DataLoader\DataLoader` instances, created fresh
 * once per GraphQL request and shared across every resolver that runs
 * during it — so two resolvers batching against the same key (e.g. a
 * `post.author` field and a `comment.author` field both loading `User`
 * rows) collapse into one batch call instead of each maintaining its own
 * loader and re-querying independently.
 *
 * This lives here, not in `ez-php/dataloader`, per that module's own
 * documented boundary: `DataLoader` itself is a generic, framework-agnostic
 * primitive, while resolver-layer wiring — a registry keyed by
 * resolver/field, request-scoped lifecycle — is GraphQL-specific and
 * belongs with the GraphQL module. `ez-php/dataloader`'s contract is
 * untouched; this class only composes it.
 *
 * Not a container-managed singleton — construct one per request (e.g. as
 * part of the webonyx execution context passed to `GraphQLExecutor`) and
 * discard it afterward. A shared, long-lived instance would leak cached
 * values and pending keys across unrelated requests.
 *
 * @package EzPhp\GraphQL
 */
final class DataLoaderRegistry
{
    /** @var array<string, DataLoader> */
    private array $loaders = [];

    /**
     * Return the DataLoader registered under `$key`, creating it with
     * `$batchLoadFn` on first access. On every subsequent call for the same
     * key, the existing loader is returned and `$batchLoadFn` is ignored —
     * the first resolver to reach a key defines its batch function.
     *
     * @param string                                                  $key         Loader identifier (e.g. "users", "posts").
     * @param callable(list<int|string>): array<int|string, mixed>    $batchLoadFn Batch-load function for a new loader.
     * @param bool                                                    $useCache    Passed through to a newly created DataLoader.
     *
     * @return DataLoader
     */
    public function get(string $key, callable $batchLoadFn, bool $useCache = true): DataLoader
    {
        return $this->loaders[$key] ??= new DataLoader($batchLoadFn, $useCache);
    }

    /**
     * Returns whether a loader has already been created for `$key`.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->loaders[$key]);
    }

    /**
     * Dispatch every registered loader's pending batch. Call this after
     * resolver execution completes for a tick, mirroring how each
     * individual `DataLoader::dispatch()` would be called by hand.
     *
     * @return void
     */
    public function dispatchAll(): void
    {
        foreach ($this->loaders as $loader) {
            $loader->dispatch();
        }
    }

    /**
     * Discard every registered loader, releasing their caches and any
     * pending keys.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->loaders = [];
    }
}
