<?php

namespace App\Filament\Resources\FrameRatings\Pages;

use App\Filament\Resources\FrameRatings\FrameRatingResource;
use Filament\Resources\Pages\ViewRecord;

class EditFrameRating extends ViewRecord
{
    protected static string $resource = FrameRatingResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $patientName = $record->patient?->full_name ?? 'Unknown patient';

        return 'View for '.$patientName;
    }
}
