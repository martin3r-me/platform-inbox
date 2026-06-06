<?php

namespace Platform\Inbox\Enums;

enum SubscriptionStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Muted = 'muted';

    public function label(): string
    {
        return match ($this) {
            self::Subscribed => 'Abonniert',
            self::Unsubscribed => 'Abbestellt',
            self::Muted => 'Stumm',
        };
    }
}
