@php
    use App\Enums\ArAssetStatus;
@endphp

<div class="space-y-4">
    @php
        $currentAsset = $currentAssetId === null
            ? null
            : $assets->firstWhere('id', (int) $currentAssetId);
    @endphp

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
        <p class="text-sm font-medium text-gray-950 dark:text-white">Current patient model</p>

        @if ($currentAsset !== null)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Version {{ $currentAsset->version }} is active for this variant.
            </p>
        @else
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                No 3D model is currently active for patients.
            </p>
        @endif
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full min-w-[720px] divide-y divide-gray-200 text-left text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 text-xs uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3 font-medium">Version</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium">Published</th>
                    <th class="px-4 py-3 font-medium">SHA-256</th>
                    <th class="px-4 py-3 font-medium">Asset URL</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                @forelse ($assets as $asset)
                    @php
                        $statusLabel = match ($asset->status) {
                            ArAssetStatus::Quarantined => 'Upload received',
                            ArAssetStatus::Validated => 'Awaiting physical review',
                            ArAssetStatus::Approved => 'Approved',
                            ArAssetStatus::Published => 'Published',
                            ArAssetStatus::Rejected => 'Rejected',
                            ArAssetStatus::Superseded => 'Superseded',
                            ArAssetStatus::Disabled => 'Disabled',
                        };
                        $isCurrent = $asset->id === (int) $currentAssetId;
                    @endphp

                    <tr class="{{ $isCurrent ? 'bg-primary-50 dark:bg-primary-950/30' : '' }}">
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">
                            v{{ $asset->version }}
                            @if ($isCurrent)
                                <span class="ml-1 text-xs font-normal text-primary-700 dark:text-primary-300">(current)</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $statusLabel }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $asset->published_at?->format('M j, Y g:i A') ?? '—' }}
                        </td>
                        <td class="max-w-56 break-all px-4 py-3 font-mono text-xs text-gray-700 dark:text-gray-300">
                            {{ $asset->sha256 ?? '—' }}
                        </td>
                        <td class="max-w-56 break-all px-4 py-3 text-gray-700 dark:text-gray-300">
                            @if (filled($asset->url))
                                <a
                                    href="{{ $asset->url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-primary-600 underline hover:text-primary-500 dark:text-primary-400"
                                >
                                    Open published asset
                                </a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-600 dark:text-gray-300">
                            No 3D asset versions have been uploaded.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
