@if($this instanceof \App\Filament\Resources\Appointments\Pages\ListAppointments)
<div
    x-data="{ showCalendar: @entangle('showCalendar') }"
    class="flex items-center gap-x-1 rounded-lg border border-gray-200 p-1 dark:border-white/10"
>
    <button
        wire:click="toggleView(false)"
        :class="showCalendar ? 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300' : 'bg-white text-gray-700 shadow-sm dark:bg-white/10 dark:text-white'"
        class="rounded p-1.5 transition-colors"
        title="Table view"
    >
        @svg('heroicon-o-table-cells', 'h-4 w-4')
    </button>
    <button
        wire:click="toggleView(true)"
        :class="showCalendar ? 'bg-white text-gray-700 shadow-sm dark:bg-white/10 dark:text-white' : 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300'"
        class="rounded p-1.5 transition-colors"
        title="Calendar view"
    >
        @svg('heroicon-o-calendar-days', 'h-4 w-4')
    </button>
</div>
@endif
