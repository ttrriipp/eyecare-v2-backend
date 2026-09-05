<?php

namespace App\Filament\Resources\VisitRatings\Pages;

use App\Filament\Resources\VisitRatings\VisitRatingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVisitRating extends ViewRecord
{
    protected static string $resource = VisitRatingResource::class;

    public function getTitle(): string
    {
        $appointmentNumber = $this->record->appointment?->appointment_number;

        return $appointmentNumber !== null
            ? "Feedback for {$appointmentNumber}"
            : 'Visit Feedback';
    }

    public function getBreadcrumbs(): array
    {
        return [
            VisitRatingResource::getUrl('index') => 'Visit Feedback',
            $this->record->appointment?->appointment_number ?? "Feedback #{$this->record->getKey()}",
        ];
    }
}
