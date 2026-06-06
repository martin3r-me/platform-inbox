<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('inbox_enrichment_templates')->insert([
            [
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'key' => 'standard-mail',
                'name' => 'Standard E-Mail',
                'description' => 'Knappe Triage-Anreicherung für eingehende E-Mails: TL;DR, Summary, Action Items, Urgency.',
                'applicable_channels' => json_encode(['mail']),
                'output_schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'headline' => ['type' => 'string', 'description' => 'Eine Zeile, max 12 Worte — worum geht es?'],
                        'tldr' => ['type' => 'string', 'description' => '1–2 Sätze, max 100 Wörter — was muss ich wissen?'],
                        'summary' => ['type' => 'string', 'description' => 'Volltext-Zusammenfassung, strukturiert mit Kontext.'],
                        'action_items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => ['type' => 'string'],
                                    'suggested_owner' => ['type' => 'string', 'description' => 'Name oder E-Mail der Person, die übernehmen sollte.'],
                                    'due_hint' => ['type' => 'string', 'description' => 'Frist-Hinweis aus dem Text, z.B. "bis Freitag", "diese Woche".'],
                                ],
                            ],
                        ],
                        'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    ],
                    'required' => ['headline', 'tldr', 'summary', 'urgency'],
                ]),
                'system_prompt' => 'Du bist ein präziser Triage-Assistent für eine Business-Inbox. Deine Aufgabe: aus einer E-Mail strukturiert die Schlüssel-Informationen extrahieren. Antworte ausschließlich in der Sprache {language}. Antworte ausschließlich als gültiges JSON-Objekt entsprechend dem vereinbarten Schema. Keine Höflichkeitsformeln, keine Meta-Kommentare.',
                'prompt_template' => "Hier ist eine eingehende E-Mail. Erstelle die strukturierte Anreicherung.\n\nAbsender: {sender}\nBetreff: {subject}\nKanal: {channel}\n\n--- BODY ---\n{body}\n--- END BODY ---",
                'preferred_provider' => 'openai:gpt-4o-mini',
                'version' => 1,
                'is_active' => true,
                'is_default_for_channel' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'key' => 'meeting-transcript',
                'name' => 'Meeting-Transkript',
                'description' => 'Reiche Anreicherung für aufgenommene Meetings: Agenda, Entscheidungen, Action Items, offene Fragen, Schlüsselzitate.',
                'applicable_channels' => json_encode(['recording', 'meeting']),
                'output_schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'headline' => ['type' => 'string'],
                        'tldr' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                        'agenda' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'decisions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'open_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'action_items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => ['type' => 'string'],
                                    'suggested_owner' => ['type' => 'string', 'description' => 'Sprecher-Label oder Name, falls erkennbar.'],
                                    'due_hint' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'key_quotes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'speaker' => ['type' => 'string'],
                                    'quote' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['headline', 'tldr', 'summary', 'action_items'],
                ]),
                'system_prompt' => 'Du bist ein präziser Meeting-Auswerter. Aus einem Audio-Transkript extrahierst du strukturiert Schlüssel-Informationen. Antworte ausschließlich in der Sprache {language}. Antworte ausschließlich als gültiges JSON-Objekt entsprechend dem vereinbarten Schema. Bei Sprecher-Zuordnung nutze die Labels aus dem Transkript (z.B. "Sprecher A", "Speaker B"). Keine Höflichkeitsformeln, keine Meta-Kommentare.',
                'prompt_template' => "Hier ist ein Meeting-Transkript. Erstelle die strukturierte Anreicherung.\n\nMeeting-Betreff: {subject}\nBeteiligte:\n{participants_list}\n\n--- TRANSKRIPT ---\n{body}\n--- END TRANSKRIPT ---",
                'preferred_provider' => 'openai:gpt-4o-mini',
                'version' => 1,
                'is_active' => true,
                'is_default_for_channel' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('inbox_enrichment_templates')
            ->whereNull('team_id')
            ->whereIn('key', ['standard-mail', 'meeting-transcript'])
            ->delete();
    }
};
