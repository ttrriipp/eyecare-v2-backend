<?php

namespace App\Filament\Support;

final class PatientAccountActions
{
    /**
     * @return array<string, string>
     */
    public static function unlinkReasonOptions(): array
    {
        return [
            'patient_request' => 'Patient request',
            'identity_mismatch' => 'Identity details no longer match',
            'duplicate_record' => 'Duplicate patient record',
            'administrative_correction' => 'Administrative correction',
            'other' => 'Other',
        ];
    }

    /**
     * Resolve a preset into the existing free-text reason field.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolveUnlinkReason(array $data): string
    {
        $preset = $data['reason_category'] ?? null;

        if ($preset === 'other') {
            return trim((string) ($data['reason_details'] ?? ''));
        }

        if (filled($preset)) {
            return self::unlinkReasonOptions()[$preset] ?? trim((string) $preset);
        }

        return trim((string) ($data['reason_details'] ?? ''));
    }
}
