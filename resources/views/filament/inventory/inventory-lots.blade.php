<div class="space-y-4">
    @php
        $showExpiry = $showExpiry ?? true;
        $unbatchedQuantity = $unbatchedQuantity ?? 0;
    @endphp

    @if ($lots->isEmpty())
        <p role="status" class="text-sm text-gray-500 dark:text-gray-400">
            No batches have been received for this variant.
            @if (! $showExpiry && $unbatchedQuantity > 0)
                {{ $unbatchedQuantity }} unit(s) remain as legacy opening stock without a batch number.
            @endif
        </p>
    @else
        @if (! $showExpiry && $unbatchedQuantity > 0)
            <p role="status" class="text-sm text-gray-500 dark:text-gray-400">
                {{ $unbatchedQuantity }} unit(s) remain as legacy opening stock without a batch number.
            </p>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Inventory batches</caption>
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ $showExpiry ? 'Lot' : 'Batch number' }}</th>
                        @if ($showExpiry)
                            <th scope="col" class="px-4 py-3">Expires</th>
                        @endif
                        <th scope="col" class="px-4 py-3">On hand</th>
                        <th scope="col" class="px-4 py-3">Received</th>
                        <th scope="col" class="px-4 py-3">Reference</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($lots as $lot)
                        @php
                            $status = $showExpiry
                                ? ($lot->isExpired()
                                    ? 'Expired'
                                    : ($lot->quantity_on_hand > 0 ? 'Usable' : 'Depleted'))
                                : ($lot->quantity_on_hand > 0 ? 'Available' : 'Depleted');
                        @endphp
                        <tr class="text-gray-700 dark:text-gray-200">
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $lot->lot_number }}</td>
                            @if ($showExpiry)
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span>{{ $lot->expires_on->toDateString() }}</span>
                                    <span class="block text-xs text-gray-500">{{ $lot->expires_on->format('M d, Y') }}</span>
                                </td>
                            @endif
                            <td class="px-4 py-3">{{ $lot->quantity_on_hand }}</td>
                            <td class="px-4 py-3">
                                <span>{{ ($lot->purchased_at ?? $lot->received_at)->format('M d, Y') }}</span>
                                <span class="block text-xs text-gray-500">{{ $lot->receivedBy?->full_name ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $lot->source_reference ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
