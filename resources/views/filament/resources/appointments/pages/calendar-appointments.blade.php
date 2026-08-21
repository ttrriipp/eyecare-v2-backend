<x-filament-panels::page>
    <div class="space-y-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Review scheduled and checked-in appointments by day, week, or month.
            </p>
        </div>

        @livewire($this->getCalendarWidget())
    </div>
</x-filament-panels::page>
