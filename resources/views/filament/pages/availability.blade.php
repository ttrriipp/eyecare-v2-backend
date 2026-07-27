<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->clinicHoursForm }}
        {{ $this->providerHoursForm }}

        <div class="flex gap-3">
            <x-filament::button wire:click="saveClinicHours" color="primary">
                Save Clinic Hours
            </x-filament::button>
            <x-filament::button wire:click="saveProviderHours" color="primary">
                Save Provider Hours
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
