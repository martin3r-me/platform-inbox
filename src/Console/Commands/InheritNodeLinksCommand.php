<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxEntityLinkService;

/**
 * Vererbt Knoten-Links über die Einheit: hängt EIN Item einer Gruppe an einem
 * Knoten, sollen ALLE Items derselben Gruppe dort hängen — auch die, die später
 * reinkommen. Gruppe = Mail-Thread (conversation_id) oder Termin-Serie (ical_uid).
 *
 * Damit „hängt der Thread einmal am Knoten, bleibt er dran — auch bei neuer Mail":
 * die neue Mail wird ein Item mit derselben conversation_id, der nächste Lauf erbt
 * die Knoten der Geschwister. Idempotent (link = firstOrCreate).
 */
class InheritNodeLinksCommand extends Command
{
    protected $signature = 'inbox:inherit-node-links {--days=7 : Rückblick in Tagen} {--dry-run}';

    protected $description = 'Vererbt Knoten-Links innerhalb von Mail-Threads (conversation_id) und Termin-Serien (ical_uid).';

    public function handle(InboxEntityLinkService $links): int
    {
        if (! Schema::hasTable('inbox_items') || ! $links->enabled()) {
            $this->warn('Inbox/Organization nicht verfügbar — übersprungen.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $from = now()->subDays((int) $this->option('days'));
        $applied = 0;

        foreach (['conversation_id', 'ical_uid'] as $column) {
            $groups = InboxItem::query()
                ->whereNotNull($column)
                ->where('received_at', '>=', $from)
                ->get()
                ->groupBy($column);

            foreach ($groups as $group) {
                if ($group->count() < 2) {
                    continue; // keine Geschwister zum Erben
                }

                $itemIds = $group->pluck('id')->all();
                $linksByItem = $links->linksForItems($itemIds); // [item_id => [entity-summaries]]

                // Union aller in der Gruppe verlinkten Entity-IDs.
                $groupEntityIds = collect($linksByItem)
                    ->flatten(1)
                    ->pluck('id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (empty($groupEntityIds)) {
                    continue; // in dieser Gruppe hängt (noch) nichts
                }

                foreach ($group as $item) {
                    $has = collect($linksByItem[$item->id] ?? [])->pluck('id')->all();
                    foreach ($groupEntityIds as $entityId) {
                        if (in_array($entityId, $has, true)) {
                            continue;
                        }
                        if (! $dryRun) {
                            $links->link($item, (int) $entityId);
                        }
                        $applied++;
                    }
                }
            }
        }

        $this->info(sprintf('%sKnoten-Links vererbt: %d.', $dryRun ? '[dry-run] ' : '', $applied));

        return self::SUCCESS;
    }
}
