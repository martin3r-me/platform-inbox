<?php

namespace Platform\Inbox;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use Platform\Inbox\Console\Commands\IngestInboxCommand;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxSenderSubscription;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inbox.php', 'inbox');

        if ($this->app->runningInConsole()) {
            $this->commands([
                IngestInboxCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Relation::morphMap([
            'inbox_item' => InboxItem::class,
            'inbox_sender_subscription' => InboxSenderSubscription::class,
        ]);

        if (
            config()->has('inbox.routing') &&
            config()->has('inbox.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key'        => 'inbox',
                'title'      => 'Inbox',
                'group'      => 'productivity',
                'routing'    => config('inbox.routing'),
                'guard'      => config('inbox.guard'),
                'navigation' => config('inbox.navigation'),
                'sidebar'    => config('inbox.sidebar'),
            ]);
        }

        if (PlatformCore::getModule('inbox')) {
            ModuleRouter::group('inbox', function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            });
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'inbox');
        $this->registerLivewireComponents();

        $this->publishes([
            __DIR__ . '/../config/inbox.php' => config_path('inbox.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('inbox:ingest --minutes=60')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            });
        }
    }

    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Inbox\\Livewire';
        $prefix = 'inbox';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            $alias = $prefix . '.' . $aliasPath;

            Livewire::component($alias, $class);
        }
    }
}
