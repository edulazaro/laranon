<?php

namespace EduLazaro\Laranon;

use EduLazaro\Laranon\Console\ScanCommand;
use EduLazaro\Laranon\Contracts\VaultStore;
use EduLazaro\Laranon\Integrations\HttpMacro;
use EduLazaro\Laranon\Strategies\FakerStrategy;
use EduLazaro\Laranon\Strategies\RedactStrategy;
use EduLazaro\Laranon\Strategies\TokenStrategy;
use EduLazaro\Laranon\Vault\ArrayVaultStore;
use EduLazaro\Laranon\Vault\CacheVaultStore;
use EduLazaro\Laranon\Vault\DatabaseVaultStore;
use Illuminate\Support\ServiceProvider;

class LaranonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laranon.php', 'laranon');

        $this->app->singleton(VaultStore::class, function ($app) {
            $config = $app['config']['laranon.vault'] ?? [];

            return match ($config['store'] ?? 'cache') {
                'array' => new ArrayVaultStore(),
                'database' => new DatabaseVaultStore(
                    $app['db']->connection(),
                    $config['table'] ?? 'laranon_vaults',
                    $config['ttl'] ?? null,
                ),
                default => new CacheVaultStore(
                    $app['cache']->store(),
                    $config['cache_prefix'] ?? 'laranon:',
                    $config['ttl'] ?? null,
                ),
            };
        });

        $this->app->singleton(Anonymizer::class, function ($app) {
            $config = $app['config']['laranon'] ?? [];
            $format = $config['token_format'] ?? '«%s_%d»';

            return new Anonymizer(
                recognizers: $this->buildRecognizers($config),
                strategies: $this->buildStrategies($config),
                defaultStrategy: $config['strategy'] ?? 'token',
                vault: $app->make(VaultStore::class),
                ttl: $config['vault']['ttl'] ?? null,
                tokenOpen: mb_substr($format, 0, 1),
                tokenClose: mb_substr($format, -1),
            );
        });

        $this->app->alias(Anonymizer::class, 'laranon');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laranon.php' => config_path('laranon.php'),
        ], 'laranon-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_laranon_vaults_table.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_laranon_vaults_table.php',
            ),
        ], 'laranon-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class]);
        }

        HttpMacro::register();
    }

    protected function buildRecognizers(array $config): array
    {
        $recognizers = [];

        foreach ($config['universal'] ?? [] as $class) {
            $recognizers[] = $this->app->make($class);
        }

        foreach ($config['locales'] ?? [] as $locale) {
            $packClass = $config['packs'][$locale] ?? null;

            if (! $packClass) {
                continue;
            }

            $pack = new $packClass($config['data_path'] ?? null);

            $recognizers = array_merge($recognizers, $pack->recognizers());
        }

        return $recognizers;
    }

    protected function buildStrategies(array $config): array
    {
        $format = $config['token_format'] ?? '«%s_%d»';
        $labels = $config['labels'] ?? [];

        $strategies = [];

        foreach ($config['strategies'] ?? [] as $name => $class) {
            $strategies[$name] = match (true) {
                is_a($class, TokenStrategy::class, true) => new $class($format, $labels),
                is_a($class, FakerStrategy::class, true) => new $class($format, $labels),
                is_a($class, RedactStrategy::class, true) => new $class($labels),
                default => $this->app->make($class),
            };
        }

        return $strategies;
    }
}
