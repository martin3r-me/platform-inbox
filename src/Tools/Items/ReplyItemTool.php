<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxSendService;

/**
 * Reply to an inbox item via the channel it came in on. Routed through
 * the ChannelRouter (mail/microsoft365, message/microsoft365 → Teams,
 * message/sipgate → SMS, call/* → callback, …). Optionally marks the
 * item as done after a successful send.
 */
class ReplyItemTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.reply.POST';
    }

    public function getDescription(): string
    {
        return 'Antwortet auf ein Inbox-Item über den passenden Kanal (Mail, Teams, SMS, Rückruf). '
            . 'Routing erfolgt automatisch via ChannelRouter. Optional: close_on_send=true setzt das Item '
            . 'nach erfolgreichem Versand auf done.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'body' => ['type' => 'string', 'description' => 'Antworttext. Pflicht für mail/message.'],
                'subject' => ['type' => 'string', 'description' => 'Optional. Default: "Re: " + Original-Betreff bei Mails.'],
                'close_on_send' => ['type' => 'boolean', 'description' => 'Default: true.'],
            ],
            'required' => ['item_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $item = InboxItem::query()
            ->where('id', (int) ($arguments['item_id'] ?? 0))
            ->where('user_id', $context->user->id)
            ->first();
        if (!$item) {
            return ToolResult::error('NOT_FOUND', 'Item nicht gefunden oder kein Zugriff.');
        }

        $body = (string) ($arguments['body'] ?? '');
        $subject = (string) ($arguments['subject'] ?? '');
        if ($subject === '' && $item->subject && $item->channel?->value === 'mail') {
            $subject = 'Re: ' . $item->subject;
        }
        $closeOnSend = $arguments['close_on_send'] ?? true;

        $channelValue = $item->channel?->value;
        $needsBody = in_array($channelValue, ['mail', 'message'], true);
        if ($needsBody && trim($body) === '') {
            return ToolResult::error('VALIDATION_ERROR', 'body ist für Mails und Nachrichten erforderlich.');
        }

        $result = app(InboxSendService::class)->sendReply(
            $item,
            $subject,
            $body,
            $context->user,
        );

        if ($result['ok'] && $closeOnSend) {
            $item->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
            ]);
        }

        return $result['ok']
            ? ToolResult::success([
                'item_id' => $item->id,
                'sent' => true,
                'closed' => (bool) ($result['ok'] && $closeOnSend),
                'message' => $result['message'] ?? null,
            ])
            : ToolResult::error('EXECUTION_ERROR', $result['message'] ?? 'Versand fehlgeschlagen.');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'reply', 'compose', 'send'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
