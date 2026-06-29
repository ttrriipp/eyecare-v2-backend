<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4 mb-6">
        <div>
            <label for="dateFrom" class="block text-sm font-medium text-gray-700 dark:text-gray-300">From</label>
            <input type="date" id="dateFrom" wire:model.live.debounce.500ms="dateFrom"
                class="mt-1 block rounded-md border-gray-300 shadow-sm dark:bg-gray-800 dark:border-gray-600 dark:text-white text-sm">
        </div>
        <div>
            <label for="dateUntil" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Until</label>
            <input type="date" id="dateUntil" wire:model.live.debounce.500ms="dateUntil"
                class="mt-1 block rounded-md border-gray-300 shadow-sm dark:bg-gray-800 dark:border-gray-600 dark:text-white text-sm">
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        @foreach ($this->getStats() as $stat)
            <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $stat->getLabel() }}</span>
                <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $stat->getValue() }}</div>
            </div>
        @endforeach
    </div>

    {{-- Breakdown table --}}
    @php $breakdown = $this->getBreakdown(); @endphp
    @if (count($breakdown) > 0)
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Category</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300">Count</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($breakdown as $label => $count)
                        <tr>
                            <td class="px-4 py-3 text-gray-900 dark:text-white">{{ $label }}</td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-white font-medium">{{ number_format($count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
