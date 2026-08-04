<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\Quotations\CreateQuotation as CreateQuotationAction;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    public ?int $encounterId = null;

    public ?int $patientId = null;

    public function mount(?string $encounter = null, ?string $patient = null): void
    {
        $encounter ??= request()->query('encounter');
        $patient ??= request()->query('patient');

        $this->encounterId = filled($encounter) ? (int) $encounter : null;
        $this->patientId = filled($patient) ? (int) $patient : null;

        parent::mount();
    }

    public function getTitle(): string
    {
        $patient = $this->resolveEncounter()?->patient ?? $this->resolvePatient();

        return $patient !== null
            ? "New Quotation for {$patient->full_name}"
            : 'New Quotation';
    }

    public function form(Schema $schema): Schema
    {
        $encounter = $this->resolveEncounter();
        $patient = $encounter?->patient ?? $this->resolvePatient();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Patient')
                    ->schema($patient !== null
                        ? [
                            Placeholder::make('patient_display')
                                ->label('Patient')
                                ->content($patient->full_name),
                        ]
                        : [
                            Select::make('patient_id')
                                ->label('Patient')
                                ->options(fn (): array => Patient::query()
                                    ->get()
                                    ->mapWithKeys(fn (Patient $p): array => [$p->id => $p->full_name])
                                    ->all())
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                    ->columns(2),

                ...QuotationCreationForm::components(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $creator = auth()->user();

        abort_unless($creator instanceof User, 403);

        $encounter = $this->resolveEncounter();
        $patient = $encounter?->patient
            ?? $this->resolvePatient()
            ?? Patient::query()->find($data['patient_id'] ?? null);

        unset($data['patient_id']);

        if ($patient === null) {
            Notification::make()
                ->title('Cannot create quotation')
                ->body('A patient is required.')
                ->danger()
                ->send();

            $this->halt();
        }

        try {
            return app(CreateQuotationAction::class)->handle(
                patient: $patient,
                creator: $creator,
                data: $data,
                encounter: $encounter,
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot create quotation')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Quotation created';
    }

    private function resolveEncounter(): ?Encounter
    {
        return $this->encounterId !== null ? Encounter::query()->find($this->encounterId) : null;
    }

    private function resolvePatient(): ?Patient
    {
        return $this->patientId !== null ? Patient::query()->find($this->patientId) : null;
    }
}
