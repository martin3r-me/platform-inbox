<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;

/**
 * Sends a reply for an InboxItem by resolving the underlying
 * user-connectors session + connection and dispatching the configured
 * handler tool. Returns a normalized result:
 *
 *   ['ok' => true,  'message' => 'Mail gesendet.', 'data' => [...]]
 *   ['ok' => false, 'message' => 'Kein Handler für mail/teams.', 'data' => null]
 */
class InboxSendService
{
    public function __construct(protected ChannelRouter $router) {}

    public function sendReply(
        InboxItem $item,
        string $subject,
        string $body,
        ?\Platform\Core\Models\User $actor = null,
    ): array {
        if (!Schema::hasTable('user_connector_connections')) {
            return $this->fail('User-Connectors-Modul nicht installiert.');
        }

        $sessionTable = $this->sessionTableForMorph($item->source_type);
        if ($sessionTable === null || !Schema::hasTable($sessionTable)) {
            return $this->fail("Quell-Tabelle für '{$item->source_type}' nicht gefunden.");
        }

        $session = DB::table($sessionTable)->where('id', $item->source_id)->first();
        if (!$session) {
            return $this->fail('Original-Session nicht mehr vorhanden.');
        }

        $connection = DB::table('user_connector_connections')
            ->where('id', $session->connection_id ?? 0)
            ->first();
        if (!$connection) {
            return $this->fail('Verbindung nicht mehr vorhanden.');
        }

        $connectorKey = DB::table('user_connectors')
            ->where('id', $connection->connector_id)
            ->value('key');
        if (!$connectorKey) {
            return $this->fail('Connector unbekannt.');
        }

        $channel = $item->channel?->value ?? '';
        $handler = $this->router->find($channel, $connectorKey);
        if (!$handler) {
            return $this->fail("Kein Versand-Handler für {$channel} / {$connectorKey}.");
        }

        $args = ($handler['args'])($item, $session, $connection, $subject, $body);

        $tool = $this->resolveTool($handler['tool']);
        if (!$tool) {
            return $this->fail("Send-Tool '{$handler['tool']}' nicht registriert.");
        }

        try {
            $context = $this->buildContext($actor ?? $item->user);
            $result = $tool->execute($args, $context);

            $array = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
            $isError = ($array['error'] ?? null) || (($array['success'] ?? null) === false);

            if ($isError) {
                // ToolResult::toArray serialises errors as ['error' => ['message' => ..., 'code' => ...]],
                // so we can't pass $array['error'] directly to fail(string).
                $errorMessage = match (true) {
                    is_string($array['error'] ?? null) => $array['error'],
                    is_array($array['error'] ?? null) => (string) ($array['error']['message'] ?? 'Versand fehlgeschlagen.'),
                    default => 'Versand fehlgeschlagen.',
                };
                return $this->fail($errorMessage, $array);
            }

            return [
                'ok' => true,
                'message' => "Über {$connectorKey} versendet — sichtbar auch im Original-Dienst.",
                'data' => $array,
            ];
        } catch (\Throwable $e) {
            return $this->fail('Versand fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function canReply(InboxItem $item): bool
    {
        if (!Schema::hasTable('user_connector_connections')) {
            return false;
        }
        $sessionTable = $this->sessionTableForMorph($item->source_type);
        if ($sessionTable === null) {
            return false;
        }
        $session = DB::table($sessionTable)->where('id', $item->source_id)->first();
        if (!$session) {
            return false;
        }
        $connectorKey = DB::table('user_connector_connections as c')
            ->join('user_connectors as uc', 'uc.id', '=', 'c.connector_id')
            ->where('c.id', $session->connection_id ?? 0)
            ->value('uc.key');

        return $connectorKey !== null && $this->router->has($item->channel?->value ?? '', $connectorKey);
    }

    protected function resolveTool(string $name): ?\Platform\Core\Contracts\ToolContract
    {
        $registry = app(\Platform\Core\Tools\ToolRegistry::class);
        return $registry->get($name);
    }

    protected function buildContext($user): \Platform\Core\Contracts\ToolContext
    {
        return new \Platform\Core\Contracts\ToolContext(
            user: $user,
            team: $user?->currentTeam ?? null,
        );
    }

    protected function sessionTableForMorph(string $morph): ?string
    {
        return match ($morph) {
            'user_connector_mail_session' => 'user_connector_mail_sessions',
            'user_connector_call_session' => 'user_connector_call_sessions',
            'user_connector_message_session' => 'user_connector_message_sessions',
            'user_connector_meeting_session' => 'user_connector_meeting_sessions',
            default => null,
        };
    }

    protected function fail(string $message, ?array $data = null): array
    {
        return ['ok' => false, 'message' => $message, 'data' => $data];
    }
}
