<?php

namespace App\Filament\Resources\VisitRatings\Pages;

use App\Filament\Resources\VisitRatings\VisitRatingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVisitRating extends ViewRecord
{
    protected static string $resource = VisitRatingResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $patientName = $record->patient?->full_name ?? 'Unknown patient';

        return 'View for '.$patientName;
    }

    public function getBreadcrumbs(): array
    {
        return [
            VisitRatingResource::getUrl('index') => 'Visit Feedback',
            $this->record->appointment?->appointment_number ?? "Feedback #{$this->record->getKey()}",
        ];
    }
}
