<?php

namespace Platform\Inbox;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use Platform\Inbox\Console\Commands\IngestInboxCommand;
use Platform\Core\Inbox\Models\InboxItem;
use Platform\Core\Inbox\Models\InboxSenderSubscription;
use Platform\Core\Inbox\Services\ChannelRouter;
use Platform\Core\Inbox\Services\Enrichment\EnrichmentProviderRegistry;
use Platform\Core\Inbox\Services\Enrichment\GatewayEnrichmentProvider;

class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inbox.php', 'inbox');

        $this->app->singleton(ChannelRouter::class);

        // Cross-module InboxItem relations. Modules (Whisper, future
        // call/mail producers) resolve the contract and call link()/
        // supplements() without touching the Inbox schema directly.
        $this->app->bind(
            \Platform\Core\Inbox\Contracts\InboxItemLinkContract::class,
            \Platform\Core\Inbox\Services\InboxItemLinkService::class
        );

        // Link-Naht des Kerns: der UnitResolver fragt hierüber, welche Items via
        // Link zur selben Einheit gehören. Überschreibt Cores NullItemRelations.
        $this->app->bind(
            \Platform\Core\Inbox\Contracts\ItemRelations::class,
            \Platform\Core\Inbox\Services\ItemRelationsService::class
        );

        // Handoff-Naht des Kerns: Item → Aufgabe/Ticket. Überschreibt NullItemHandoff.
        $this->app->bind(
            \Platform\Core\Inbox\Contracts\ItemHandoff::class,
            \Platform\Core\Inbox\Services\ItemHandoffService::class
        );

        // Zuordnungs-Naht des Kerns: manuelle Aufnahme→Termin-Zuordnung.
        $this->app->bind(
            \Platform\Core\Inbox\Contracts\ItemLinking::class,
            \Platform\Core\Inbox\Services\ItemLinkingService::class
        );

        // Aufnahme-Ingestion-Naht: Produzenten (whisper) liefern über Core, die
        // Materialisierung bleibt hier (InboxAudioIngestionService).
        $this->app->bind(
            \Platform\Core\Inbox\Contracts\RecordingIngestion::class,
            \Platform\Core\Inbox\Services\RecordingIngestionService::class
        );

        // Query-Kontrakt Meeting-Kanal: home rendert damit den Reading-Pane.
        $this->app->bind(
            \Platform\Core\Inbox\Contracts\InboxMeetingQueryContract::class,
            \Platform\Core\Inbox\Services\InboxMeetingQueryService::class
        );

        $this->app->bind(
            \Platform\Core\Inbox\Contracts\InboxMailQueryContract::class,
            \Platform\Core\Inbox\Services\InboxMailQueryService::class
        );

        $this->app->bind(
            \Platform\Core\Inbox\Contracts\InboxCallQueryContract::class,
            \Platform\Core\Inbox\Services\InboxCallQueryService::class
        );

        $this->app->bind(
            \Platform\Core\Inbox\Contracts\InboxTaskQueryContract::class,
            \Platform\Core\Inbox\Services\InboxTaskQueryService::class
        );

        $this->app->bind(
            \Platform\Core\Inbox\Contracts\InboxTicketQueryContract::class,
            \Platform\Core\Inbox\Services\InboxTicketQueryService::class
        );

        $this->app->singleton(EnrichmentProviderRegistry::class, function ($app) {
            $registry = new EnrichmentProviderRegistry();
            // EIN anbieter-agnostischer Provider. Er routet über die zentrale
            // Core\LLMProviderRegistry anhand von template.preferred_provider
            // ("claude:…" / "openai:…" / "local:…"). Neues Backend = Core-Provider
            // registrieren — hier nichts ändern. (Früher: je ein Enrichment-Provider
            // pro Anbieter mit dupliziertem Prompt-/Parse-/Kosten-Code.)
            $registry->register(new GatewayEnrichmentProvider(), asDefault: true);
            return $registry;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                IngestInboxCommand::class,
                \Platform\Inbox\Console\Commands\BackfillInboxThreadKeys::class,
                \Platform\Inbox\Console\Commands\RecomputeInboxScores::class,
                \Platform\Inbox\Console\Commands\ReenrichInboxItems::class,
                \Platform\Inbox\Console\Commands\AutoClosePastItems::class,
                \Platform\Inbox\Console\Commands\MaterializeMeetingTimeCommand::class,
                \Platform\Inbox\Console\Commands\MigrateMeetingTimeToItemCommand::class,
                \Platform\Inbox\Console\Commands\BackfillMeetingSeriesCommand::class,
                \Platform\Inbox\Console\Commands\CorrelateRecordingsCommand::class,
                \Platform\Inbox\Console\Commands\InheritNodeLinksCommand::class,
                \Platform\Inbox\Console\Commands\BackfillIdentityCommand::class,
            ]);

            // Schedule registration mirrors what datawarehouse does and what
            // actually fires in production: gate on runningInConsole() so the
            // schedule:run boot is the only path that adds events, and use
            // app->make(Schedule::class) so the binding is created if missing.
            // The earlier outside-of-console + bound() guard variant looked
            // registered in HTTP probes (MCP diagnose saw 1 event) but never
            // landed in the schedule:run process, so cron silently did nothing.
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('inbox:ingest --minutes=60')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
                $schedule->command('inbox:recompute-scores --since=24')
                    ->hourly()
                    ->withoutOverlapping();
                $schedule->command('inbox:auto-close')
                    ->hourly()
                    ->withoutOverlapping();
                // Abends, vor dem Organization-Evening-Snapshot (18:00), damit die
                // heute stattgefundenen Termine noch in den Abend-Snapshot einfließen.
                $schedule->command('inbox:materialize-meeting-time')
                    ->dailyAt('17:45')
                    ->withoutOverlapping();
                // Aufnahmen ans Meeting hängen — fängt Out-of-Order-Ankünfte ab.
                $schedule->command('inbox:correlate-recordings')
                    ->everyFifteenMinutes()
                    ->withoutOverlapping();
                // Knoten-Links über Thread/Serie vererben — neue Mails erben den Knoten.
                $schedule->command('inbox:inherit-node-links')
                    ->everyTenMinutes()
                    ->withoutOverlapping();
                // Gruppen-Identität (conversation_id/ical_uid) von Sessions auf Items
                // nachziehen — Altdaten-Catch-up, idempotent.
                $schedule->command('inbox:backfill-identity')
                    ->hourly()
                    ->withoutOverlapping();
            });
        }
    }

    public function boot(): void
    {
        $morphMap = [
            'inbox_item' => InboxItem::class,
            'inbox_sender_subscription' => InboxSenderSubscription::class,
        ];

        // source_type der Meeting-Items ist der Alias 'user_connector_meeting_session'
        // (bei der Ingestion gesetzt). Ist die Morph-Map erzwungen, muss der Alias
        // aufgelöst werden können, sonst wirft $item->source (morphTo) beim Laden
        // "Class user_connector_meeting_session not found". Der Alias gehört dem
        // UserConnectors-Modul; wir registrieren ihn defensiv mit, wo die Klasse da
        // ist (soft-coupled — fehlt das Modul, bleibt der Eintrag weg).
        if (class_exists(\Platform\UserConnectors\Models\UserConnectorMeetingSession::class)) {
            $morphMap['user_connector_meeting_session'] = \Platform\UserConnectors\Models\UserConnectorMeetingSession::class;
        }

        Relation::morphMap($morphMap);

        // Am Knoten verlinkte Inbox-Items als Zeit-Kontext registrieren, damit
        // gebuchte Meeting-Zeit (MaterializeMeetingTimeCommand) in die Ist-Zeiten
        // des Knotens aufläuft. Soft-gekoppelt: ohne Organization-Modul No-op.
        try {
            if (class_exists(\Platform\Organization\Services\EntityLinkRegistry::class)) {
                resolve(\Platform\Organization\Services\EntityLinkRegistry::class)
                    ->register(new \Platform\Inbox\Organization\InboxEntityLinkProvider());
            }
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen — Meeting-Zeit aggregiert dann nicht.
        }

        // Aufnahme-Detail (Transkript-Segmente + Dauer) für den Core-Stream.
        // Core\StreamQuery::detailForItem merged das für channel='recording', damit
        // der Reading-Pane (z.B. in home) die Segmente bekommt. Soft: ohne Core-
        // Registry ein No-op.
        try {
            if (class_exists(\Platform\Core\Inbox\Support\SourceDetailRegistry::class)) {
                $sourceDetails = resolve(\Platform\Core\Inbox\Support\SourceDetailRegistry::class);
                $sourceDetails->register(new \Platform\Core\Inbox\Services\RecordingSourceDetail());

                // Detail-Naht der übrigen Kanäle über Adapter an die bestehenden
                // QueryServices — home liest damit JEDES Kanal-Detail über die EINE
                // Core-InboxQuery, nicht mehr über 5 Inbox*QueryContracts.
                $channelContracts = [
                    'meeting' => \Platform\Core\Inbox\Contracts\InboxMeetingQueryContract::class,
                    'mail'    => \Platform\Core\Inbox\Contracts\InboxMailQueryContract::class,
                    'call'    => \Platform\Core\Inbox\Contracts\InboxCallQueryContract::class,
                    'task'    => \Platform\Core\Inbox\Contracts\InboxTaskQueryContract::class,
                    'ticket'  => \Platform\Core\Inbox\Contracts\InboxTicketQueryContract::class,
                ];
                foreach ($channelContracts as $channel => $contract) {
                    $sourceDetails->register(new \Platform\Core\Inbox\Services\DelegatingSourceDetail($channel, $contract));
                }

                // Listen-Naht dito: ein konsolidierter Stream über die Core-InboxQuery.
                if (class_exists(\Platform\Core\Inbox\Support\SourceListRegistry::class)) {
                    $sourceLists = resolve(\Platform\Core\Inbox\Support\SourceListRegistry::class);
                    foreach ($channelContracts as $channel => $contract) {
                        $sourceLists->register(new \Platform\Core\Inbox\Services\DelegatingSourceList($channel, $contract));
                    }
                }
            }

            // LinkRule-Fabrik: Aufnahme↔Meeting als pluggbare Regel registrieren.
            if (class_exists(\Platform\Core\Inbox\Support\LinkRuleRegistry::class)) {
                resolve(\Platform\Core\Inbox\Support\LinkRuleRegistry::class)
                    ->register(new \Platform\Inbox\LinkRules\RecordingMeetingLinkRule());
            }
        } catch (\Throwable $e) {
            // Core-Inbox-Engine nicht geladen.
        }

        // inbox ist headless: keine eigene UI/Navigation mehr — der Eingang lebt in
        // home. Das Modul bleibt NUR registriert, damit die Bearer-Auth-API
        // weiterläuft (ModuleRouter::apiGroup wirft ohne registriertes Modul).
        // Keine navigation/sidebar → kein Menüeintrag; home blendet inbox zusätzlich
        // explizit aus der Modul-Leiste aus.
        if (
            config()->has('inbox.routing') &&
            config()->has('inbox.navigation') &&
            PlatformCore::modulesTableExists()
        ) {
            PlatformCore::registerModule([
                'key'     => 'inbox',
                'title'   => 'Inbox',
                'group'   => 'productivity',
                'routing' => config('inbox.routing'),
                'guard'   => config('inbox.guard'),
            ]);
        }

        if (PlatformCore::getModule('inbox')) {
            // Nur die Bearer-Auth-API: /api/inbox/... — der KyberOS-Mac-Client fragt
            // vor einer Aufnahme das laufende Meeting ab. Keine Web-/UI-Routen mehr.
            ModuleRouter::apiGroup('inbox', function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
            });
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/inbox.php' => config_path('inbox.php'),
        ], 'config');

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

}
