<x-filament-panels::page>
    <div class="relative">
        <div class="absolute end-0 top-0 z-10 flex items-center gap-x-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-white/10 dark:bg-gray-900">
            <button
                wire:click="$set('showCalendar', false)"
                title="{{ __('Table view') }}"
                @class([
                    'rounded p-1.5 transition-colors',
                    'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => ! $this->showCalendar,
                    'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300' => $this->showCalendar,
                ])
            >
                @svg('heroicon-o-table-cells', 'h-4 w-4')
            </button>
            <button
                wire:click="$set('showCalendar', true)"
                title="{{ __('Calendar view') }}"
                @class([
                    'rounded p-1.5 transition-colors',
                    'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $this->showCalendar,
                    'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300' => ! $this->showCalendar,
                ])
            >
                @svg('heroicon-o-calendar-days', 'h-4 w-4')
            </button>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
