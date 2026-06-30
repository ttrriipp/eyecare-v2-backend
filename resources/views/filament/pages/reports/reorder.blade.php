<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Items at or below reorder threshold</x-slot>
        <x-slot name="description">Use this list to determine what needs to be reordered from suppliers.</x-slot>

        <div class="mb-4 flex justify-end">
            <button
                type="button"
                wire:click="exportCsv"
                class="fi-btn fi-btn-size-md inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold outline-none transition fi-btn-color-gray fi-color-gray bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
            >
                <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                Export CSV
            </button>
        </div>

        @php $items = $this->getItems(); @endphp

        @if($items->isEmpty())
            <div class="fi-ta-empty-state px-6 py-12">
                <div class="fi-ta-empty-state-content mx-auto grid max-w-lg justify-items-center text-center">
                    <div class="fi-ta-empty-state-icon-ctn mb-4 rounded-full bg-gray-100 p-3 dark:bg-gray-500/20">
                        <x-filament::icon icon="heroicon-o-check-circle" class="fi-ta-empty-state-icon h-6 w-6 text-gray-400 dark:text-gray-500" />
                    </div>
                    <h4 class="fi-ta-empty-state-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">All items above threshold</h4>
                    <p class="fi-ta-empty-state-description text-sm text-gray-500 dark:text-gray-400 mt-1">No products currently need reordering.</p>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                    <thead class="divide-y divide-gray-200 dark:divide-white/5">
                        <tr>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 fi-table-header-cell-product text-start text-sm font-semibold text-gray-950 dark:text-white">Product</th>
                            <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">Variant / SKU</th>
                            <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">Supplier Contact</th>
                            <th class="fi-ta-header-cell px-3 py-3.5 text-end text-sm font-semibold text-gray-950 dark:text-white">Stock</th>
                            <th class="fi-ta-header-cell px-3 py-3.5 text-end text-sm font-semibold text-gray-950 dark:text-white">Threshold</th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:last-of-type:pe-6 text-end text-sm font-semibold text-gray-950 dark:text-white">Deficit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                        @foreach($items as $item)
                            <tr>
                                <td class="fi-ta-cell px-3 py-4 sm:first-of-type:ps-6 text-sm text-gray-950 dark:text-white">
                                    @if($item['product_id'])
                                        <a href="{{ route('filament.admin.resources.products.edit', $item['product_id']) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $item['product'] }}</a>
                                    @else
                                        {{ $item['product'] }}
                                    @endif
                                </td>
                                <td class="fi-ta-cell px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $item['variant'] }} <span class="text-xs text-gray-400 dark:text-gray-500">{{ $item['sku'] }}</span></td>
                                <td class="fi-ta-cell px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $item['supplier'] }}</td>
                                <td class="fi-ta-cell px-3 py-4 text-end text-sm font-medium {{ $item['stock'] === 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ $item['stock'] }}</td>
                                <td class="fi-ta-cell px-3 py-4 text-end text-sm text-gray-500 dark:text-gray-400">{{ $item['threshold'] }}</td>
                                <td class="fi-ta-cell px-3 py-4 sm:last-of-type:pe-6 text-end text-sm font-semibold text-danger-600 dark:text-danger-400">-{{ $item['deficit'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
