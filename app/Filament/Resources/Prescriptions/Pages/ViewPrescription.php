<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Actions\Prescriptions\VoidPrescription;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Prescription;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewPrescription extends ViewRecord
{
    protected static string $resource = PrescriptionResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $patientName = $record->patient?->full_name ?? 'Unknown patient';

        return 'Prescription for '.$patientName;
    }

    public function getBreadcrumb(): string
    {
        return $this->getRecord()->prescription_number;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();

        if ($record->isVoided()) {
            return $this->warning('⚠ Voided — this prescription cannot be dispensed against.');
        }

        if (! $record->isCurrentVersion()) {
            return $this->warning('⚠ Superseded — use the current version for printing and fulfillment.');
        }

        // A voided encounter does not void the prescription it produced — the
        // patient may already hold the printout — but staff must be told.
        if ($record->encounter?->status === EncounterStatus::Voided) {
            return $this->warning('⚠ The consultation that produced this prescription was voided. Confirm it is still clinically valid before dispensing.');
        }

        return null;
    }

    private function warning(string $message): Htmlable
    {
        return new HtmlString(
            '<span class="text-sm font-medium text-warning-600 dark:text-warning-400">'
            .e($message)
            .'</span>'
        );
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
                        Placeholder::make('patient_name')
                            ->label('Patient')
                            ->content($record->patient?->full_name ?? '—')
                            ->weight('bold'),
                        Placeholder::make('patient_number')
                            ->label('Patient Number')
                            ->content($record->patient?->patient_number ?? '—'),
                        Placeholder::make('encounter')
                            ->label('Consultation')
                            ->content(function () use ($record): HtmlString {
                                if ($record->encounter === null) {
                                    return new HtmlString('—');
                                }

                                $url = EncounterResource::getUrl('edit', ['record' => $record->encounter]);
                                $consultationDate = $record->encounter->started_at?->format('M j, Y')
                                    ?? $record->encounter->created_at?->format('M j, Y');
                                $consultationLabel = $record->encounter->encounter_number
                                    .($consultationDate === null ? '' : ' ('.$consultationDate.')');

                                return new HtmlString(
                                    '<a href="'.e($url).'" class="text-primary-600 hover:underline dark:text-primary-400">'
                                    .e($consultationLabel)
                                    .'</a>'
                                );
                            }),
                        Placeholder::make('optometrist')
                            ->label('Prescribing Optometrist')
                            ->content($record->author?->full_name ?? '—'),
                        Placeholder::make('prescribed_at')
                            ->label('Prescribed Date')
                            ->content($record->prescribed_at?->format('M j, Y') ?? '—'),
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
                    Section::make('Prescription')
                        ->schema([
                            Grid::make(3)->schema([
                                Placeholder::make('prev_main_od_value')->label('O.D.')->content($previousPrescription->main_od_value ?? '—'),
                                Placeholder::make('prev_main_od_sphere')->label('SPH')->content($previousPrescription->main_od_sphere ?? '—'),
                                Placeholder::make('prev_main_od_cylinder')->label('CX')->content($previousPrescription->main_od_cylinder ?? '—'),
                            ]),
                            Grid::make(3)->schema([
                                Placeholder::make('prev_main_os_value')->label('O.S.')->content($previousPrescription->main_os_value ?? '—'),
                                Placeholder::make('prev_main_os_sphere')->label('SPH')->content($previousPrescription->main_os_sphere ?? '—'),
                                Placeholder::make('prev_main_os_cylinder')->label('CX')->content($previousPrescription->main_os_cylinder ?? '—'),
                            ]),
                        ]),

                    Section::make('ADD')
                        ->schema([
                            Grid::make(3)->schema([
                                Placeholder::make('prev_add_od_value')->label('O.D.')->content($previousPrescription->add_od_value ?? '—'),
                                Placeholder::make('prev_add_od_sphere')->label('SPH')->content($previousPrescription->add_od_sphere ?? '—'),
                                Placeholder::make('prev_add_od_cylinder')->label('CX')->content($previousPrescription->add_od_cylinder ?? '—'),
                            ]),
                            Grid::make(3)->schema([
                                Placeholder::make('prev_add_os_value')->label('O.S.')->content($previousPrescription->add_os_value ?? '—'),
                                Placeholder::make('prev_add_os_sphere')->label('SPH')->content($previousPrescription->add_os_sphere ?? '—'),
                                Placeholder::make('prev_add_os_cylinder')->label('CX')->content($previousPrescription->add_os_cylinder ?? '—'),
                            ]),
                        ]),

                    Section::make('Details')
                        ->schema([
                            Placeholder::make('prev_remarks')->label('Remarks')->content($previousPrescription->remarks ?? '—')->columnSpanFull(),
                        ]),
                ]);
        }

        $components[] = $this->getRelationManagersContentComponent();

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createQuotation')
                ->label('Create Quotation')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->isCurrentVersion()
                    && (
                        auth()->user()?->isAdmin() === true
                        || auth()->user()?->isStaff() === true
                        || auth()->user()?->isOptometrist() === true
                    ))
                ->url(fn (): string => QuotationResource::getUrl('create', [
                    'prescription' => $this->getRecord()->id,
                ])),

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

            Action::make('voidPrescription')
                ->label('Void Prescription')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! $this->getRecord()->isVoided()
                    && auth()->user()?->isOptometrist() === true)
                ->requiresConfirmation()
                ->modalHeading('Void Prescription')
                ->modalDescription('This will mark the prescription as voided. This action cannot be undone.')
                ->modalSubmitActionLabel('Void Prescription')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason for Voiding')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    try {
                        app(VoidPrescription::class)->handle(
                            prescription: $this->getRecord(),
                            actor: auth()->user(),
                            reason: $data['reason'],
                        );

                        Notification::make()->title('Prescription voided')->success()->send();
                        $this->refreshFormData([]);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot void prescription')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
