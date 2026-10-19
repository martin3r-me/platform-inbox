<?php

namespace Platform\Inbox\Services;

use Platform\Core\Inbox\Contracts\SourceList;

/**
 * SourceList-Adapter: bedient einen Kanal, indem er an einen bestehenden
 * Inbox-QueryContract (listForUser) delegiert. So wandert die Listen-Naht in die
 * Core-SourceListRegistry (home liest die Liste über die EINE Core-InboxQuery),
 * ohne die erprobte Kanal-Faltung (Serie/Thread) neu zu schreiben.
 *
 * Pendant zu DelegatingSourceDetail; gemeinsam lösen sie die Inbox*QueryContracts
 * als home-Direktabhängigkeiten ab.
 */
class DelegatingSourceList implements SourceList
{
    /**
     * @param  string  $channel  Kanal, den dieser Provider bedient.
     * @param  class-string  $contract  Inbox*QueryContract mit listForUser(int,int,int): array.
     */
    public function __construct(
        protected string $channel,
        protected string $contract,
    ) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function listForUser(int $userId, int $teamId, int $limit): array
    {
        if (! interface_exists($this->contract)) {
            return [];
        }

        try {
            $rows = app($this->contract)->listForUser($userId, $teamId, $limit);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
