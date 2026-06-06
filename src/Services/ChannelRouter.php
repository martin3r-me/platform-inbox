<?php

namespace Platform\Inbox\Services;

use Closure;

/**
 * Maps a (channel, connector_key) pair to the user-connectors send-tool
 * that handles it, plus a builder for the tool's arguments.
 *
 * Other modules / future connectors register here — Inbox itself only
 * talks via this registry, never to provider classes directly.
 */
class ChannelRouter
{
    /** @var array<string, array{tool: string, args: Closure, label: string}> */
    protected array $handlers = [];

    /**
     * Register a handler for one (channel, connector_key) pair.
     *
     * The arg-builder receives ($item, $session, $connection) and returns
     * the array of arguments to pass to the registered tool.
     *
     * Signature of $args:
     *   function (InboxItem $item, object $session, object $connection): array
     */
    public function register(
        string $channel,
        string $connectorKey,
        string $toolName,
        Closure $argBuilder,
        string $label = '',
    ): void {
        $this->handlers[$this->key($channel, $connectorKey)] = [
            'tool' => $toolName,
            'args' => $argBuilder,
            'label' => $label !== '' ? $label : "{$channel} via {$connectorKey}",
        ];
    }

    public function find(string $channel, string $connectorKey): ?array
    {
        return $this->handlers[$this->key($channel, $connectorKey)] ?? null;
    }

    public function has(string $channel, string $connectorKey): bool
    {
        return isset($this->handlers[$this->key($channel, $connectorKey)]);
    }

    /** @return string[] tuples like "mail:microsoft365" */
    public function registered(): array
    {
        return array_keys($this->handlers);
    }

    protected function key(string $channel, string $connectorKey): string
    {
        return strtolower($channel) . ':' . strtolower($connectorKey);
    }
}
