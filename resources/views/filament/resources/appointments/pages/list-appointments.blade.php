<x-filament-panels::page>
    <div x-data="{ showCalendar: $wire.entangle('showCalendar') }">
        <div class="flex justify-end pb-2">
            <div class="flex items-center gap-x-1 rounded-lg border border-gray-200 bg-white p-1.5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <button wire:click="$set('showCalendar', false)" title="Table view"
                    :class="! showCalendar ? 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' : 'text-gray-400 hover:text-gray-600 dark:text-gray-500'"
                    class="rounded p-1.5 transition-colors">
                    @svg('heroicon-o-table-cells', 'h-5 w-5')
                </button>
                <button wire:click="$set('showCalendar', true)" title="Calendar view"
                    :class="showCalendar ? 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' : 'text-gray-400 hover:text-gray-600 dark:text-gray-500'"
                    class="rounded p-1.5 transition-colors">
                    @svg('heroicon-o-calendar-days', 'h-5 w-5')
                </button>
            </div>
        </div>

        <div x-show="! showCalendar">{{ $this->content }}</div>
        <div x-show="showCalendar" x-cloak>
            @livewire(\App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget::class)
        </div>
    </div>
</x-filament-panels::page>
