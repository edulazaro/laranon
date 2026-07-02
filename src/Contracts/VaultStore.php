<?php

namespace EduLazaro\Laranon\Contracts;

interface VaultStore
{
    /**
     * Retrieve a persisted token map payload for the given scope key.
     */
    public function get(string $key): ?array;

    /**
     * Persist a token map payload under the given scope key.
     */
    public function put(string $key, array $payload, ?int $ttl = null): void;

    /**
     * Remove a persisted token map. After this, replacements made under the
     * scope can no longer be reversed: pseudonymization becomes effective
     * anonymization.
     */
    public function forget(string $key): void;
}
