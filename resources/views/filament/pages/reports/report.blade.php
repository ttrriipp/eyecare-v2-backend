<x-filament-panels::page>
    {{-- Filters: preset pills + manual date range --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-content space-y-4 p-4">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->getPresets() as $key => $label)
                    <button
                        type="button"
                        wire:click="applyPreset('{{ $key }}')"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            'text-white shadow-sm' => $activePreset === $key,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $activePreset !== $key,
                        ])
                        @style(['background-color: #4F8DD7' => $activePreset === $key])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-end gap-4 border-t border-gray-100 pt-4 dark:border-white/5">
                <div class="w-40">
                    <label for="dateFrom" class="text-sm font-medium text-gray-950 dark:text-white">From</label>
                    <div class="fi-input-wrp mt-1">
                        <div class="fi-input-wrp-content-ctn">
                            <input type="date" id="dateFrom" wire:model.live.debounce.500ms="dateFrom" class="fi-input w-full">
                        </div>
                    </div>
                </div>
                <div class="w-40">
                    <label for="dateUntil" class="text-sm font-medium text-gray-950 dark:text-white">Until</label>
                    <div class="fi-input-wrp mt-1">
                        <div class="fi-input-wrp-content-ctn">
                            <input type="date" id="dateUntil" wire:model.live.debounce.500ms="dateUntil" class="fi-input w-full">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->getStats() as $stat)
            <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $stat->getLabel() }}</span>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $stat->getValue() }}</div>
            </div>
        @endforeach
    </div>

    {{-- Breakdown with share bars --}}
    @php
        $breakdown = $this->getBreakdown();
        $total = array_sum($breakdown);
    @endphp

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="border-b border-gray-100 p-4 dark:border-white/5">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Breakdown</h3>
        </div>

        @if ($total > 0)
            <div class="space-y-4 p-4">
                @foreach ($breakdown as $label => $count)
                    @php $pct = (int) round(($count / $total) * 100); @endphp
                    <div>
                        <div class="mb-1 flex items-baseline justify-between text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $label }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ number_format($count) }} <span class="ml-1 tabular-nums">({{ $pct }}%)</span>
                            </span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: #4F8DD7;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-col items-center justify-center px-4 py-12 text-center">
                <svg class="h-10 w-10 text-gray-300 dark:text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
                <p class="mt-3 text-sm font-medium text-gray-600 dark:text-gray-300">No records in this period</p>
                <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">Try a different date range.</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
