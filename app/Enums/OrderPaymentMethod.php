<?php

namespace App\Enums;

enum OrderPaymentMethod: string
{
    case GCash = 'gcash';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::GCash => 'GCash',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }
}
