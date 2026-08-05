<?php

namespace App\Filament\Clusters\Availability\Pages;

use App\Actions\Appointments\DailyAvailabilitySummary;
use App\Actions\Appointments\ResolveDailyAvailabilitySummary;
use App\Filament\Support\DailyAvailabilitySummaryCard;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class Today extends AvailabilityClusterPage
{
    protected static ?string $title = 'Today & Next 7 Days';

    protected static ?string $navigationLabel = 'Today';

    protected static ?int $navigationSort = 1;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Today & Next 7 Days')
                ->description('Resolved status per day, with schedule overrides already applied.')
                ->schema([
                    Placeholder::make('availability_summary')
                        ->hiddenLabel()
                        ->content(fn () => DailyAvailabilitySummaryCard::render($this->getAvailabilitySummary())),
                ]),
        ]);
    }

    /**
     * @return Collection<int, DailyAvailabilitySummary>
     */
    private function getAvailabilitySummary(): Collection
    {
        return app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 8);
    }
}
