<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

class CreatePrescription extends CreateRecord
{
    protected static string $resource = PrescriptionResource::class;

    /**
     * Optional: pre-fill form from an existing prescription's ID (passed via URL).
     * Staff picks "Copy from previous" → redirects here with copyFromId set.
     */
    #[Url(as: 'copy')]
    public ?int $copyFromId = null;

    public function mount(): void
    {
        parent::mount();

        if ($this->copyFromId) {
            $source = Prescription::query()->find($this->copyFromId);

            if ($source) {
                $this->form->fill([
                    'customer_id' => $source->customer_id,
                    'od_sphere' => $source->od_sphere,
                    'od_cylinder' => $source->od_cylinder,
                    'od_axis' => $source->od_axis,
                    'od_add' => $source->od_add,
                    'od_prism' => $source->od_prism,
                    'od_base' => $source->od_base,
                    'os_sphere' => $source->os_sphere,
                    'os_cylinder' => $source->os_cylinder,
                    'os_axis' => $source->os_axis,
                    'os_add' => $source->os_add,
                    'os_prism' => $source->os_prism,
                    'os_base' => $source->os_base,
                    'pd' => $source->pd,
                    'notes' => $source->notes,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
