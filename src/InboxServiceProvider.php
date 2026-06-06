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
use Platform\Inbox\Services\ChannelRouter;
use Platform\Inbox\Services\Enrichment\EnrichmentProviderRegistry;
use Platform\Inbox\Services\Enrichment\OpenAiEnrichmentProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inbox.php', 'inbox');

        $this->app->singleton(ChannelRouter::class);

        $this->app->singleton(EnrichmentProviderRegistry::class, function ($app) {
            $registry = new EnrichmentProviderRegistry();
            // Default provider — registered as default fallback.
            try {
                if (class_exists(\Platform\Core\Services\OpenAiService::class)) {
                    $registry->register(new OpenAiEnrichmentProvider(), asDefault: true);
                }
            } catch (\Throwable $e) {
                // OpenAiService not available — registry stays empty; jobs will
                // record FAILED status instead of crashing.
            }
            return $registry;
        });

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

        $this->registerDefaultChannelHandlers();
    }

    /**
     * Register the default (channel, connector_key) -> send-tool handlers.
     * Other modules can register more via the same ChannelRouter — this is
     * a sensible default set so the inbox compose works out of the box.
     */
    protected function registerDefaultChannelHandlers(): void
    {
        try {
            $router = $this->app->make(ChannelRouter::class);
        } catch (\Throwable $e) {
            return;
        }

        // mail / microsoft365 — reply uses from_address as recipient.
        $router->register(
            channel: 'mail',
            connectorKey: 'microsoft365',
            toolName: 'user-connectors.microsoft365.mail.send',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'to' => $session->from_address ?? '',
                    'subject' => $subject !== '' ? $subject : 'Re: ' . ($session->subject ?? ''),
                    'body' => $body,
                ];
            },
            label: 'E-Mail via Outlook',
        );

        // message (SMS) / sipgate
        $router->register(
            channel: 'message',
            connectorKey: 'sipgate',
            toolName: 'user-connectors.sipgate.sms.send',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'to' => $this->recipientForMessage($session),
                    'body' => $body,
                ];
            },
            label: 'SMS via Sipgate',
        );

        // message (SMS) / ringcentral
        $router->register(
            channel: 'message',
            connectorKey: 'ringcentral',
            toolName: 'user-connectors.ringcentral.sms.send',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'to' => $this->recipientForMessage($session),
                    'body' => $body,
                ];
            },
            label: 'SMS via RingCentral',
        );

        // call / sipgate — callback: ignore subject + body, just initiate.
        $router->register(
            channel: 'call',
            connectorKey: 'sipgate',
            toolName: 'user-connectors.sipgate.calls.initiate',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'to' => $this->recipientForCall($session),
                ];
            },
            label: 'Rückruf via Sipgate',
        );

        // call / ringcentral — callback
        $router->register(
            channel: 'call',
            connectorKey: 'ringcentral',
            toolName: 'user-connectors.ringcentral.calls.initiate',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'to' => $this->recipientForCall($session),
                ];
            },
            label: 'Rückruf via RingCentral',
        );
    }

    protected function recipientForMessage(object $session): string
    {
        $direction = $session->direction ?? 'inbound';
        return $direction === 'inbound'
            ? ($session->from_identifier ?? '')
            : ($session->to_identifier ?? '');
    }

    protected function recipientForCall(object $session): string
    {
        $direction = $session->direction ?? 'inbound';
        return $direction === 'inbound'
            ? ($session->from_number ?? '')
            : ($session->to_number ?? '');
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
