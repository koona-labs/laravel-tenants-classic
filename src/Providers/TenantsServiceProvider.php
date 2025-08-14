<?php

declare(strict_types=1);

namespace Rinvex\Tenants\Providers;

use Exception;
use Illuminate\Support\Str;
use Rinvex\Tenants\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Rinvex\Tenants\Console\Commands\MigrateCommand;
use Rinvex\Tenants\Console\Commands\PublishCommand;
use Illuminate\Database\Eloquent\Relations\Relation;
use Rinvex\Tenants\Console\Commands\RollbackCommand;

class TenantsServiceProvider extends ServiceProvider
{

    /**
     * The commands to be registered.
     *
     * @var array
     */
    protected $commands = [
        MigrateCommand::class,
        PublishCommand::class,
        RollbackCommand::class,
    ];

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(realpath(__DIR__.'/../../config/config.php'), 'rinvex.tenants');

        // Bind eloquent models to IoC container
        $this->registerModels([
            'rinvex.tenants.tenant' => Tenant::class,
        ]);

        // Register console commands
        $this->commands($this->commands);
    }

    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        // Register paths to be published by the publish command.
        $this->publishConfigFrom(realpath(__DIR__.'/../../config/config.php'), 'rinvex/tenants');
        $this->publishMigrationsFrom(realpath(__DIR__.'/../../database/migrations'), 'rinvex/tenants');

        ! $this->app['config']['rinvex.tenants.autoload_migrations'] || $this->loadMigrationsFrom(realpath(__DIR__.'/../../database/migrations'));

        // Resolve active tenant
        $this->resolveActiveTenant();

        // Map relations
        Relation::morphMap([
            'tenant' => config('rinvex.tenants.models.tenant'),
        ]);
    }

    /**
     * Resolve active tenant.
     *
     * @return void
     */
    protected function resolveActiveTenant()
    {
        $tenant = null;

        try {
            // Just check if we have DB connection! This is to avoid
            // exceptions on new projects before configuring database options
            DB::connection()->getPdo();

            if (! array_key_exists($this->app['request']->getHost(), config('app.domains')) && Schema::hasTable(config('rinvex.tenants.tables.tenants'))) {
                $tenant = config('rinvex.tenants.resolver')::resolve();
            }
        } catch (Exception $e) {
            // Be quiet! Do not do or say anything!!
        }

        // Resolve and register tenant into service container
        $this->app->singleton('request.tenant', fn () => $tenant);
    }



    /**
     * Register migration paths to be published by the publish command.
     *
     * @param string $path
     * @param string $namespace
     *
     * @return void
     */
    protected function publishMigrationsFrom(string $path, string $namespace): void
    {
        if (file_exists($path)) {
            $stubs = $this->app['files']->glob($path.'/*.php');
            $existing = $this->app['files']->glob($this->app->databasePath('migrations/'.$namespace.'/*.php'));

            $migrations = collect($stubs)->flatMap(function ($migration) use ($existing, $namespace) {
                $sequence = mb_substr(basename($migration), 0, 17);
                $match = collect($existing)->first(fn ($item, $key) => mb_strpos($item, str_replace($sequence, '', basename($migration))) !== false);

                return [$migration => $this->app->databasePath('migrations/'.$namespace.'/'.($match ? basename($match) : date('Y_m_d_His', time() + mb_substr($sequence, -6)).str_replace($sequence, '', basename($migration))))];
            })->toArray();

            $this->publishes($migrations, $namespace.'::migrations');
        }
    }

    /**
     * Register config paths to be published by the publish command.
     *
     * @param string $path
     * @param string $namespace
     *
     * @return void
     */
    protected function publishConfigFrom(string $path, string $namespace): void
    {
        ! file_exists($path) || $this->publishes([$path => $this->app->configPath(str_replace('/', '.', $namespace).'.php')], $namespace.'::config');
    }


    /**
     * Register models into IoC.
     *
     * @param array $models
     *
     * @return void
     */
    protected function registerModels(array $models): void
    {
        foreach ($models as $service => $class) {
            $this->app->singletonIf($service, $model = $this->app['config'][Str::replaceLast('.', '.models.', $service)]);
            $model === $class || $this->app->alias($service, $class);
            $this->app->singletonIf($model, $model);
        }
    }

}
