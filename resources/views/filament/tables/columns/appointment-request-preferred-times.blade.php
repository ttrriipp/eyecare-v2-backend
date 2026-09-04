@php
    $summary = $getState();
@endphp

<div class="space-y-1">
    @forelse ($summary['preferences'] ?? [] as $preference)
        <div class="flex min-w-0 items-center gap-x-2">
            <span class="w-12 shrink-0 text-sm font-medium text-gray-950 dark:text-white">
                {{ $preference['label'] }}
            </span>

            <span class="min-w-0 truncate text-sm text-gray-600 dark:text-gray-300">
                {{ $preference['time'] }}
            </span>

            @if ($summary['show_availability'])
                @php
                    $availabilityClasses = match ($preference['available']) {
                        true => 'text-success-600 dark:text-success-400',
                        false => 'text-danger-600 dark:text-danger-400',
                        default => 'text-gray-500 dark:text-gray-400',
                    };
                    $availabilityLabel = $preference['availability_label'] ?? 'Availability needs review';
                @endphp

                <span
                    @class([
                        'inline-flex shrink-0 items-center',
                        $availabilityClasses,
                    ])
                    role="status"
                    aria-label="{{ $availabilityLabel }}"
                    title="{{ $availabilityLabel }}"
                >
                    @if ($preference['available'] === true)
                        <x-heroicon-s-check class="h-3.5 w-3.5" />
                    @elseif ($preference['available'] === false)
                        <x-heroicon-s-x-mark class="h-3.5 w-3.5" />
                    @else
                        <x-heroicon-o-question-mark-circle class="h-3.5 w-3.5" />
                    @endif
                    <span class="sr-only">{{ $availabilityLabel }}</span>
                </span>
            @endif
        </div>
    @empty
        <span class="text-sm text-gray-500 dark:text-gray-400">No preferred times</span>
    @endforelse
</div>
