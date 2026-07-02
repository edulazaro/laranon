<?php

namespace EduLazaro\Laranon\Vault;

use EduLazaro\Laranon\Contracts\VaultStore;

/**
 * In-memory store: maps live only for the current request. The default when
 * anonymize/deanonymize happen within the same call and nothing must persist.
 */
class ArrayVaultStore implements VaultStore
{
    /** @var array<string, array> */
    protected array $items = [];

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, array $payload, ?int $ttl = null): void
    {
        $this->items[$key] = $payload;
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }
}
