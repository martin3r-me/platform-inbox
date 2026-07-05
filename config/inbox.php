<?php

return [
    'routing' => [
        'mode' => env('INBOX_MODE', 'path'),
        'prefix' => 'inbox',
    ],
    'guard' => 'web',

    'navigation' => [
        'route' => 'inbox.index',
        'icon'  => 'heroicon-o-inbox',
        'order' => 5,
    ],

    'sidebar' => [
        [
            'group' => 'Allgemein',
            'items' => [
                [
                    'label' => 'Inbox',
                    'route' => 'inbox.index',
                    'icon'  => 'heroicon-o-inbox',
                ],
                [
                    'label' => 'Snoozed',
                    'route' => 'inbox.snoozed',
                    'icon'  => 'heroicon-o-clock',
                ],
                [
                    'label' => 'Abonnements',
                    'route' => 'inbox.subscriptions',
                    'icon'  => 'heroicon-o-bell',
                ],
                [
                    'label' => 'Regeln',
                    'route' => 'inbox.rules.index',
                    'icon'  => 'heroicon-o-funnel',
                ],
                [
                    'label' => 'Templates',
                    'route' => 'inbox.templates.index',
                    'icon'  => 'heroicon-o-sparkles',
                ],
                [
                    'label' => 'Kosten',
                    'route' => 'inbox.costs.index',
                    'icon'  => 'heroicon-o-banknotes',
                ],
            ],
        ],
    ],

    'sources' => [
        'user_connector_mail_session' => [
            'channel' => 'mail',
            'sender_field' => 'from_address',
            'sender_kind' => 'email',
            'subject_field' => 'subject',
            'preview_field' => 'body_preview',
            'body_field' => 'body',
            'body_format' => 'text',
            'received_at_field' => 'received_at',
            // outbound mails are the user's own sends — no triage value, skip.
            'skip_outbound' => true,
        ],
        'user_connector_call_session' => [
            'channel' => 'call',
            'sender_field' => 'from_number',
            'sender_kind' => 'phone',
            'subject_field' => null,
            'preview_field' => null,
            'received_at_field' => 'started_at',
            // Outbound calls = du weisst was du gerufen hast, kein Triage-Wert.
            // Inbound (angenommen + verpasst) bleibt: angenommene Calls
            // kommen oft mit Transkript, das ist Triage-relevant; verpasste
            // brauchen Rückruf-Entscheidung.
            'skip_outbound' => true,
        ],
        'user_connector_message_session' => [
            'channel' => 'message',
            'sender_field' => 'from_identifier',
            'sender_kind' => 'teams',
            'subject_field' => null,
            'preview_field' => 'body_preview',
            'received_at_field' => 'sent_at',
            // outbound teams messages are the user's own sends — skip.
            'skip_outbound' => true,
        ],
        'user_connector_meeting_session' => [
            'channel' => 'meeting',
            'sender_field' => 'organizer_address',
            'sender_kind' => 'email',
            'subject_field' => 'subject',
            'preview_field' => 'body_preview',
            'received_at_field' => 'start_at',
            // Auch hier: outbound = selbst angelegte Einladungen.
            // Triage-Wert ist null, weil der Termin schon im Kalender steht.
            'skip_outbound' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrichment
    |--------------------------------------------------------------------------
    |
    | Gate für den RunEnrichmentJob-Dispatch. Ohne diese Grenzen kostet
    | ein voller Inbox-Backfill für einen Team-Account schnell dreistellig
    | pro Monat, obwohl der Grossteil davon Instagram-Digests und
    | Wartungsmails sind, die niemand liest.
    |
    | skip_sender_patterns: Case-insensitive. Präfix "/" → Regex. Sonst
    | wird die Zeichenkette als Substring gegen den sender_identifier
    | geprüft (also 'newsletter@' fängt auch 'newsletters@…' ab).
    |
    | min_body_length: alles darunter ist zu dünn für eine sinnvolle
    | Zusammenfassung — Meeting-Reminder, 1-Zeilen-Bestätigungen etc.
    */
    'enrichment' => [
        'min_body_length' => 200,
        'skip_sender_patterns' => [
            // Social-Media-Notification-Domains
            '@mail.instagram.com',
            '@notifications.instagram.com',
            '@instagram.com',
            '@facebookmail.com',
            '@linkedin.com',
            '@twitter.com',
            '@x.com',
            '@notifications.slack.com',
            // Recap / Digest local-parts
            'posts-recap@',
            'stories-recap@',
            'stories@',
            'newsletter@',
            'newsletters@',
            'digest@',
            'daily-digest@',
            'weekly-digest@',
            // Generic notification senders that never carry actionable text
            'notifications@',
            'notification@',
            'no-reply@',
            'noreply@',
            'do-not-reply@',
            'donotreply@',
        ],
    ],
];
