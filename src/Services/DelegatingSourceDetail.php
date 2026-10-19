<?php

namespace Platform\Inbox\Services;

use Platform\Core\Inbox\Contracts\SourceDetail;

/**
 * SourceDetail-Adapter: bedient einen Kanal, indem er an einen bestehenden
 * Inbox-QueryContract delegiert. So wandert die Detail-Naht in die Core-
 * SourceDetailRegistry (home liest Detail über EINE Core-Query), ohne die
 * erprobte Kanal-Logik (Meeting-Serie, Mail-Thread, …) neu zu schreiben.
 *
 * Schritt Richtung inbox-Auflösung: die Inbox*QueryServices werden damit zu
 * internen Detail-Quellen hinter Core, nicht mehr zu home-Direktabhängigkeiten.
 */
class DelegatingSourceDetail implements SourceDetail
{
    /**
     * @param  string  $channel  Kanal, den dieser Provider bedient.
     * @param  class-string  $contract  Inbox*QueryContract mit detailForItem(int): ?array.
     */
    public function __construct(
        protected string $channel,
        protected string $contract,
    ) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        if (! interface_exists($this->contract)) {
            return null;
        }

        try {
            $detail = app($this->contract)->detailForItem($inboxItemId);

            return is_array($detail) ? $detail : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
