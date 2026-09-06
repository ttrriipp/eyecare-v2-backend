<div class="space-y-4">
    @if ($lots->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No batches have been received for this variant.</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Contact-lens inventory batches</caption>
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">Lot</th>
                        <th scope="col" class="px-4 py-3">Expires</th>
                        <th scope="col" class="px-4 py-3">On hand</th>
                        <th scope="col" class="px-4 py-3">Received</th>
                        <th scope="col" class="px-4 py-3">Reference</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($lots as $lot)
                        @php
                            $status = $lot->isExpired()
                                ? 'Expired'
                                : ($lot->quantity_on_hand > 0 ? 'Usable' : 'Depleted');
                        @endphp
                        <tr class="text-gray-700 dark:text-gray-200">
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $lot->lot_number }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span>{{ $lot->expires_on->toDateString() }}</span>
                                <span class="block text-xs text-gray-500">{{ $lot->expires_on->format('M d, Y') }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $lot->quantity_on_hand }}</td>
                            <td class="px-4 py-3">
                                <span>{{ $lot->received_at->format('M d, Y') }}</span>
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
