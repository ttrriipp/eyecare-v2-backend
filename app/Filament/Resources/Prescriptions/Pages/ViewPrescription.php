<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ViewPrescription extends ViewRecord
{
    protected static string $resource = PrescriptionResource::class;

    public function getTitle(): string
    {
        return 'View '.$this->getRecord()->prescription_number;
    }

    public function getBreadcrumb(): string
    {
        return $this->getRecord()->prescription_number;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();

        if (! $record->isCurrentVersion()) {
            return new HtmlString(
                '<span class="text-sm font-medium text-warning-600 dark:text-warning-400">⚠ Superseded — use the current version for printing and fulfillment.</span>'
            );
        }

        return null;
    }

    public function content(Schema $schema): Schema
    {
        $record = $this->getRecord();

        $components = [
            Grid::make(3)->schema([
                $this->getFormContentComponent()
                    ->columnSpan(2),

                Section::make('Details')
                    ->schema([
                        Placeholder::make('prescription_number')
                            ->label('Prescription Number')
                            ->content($record->prescription_number ?? '—'),
                        Placeholder::make('prescribed_at')
                            ->label('Prescribed Date')
                            ->content($record->prescribed_at?->format('M j, Y') ?? '—'),
                        Placeholder::make('patient_name')
                            ->label('Patient')
                            ->content($record->patient?->full_name ?? '—'),
                        Placeholder::make('patient_number')
                            ->label('Patient Number')
                            ->content($record->patient?->patient_number ?? '—'),
                        Placeholder::make('encounter')
                            ->label('Encounter')
                            ->content($record->encounter?->encounter_number ?? '—'),
                        Placeholder::make('optometrist')
                            ->label('Prescribing Optometrist')
                            ->content($record->author?->full_name ?? '—'),
                    ])
                    ->columnSpan(1),
            ]),
        ];

        $previousPrescription = $this->getRecord()->previousPrescription;

        if ($previousPrescription !== null) {
            $components[] = Section::make('Previous Version ('.$previousPrescription->prescribed_at->format('M j, Y').')')
                ->description('This finalized record supersedes the version below.')
                ->collapsed()
                ->collapsible()
                ->schema([
                    Grid::make(6)->schema([
                        Placeholder::make('prev_main_od_value')->label('O.D.')->content($previousPrescription->main_od_value ?? '—'),
                        Placeholder::make('prev_main_od_sphere')->label('SPH')->content($previousPrescription->main_od_sphere ?? '—'),
                        Placeholder::make('prev_main_od_cylinder')->label('CX')->content($previousPrescription->main_od_cylinder ?? '—'),
                        Placeholder::make('prev_main_os_value')->label('O.S.')->content($previousPrescription->main_os_value ?? '—'),
                        Placeholder::make('prev_main_os_sphere')->label('SPH')->content($previousPrescription->main_os_sphere ?? '—'),
                        Placeholder::make('prev_main_os_cylinder')->label('CX')->content($previousPrescription->main_os_cylinder ?? '—'),
                        Placeholder::make('prev_remarks')->label('Remarks')->content($previousPrescription->remarks ?? '—'),
                    ]),
                ]);
        }

        $components[] = $this->getRelationManagersContentComponent();

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('amendPrescription')
                ->label('Amend Prescription')
                ->icon('heroicon-o-document-plus')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('amend', $this->getRecord()) === true
                    && ! Prescription::query()
                        ->withTrashed()
                        ->where('previous_prescription_id', $this->getRecord()->id)
                        ->exists())
                ->url(fn (): string => PrescriptionResource::getUrl('amend', [
                    'previous' => $this->getRecord()->id,
                ])),

            Action::make('viewCurrentPrescription')
                ->label('View Current Version')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('warning')
                ->visible(fn (): bool => ! $this->getRecord()->isCurrentVersion())
                ->url(fn (): string => PrescriptionResource::getUrl('view', [
                    'record' => $this->getRecord()->currentVersion(),
                ])),

            Action::make('print_prescription')
                ->label('Print Prescription')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->isCurrentVersion())
                ->url(fn () => route('pdf.prescription', $this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }
}
