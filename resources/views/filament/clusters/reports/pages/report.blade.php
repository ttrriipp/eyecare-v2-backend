<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-content space-y-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Report period</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $reportPeriodLabel }} · {{ $reportTimezone }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="exportCsv"
                    wire:loading.attr="disabled"
                    wire:target="exportCsv"
                    class="fi-btn fi-btn-size-md inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-950 shadow-sm ring-1 ring-gray-950/10 outline-none transition hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10"
                >
                    <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                    <span wire:loading.remove wire:target="exportCsv">Export CSV</span>
                    <span wire:loading wire:target="exportCsv">Preparing export...</span>
                </button>
            </div>

            <div class="flex flex-wrap gap-2" aria-label="Report period presets">
                @foreach ($reportPresets as $key => $label)
                    <button
                        type="button"
                        wire:click="applyPreset('{{ $key }}')"
                        wire:loading.attr="disabled"
                        wire:target="applyPreset"
                        @class([
                            'fi-btn rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            'bg-primary-600 text-white shadow-sm hover:bg-primary-500' => $activePreset === $key,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $activePreset !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <form wire:submit="applyDateRange" wire:loading.class="opacity-60" wire:target="applyDateRange,applyPreset" class="flex flex-wrap items-end gap-4 border-t border-gray-100 pt-4 dark:border-white/5">
                <div class="w-44">
                    <label for="report-date-from" class="text-sm font-medium text-gray-950 dark:text-white">From</label>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input
                            type="date"
                            id="report-date-from"
                            wire:model="dateInputFrom"
                            aria-describedby="report-date-from-error"
                        />
                    </x-filament::input.wrapper>
                    @error('dateInputFrom')
                        <p id="report-date-from-error" class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="w-44">
                    <label for="report-date-until" class="text-sm font-medium text-gray-950 dark:text-white">Until</label>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input
                            type="date"
                            id="report-date-until"
                            wire:model="dateInputUntil"
                            aria-describedby="report-date-until-error"
                        />
                    </x-filament::input.wrapper>
                    @error('dateInputUntil')
                        <p id="report-date-until-error" class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="applyDateRange"
                    class="fi-btn fi-btn-size-md rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition hover:bg-primary-500"
                >
                    <span wire:loading.remove wire:target="applyDateRange">Apply range</span>
                    <span wire:loading wire:target="applyDateRange">Applying range...</span>
                </button>
            </form>

            <p wire:loading wire:target="applyDateRange,applyPreset" class="text-sm text-gray-500 dark:text-gray-400" role="status" aria-live="polite">
                Updating report...
            </p>
        </div>
    </div>

    @if ($reportStats !== [])
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4" aria-label="Report key metrics">
            @foreach ($reportStats as $stat)
                <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $stat->getLabel() }}</span>
                    <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $stat->getValue() }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($reportCharts !== [])
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2" aria-label="Report charts">
            @foreach ($reportCharts as $chart)
                @php($chartKey = 'report-chart-'.$chart['key'].'-'.md5(json_encode($chart['data'])))
                <div wire:key="{{ $chartKey }}">
                    @livewire(\App\Filament\Widgets\ReportChartWidget::class, [
                        'chartType' => $chart['type'],
                        'chartData' => $chart['data'],
                        'chartHeading' => $chart['heading'],
                        'chartDescription' => $chart['description'],
                        'chartOptions' => $chart['options'],
                    ], key($chartKey))
                </div>
            @endforeach
        </div>
    @endif

    <div class="space-y-6">
        @forelse ($reportSections as $section)
            <section class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" aria-labelledby="report-section-{{ $loop->index }}">
                <div class="border-b border-gray-100 p-4 dark:border-white/5">
                    <h2 id="report-section-{{ $loop->index }}" class="text-base font-semibold text-gray-950 dark:text-white">{{ $section['title'] }}</h2>
                    @if (filled($section['description'] ?? null))
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $section['description'] }}</p>
                    @endif
                </div>

                @if ($section['has_data'])
                    <div class="space-y-4 p-4">
                        @foreach ($section['rows'] as $row)
                            <div>
                                <div class="mb-1 flex items-baseline justify-between gap-4 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>
                                    <span class="shrink-0 text-gray-500 dark:text-gray-400">
                                        {{ $row['value'] }} <span class="ml-1 tabular-nums">({{ $row['percentage'] }}%)</span>
                                    </span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                    <div
                                        class="h-full rounded-full bg-primary-600"
                                        style="width: {{ $row['percentage'] }}%;"
                                        role="progressbar"
                                        aria-label="{{ $row['label'] }} share"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow="{{ $row['percentage'] }}"
                                    ></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center px-4 py-12 text-center">
                        <x-filament::icon icon="heroicon-o-chart-bar" class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                        <p class="mt-3 text-sm font-medium text-gray-600 dark:text-gray-300">No records in this period</p>
                        <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">Try a different date range.</p>
                    </div>
                @endif
            </section>
        @empty
            @if ($errors->isEmpty())
                <div class="fi-section rounded-xl bg-white px-4 py-12 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">No records in this period</p>
                    <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">Try a different date range.</p>
                </div>
            @endif
        @endforelse
    </div>
</x-filament-panels::page>
