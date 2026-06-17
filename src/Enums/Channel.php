<?php

namespace Platform\Inbox\Enums;

enum Channel: string
{
    case Mail = 'mail';
    case Call = 'call';
    case Message = 'message';
    case Meeting = 'meeting';
    case Recording = 'recording';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'E-Mail',
            self::Call => 'Anruf',
            self::Message => 'Nachricht',
            self::Meeting => 'Meeting',
            self::Recording => 'Aufnahme',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Mail => 'heroicon-o-envelope',
            self::Call => 'heroicon-o-phone',
            self::Message => 'heroicon-o-chat-bubble-left',
            self::Meeting => 'heroicon-o-calendar-days',
            self::Recording => 'heroicon-o-microphone',
        };
    }
}
