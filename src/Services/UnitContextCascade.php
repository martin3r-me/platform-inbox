<?php

namespace Platform\Inbox\Services;

/**
 * Durchreichen von Kontext innerhalb einer Einheit: kopiert die Org-Dimension-
 * Verknüpfungen eines Items auf ein anderes. Kanal-blind — egal ob Aufnahme→Meeting,
 * Mail→Ticket oder künftige Fälle. Soft-gekoppelt: ohne Organization-Modul No-op.
 *
 * So landen Transkript UND gebuchte Zeit am selben Org-Kontext, ohne dass der
 * aufrufende Code (Correlator, LinkRule, manuelle Zuordnung) Kanal-Wissen braucht.
 */
class UnitContextCascade
{
    public function inherit(int $fromItemId, int $toItemId): void
    {
        $svcClass = \Platform\Organization\Services\DimensionLinkService::class;
        if (! class_exists($svcClass)) {
            return;
        }

        try {
            $svc = app($svcClass);
            foreach (array_keys($svcClass::getDimensions()) as $dimension) {
                foreach ($svc->getLinked($dimension, 'inbox_item', $fromItemId) as $row) {
                    $valueId = (int) ($row['dim_value_id'] ?? $row['id'] ?? 0);
                    if ($valueId > 0) {
                        $svc->link($dimension, 'inbox_item', $toItemId, $valueId, ['via' => 'unit_context_cascade']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen / Dimension nicht verfügbar — egal.
        }
    }
}
