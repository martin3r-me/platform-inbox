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
use Platform\Inbox\Services\Enrichment\ClaudeEnrichmentProvider;
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
            try {
                if (class_exists(\Platform\Core\Services\OpenAiService::class)) {
                    $registry->register(new OpenAiEnrichmentProvider(), asDefault: true);
                }
            } catch (\Throwable $e) {
                // OpenAiService not available — registry stays empty; jobs will
                // record FAILED status instead of crashing.
            }
            // Claude provider — registered when the Anthropic key exists. Lets
            // templates with preferred_provider="claude:…" resolve and adds a
            // second option for the show-view re-run picker.
            if ((string) config('ai.anthropic.api_key', '') !== '') {
                $registry->register(new ClaudeEnrichmentProvider());
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

        // Schedule via $app->booted() registrieren statt afterResolving —
        // afterResolving feuert nur bei NEUEN Resolves; wenn ein anderer
        // Provider den Schedule früher aufgelöst hat (z. B. ConsoleSupport),
        // verpasst unsere Callback den Train und der Cron-Eintrag fehlt
        // stillschweigend. booted() läuft nach allen Provider-Boots und
        // greift das real existierende Schedule-Singleton ab. Im HTTP-
        // Kontext ist das harmlos (der Event landet im Container, wird
        // aber nie ausgeführt — schedule:run kommt nur aus dem Cron).
        $this->app->booted(function () {
            if (!$this->app->bound(Schedule::class)) {
                return;
            }
            try {
                $this->app->make(Schedule::class)
                    ->command('inbox:ingest --minutes=60')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            } catch (\Throwable $e) {
                \Log::warning('Inbox: schedule registration failed', ['error' => $e->getMessage()]);
            }
        });

        $this->registerDefaultChannelHandlers();
        $this->registerTools();
    }

    /**
     * Source-adapter MCP tools — receive vendor payloads (Plaud today,
     * future Twilio voicemail / Zoom recordings / …) and route them
     * through InboxAudioIngestionService. Each adapter stays a thin
     * shim: parse, normalise, hand off.
     */
    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);
            $registry->register(new \Platform\Inbox\Sources\Plaud\SyncTool());
            $registry->register(new \Platform\Inbox\Tools\Templates\ListTemplatesTool());
            $registry->register(new \Platform\Inbox\Tools\Templates\GetTemplateTool());
            $registry->register(new \Platform\Inbox\Tools\Templates\UpsertTemplateTool());
            $registry->register(new \Platform\Inbox\Tools\BackfillInboxTool());
            $registry->register(new \Platform\Inbox\Tools\InboxCountsByChannelTool());
            $registry->register(new \Platform\Inbox\Tools\SchedulerDiagnoseTool());
            $registry->register(new \Platform\Inbox\Tools\SchedulerUnlockTool());
            $registry->register(new \Platform\Inbox\Tools\Items\ListItemsTool());
            $registry->register(new \Platform\Inbox\Tools\Items\ShowItemTool());
            $registry->register(new \Platform\Inbox\Tools\Items\TriageItemsTool());
            $registry->register(new \Platform\Inbox\Tools\Items\SenderControlTool());
            $registry->register(new \Platform\Inbox\Tools\Items\LinkEntityTool());
            $registry->register(new \Platform\Inbox\Tools\Items\UnlinkEntityTool());
            $registry->register(new \Platform\Inbox\Tools\Items\HandoffTool());
            $registry->register(new \Platform\Inbox\Tools\Items\ReplyItemTool());
            $registry->register(new \Platform\Inbox\Tools\Items\ForwardItemTool());
            $registry->register(new \Platform\Inbox\Tools\Items\RespondEventTool());
            $registry->register(new \Platform\Inbox\Tools\Items\ImportOutlookMailTool());
            $registry->register(new \Platform\Inbox\Tools\Items\ReprocessItemTool());
        } catch (\Throwable $e) {
            \Log::warning('Inbox: tool registration failed', ['error' => $e->getMessage()]);
        }
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

        // mail / microsoft365 — native Graph /reply auf der external_mail_id,
        // damit Thread + Conversation-ID erhalten bleiben (statt fresh send).
        $router->register(
            channel: 'mail',
            connectorKey: 'microsoft365',
            toolName: 'user-connectors.microsoft365.mail.reply',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'external_mail_id' => (string) ($session->external_mail_id ?? ''),
                    'body' => $body,
                ];
            },
            label: 'E-Mail via Outlook',
        );

        // message / microsoft365 — Teams chat reply via user-connectors
        // (eigene Connection + OAuth-Token, kein Umweg über core).
        // Channel-Posts in Teams-Channels brauchten team_id + channel_id,
        // die in der message_session nicht vorliegen → chat-only.
        $router->register(
            channel: 'message',
            connectorKey: 'microsoft365',
            toolName: 'user-connectors.microsoft365.teams.send',
            argBuilder: function ($item, $session, $connection, $subject, $body) {
                return [
                    'connection_id' => (int) $session->connection_id,
                    'chat_id' => (string) ($session->chat_id ?? ''),
                    'body' => $body,
                    'content_type' => 'text',
                ];
            },
            label: 'Teams-Chat-Antwort',
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
