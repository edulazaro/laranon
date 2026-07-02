<?php

namespace EduLazaro\Laranon\Vault;

use EduLazaro\Laranon\Contracts\VaultStore;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Cache-backed store (whatever cache driver the app uses). Payloads are
 * encrypted with the app key and expire with the configured TTL, so a
 * finished conversation loses its reversal map automatically.
 */
class CacheVaultStore implements VaultStore
{
    public function __construct(
        protected Cache $cache,
        protected string $prefix = 'laranon:',
        protected ?int $ttl = null,
    ) {
    }

    public function get(string $key): ?array
    {
        $raw = $this->cache->get($this->prefix . $key);

        if (! is_string($raw)) {
            return null;
        }

        $payload = json_decode(Crypt::decryptString($raw), true);

        return is_array($payload) ? $payload : null;
    }

    public function put(string $key, array $payload, ?int $ttl = null): void
    {
        $this->cache->put(
            $this->prefix . $key,
            Crypt::encryptString(json_encode($payload)),
            $ttl ?? $this->ttl,
        );
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->prefix . $key);
    }
}
