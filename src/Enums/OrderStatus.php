<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case PROFORMA = 'proforma';
    case CONFIRMED = 'confirmed';
    case CONVERTED_TO_INVOICE = 'converted_to_invoice';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PROFORMA => 'Proforma Invoice',
            self::CONFIRMED => 'Confirmed Order',
            self::CONVERTED_TO_INVOICE => 'Converted to Invoice',
            self::CANCELLED => 'Cancelled',
        };
    }
}
