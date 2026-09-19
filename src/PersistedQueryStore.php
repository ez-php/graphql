<?php

declare(strict_types=1);

namespace EzPhp\GraphQL;

use EzPhp\Cache\CacheInterface;

/**
 * Class PersistedQueryStore
 *
 * Cache-backed store for Automatic Persisted Queries (APQ): a client sends the
 * SHA-256 hash of a query instead of the query text; the first request that
 * carries both hash and text registers the query, later ones can send the hash
 * alone.
 *
 * The hash is verified against the text before anything is stored, so a client
 * cannot poison the entry another client will later look up. Queries above
 * `MAX_QUERY_BYTES` are never stored, which bounds what an anonymous client can
 * put into the cache.
 *
 * `ez-php/cache` is a soft dependency (`require-dev` + `suggest`): this class is
 * only autoloaded when persisted queries are enabled.
 *
 * @package EzPhp\GraphQL
 */
final class PersistedQueryStore
{
    /**
     * Largest query text that will be stored (bytes).
     */
    public const int MAX_QUERY_BYTES = 65536;

    private const string KEY_PREFIX = 'graphql:apq:';

    /**
     * PersistedQueryStore Constructor
     *
     * @param CacheInterface $cache
     * @param int            $ttl   Seconds an entry lives; 0 = no expiry.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttl = 0,
    ) {
    }

    /**
     * Whether a string looks like a SHA-256 hex digest.
     *
     * @param string $hash
     *
     * @return bool
     */
    public static function isValidHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }

    /**
     * Whether the hash is the SHA-256 of the query text.
     *
     * @param string $hash
     * @param string $query
     *
     * @return bool
     */
    public static function matches(string $hash, string $query): bool
    {
        return self::isValidHash($hash) && hash_equals($hash, hash('sha256', $query));
    }

    /**
     * Look up the query text for a hash.
     *
     * @param string $hash
     *
     * @return string|null Null when unknown (or the hash is malformed).
     */
    public function find(string $hash): ?string
    {
        if (!self::isValidHash($hash)) {
            return null;
        }

        $query = $this->cache->get(self::KEY_PREFIX . $hash);

        return is_string($query) ? $query : null;
    }

    /**
     * Register a query under its hash.
     *
     * @param string $hash  Lowercase SHA-256 hex digest the client claims for $query.
     * @param string $query
     *
     * @return bool False (nothing stored) when the hash does not match the text or the text is too large
     *              to store — the caller may still execute such a query.
     */
    public function register(string $hash, string $query): bool
    {
        if (strlen($query) > self::MAX_QUERY_BYTES || !self::matches($hash, $query)) {
            return false;
        }

        $this->cache->set(self::KEY_PREFIX . $hash, $query, $this->ttl);

        return true;
    }
}
