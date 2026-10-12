<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case POSTED = 'posted';
    case DRAFT = 'draft';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::POSTED => 'Posted',
            self::DRAFT => 'Draft',
            self::CANCELLED => 'Cancelled',
        };
    }
}
