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
        ],
        'user_connector_call_session' => [
            'channel' => 'call',
            'sender_field' => 'from_number',
            'sender_kind' => 'phone',
            'subject_field' => null,
            'preview_field' => null,
            'received_at_field' => 'started_at',
        ],
        'user_connector_message_session' => [
            'channel' => 'message',
            'sender_field' => 'from_identifier',
            'sender_kind' => 'teams',
            'subject_field' => null,
            'preview_field' => 'body_preview',
            'received_at_field' => 'sent_at',
        ],
        'user_connector_meeting_session' => [
            'channel' => 'meeting',
            'sender_field' => 'organizer_address',
            'sender_kind' => 'email',
            'subject_field' => 'subject',
            'preview_field' => 'body_preview',
            'received_at_field' => 'start_at',
        ],
    ],
];
