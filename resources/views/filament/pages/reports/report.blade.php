<x-filament-panels::page>
    {{-- Date filters --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-content p-4">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-40">
                    <label for="dateFrom" class="fi-fo-field-label text-sm font-medium text-gray-950 dark:text-white">
                        From
                    </label>
                    <div class="fi-input-wrp mt-1">
                        <div class="fi-input-wrp-content-ctn">
                            <input
                                type="date"
                                id="dateFrom"
                                wire:model.live.debounce.500ms="dateFrom"
                                class="fi-input w-full"
                            >
                        </div>
                    </div>
                </div>
                <div class="w-40">
                    <label for="dateUntil" class="fi-fo-field-label text-sm font-medium text-gray-950 dark:text-white">
                        Until
                    </label>
                    <div class="fi-input-wrp mt-1">
                        <div class="fi-input-wrp-content-ctn">
                            <input
                                type="date"
                                id="dateUntil"
                                wire:model.live.debounce.500ms="dateUntil"
                                class="fi-input w-full"
                            >
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
                <div class="flex items-center gap-x-2">
                    <span class="fi-wi-stats-overview-stat-label text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ $stat->getLabel() }}
                    </span>
                </div>
                <div class="fi-wi-stats-overview-stat-value mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ $stat->getValue() }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Breakdown table --}}
    @php $breakdown = $this->getBreakdown(); @endphp
    @if (count($breakdown) > 0)
        <div class="fi-ta rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-ta-header-ctn p-4 border-b border-gray-100 dark:border-white/5">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                    Breakdown
                </h3>
            </div>
            <div class="fi-ta-content-ctn">
                <table class="fi-ta-table w-full">
                    <thead>
                        <tr class="bg-gray-50/75 dark:bg-white/5">
                            <th class="fi-ta-header-cell px-4 py-2.5 text-start text-sm font-medium text-gray-500 dark:text-gray-400">
                                Status
                            </th>
                            <th class="fi-ta-header-cell px-4 py-2.5 text-end text-sm font-medium text-gray-500 dark:text-gray-400">
                                Count
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($breakdown as $label => $count)
                            <tr class="fi-ta-row">
                                <td class="fi-ta-cell px-4 py-3 text-sm text-gray-950 dark:text-white">
                                    {{ $label }}
                                </td>
                                <td class="fi-ta-cell px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ number_format($count) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
