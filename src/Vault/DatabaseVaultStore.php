<?php

namespace EduLazaro\Laranon\Vault;

use EduLazaro\Laranon\Contracts\VaultStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;

/**
 * Database-backed store for maps that must survive days (queued document
 * generation, audits). Payloads are encrypted with the app key.
 */
class DatabaseVaultStore implements VaultStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table = 'laranon_vaults',
        protected ?int $ttl = null,
    ) {
    }

    public function get(string $key): ?array
    {
        $row = $this->connection->table($this->table)->where('key', $key)->first();

        if (! $row) {
            return null;
        }

        if ($row->expires_at !== null && strtotime($row->expires_at) < time()) {
            $this->forget($key);

            return null;
        }

        $payload = json_decode(Crypt::decryptString($row->payload), true);

        return is_array($payload) ? $payload : null;
    }

    public function put(string $key, array $payload, ?int $ttl = null): void
    {
        $ttl ??= $this->ttl;

        $this->connection->table($this->table)->updateOrInsert(
            ['key' => $key],
            [
                'payload' => Crypt::encryptString(json_encode($payload)),
                'expires_at' => $ttl ? date('Y-m-d H:i:s', time() + $ttl) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function forget(string $key): void
    {
        $this->connection->table($this->table)->where('key', $key)->delete();
    }
}
