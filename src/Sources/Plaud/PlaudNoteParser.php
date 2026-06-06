<?php

namespace Platform\Inbox\Sources\Plaud;

/**
 * Parses the markdown note that Plaud produces alongside a recording into
 * the structured chunks Inbox treats as a vendor-provided enrichment
 * (summary, action_items, ai_suggestions, outline). Used by the
 * inbox.plaud.sync.POST tool — and only there.
 *
 * Section names match Plaud's German default output. Other locales aren't
 * supported yet; if Plaud ever exposes the locale of the note we can add a
 * per-locale section map.
 */
class PlaudNoteParser
{
    public function parse(string $markdown): array
    {
        return [
            'summary' => $this->extractSection($markdown, 'Zusammenfassung'),
            'action_items' => $this->extractSection($markdown, 'Nächste Vereinbarungen'),
            'ai_suggestions' => $this->extractSection($markdown, 'KI-Vorschläge'),
            'outline' => $this->extractOutline($markdown),
        ];
    }

    /**
     * Extract content between "## SectionName" and the next "## " heading
     * (or end of string).
     */
    protected function extractSection(string $markdown, string $sectionName): ?string
    {
        $pattern = '/## ' . preg_quote($sectionName, '/') . '\s*\n(.*?)(?=\n## |\z)/s';

        if (preg_match($pattern, $markdown, $matches)) {
            $content = trim($matches[1]);
            return $content !== '' ? $content : null;
        }

        return null;
    }

    /**
     * Extract "Besprechungsinformationen" section as a key-value array.
     * Lines formatted as "> Key: Value" or "Key: Value".
     */
    protected function extractOutline(string $markdown): ?array
    {
        $raw = $this->extractSection($markdown, 'Besprechungsinformationen');
        if ($raw === null) {
            return null;
        }

        $outline = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '> ')) {
                $line = substr($line, 2);
            }
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(.+?):\s*(.+)$/', $line, $matches)) {
                $outline[trim($matches[1])] = trim($matches[2]);
            }
        }

        return !empty($outline) ? $outline : null;
    }
}
