<div class="flex items-center gap-x-1 rounded-lg border border-gray-200 p-1 dark:border-white/10">
    <button
        wire:click="toggleView(false)"
        @class([
            'rounded p-1.5 transition-colors',
            'bg-white text-gray-700 shadow-sm dark:bg-white/10 dark:text-white' => ! $showCalendar,
            'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300' => $showCalendar,
        ])
        title="Table view"
    >
        @svg('heroicon-o-table-cells', 'h-4 w-4')
    </button>
    <button
        wire:click="toggleView(true)"
        @class([
            'rounded p-1.5 transition-colors',
            'bg-white text-gray-700 shadow-sm dark:bg-white/10 dark:text-white' => $showCalendar,
            'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300' => ! $showCalendar,
        ])
        title="Calendar view"
    >
        @svg('heroicon-o-calendar-days', 'h-4 w-4')
    </button>
</div>
