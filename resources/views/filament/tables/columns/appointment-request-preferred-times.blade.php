@php
    $summary = $getState();
@endphp

<div class="flex flex-wrap gap-1.5">
    @forelse ($summary['preferences'] ?? [] as $preference)
        @php
            $chipClasses = $summary['show_availability']
                ? match ($preference['available']) {
                    true => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
                    false => 'bg-danger-50 text-danger-600 dark:bg-danger-500/15 dark:text-danger-400',
                    default => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400',
                }
                : ($preference['label'] === 'Primary'
                    ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-400'
                    : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400');
            $availabilityLabel = $preference['availability_label'] ?? 'Availability needs review';
        @endphp

        <span
            @class([
                'inline-flex max-w-full items-center gap-1 rounded-md px-2 py-1 text-xs font-medium',
                $chipClasses,
            ])
        >
            <span class="shrink-0 font-semibold">
                {{ $preference['label'] }}
            </span>

            <span class="truncate">
                {{ $preference['time'] }}
            </span>

            @if ($summary['show_availability'])
                @php
                    $availabilityClasses = match ($preference['available']) {
                        true => 'text-success-600 dark:text-success-400',
                        false => 'text-danger-600 dark:text-danger-400',
                        default => 'text-gray-500 dark:text-gray-400',
                    };
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
        </span>
    @empty
        <span class="text-sm text-gray-500 dark:text-gray-400">No preferred times</span>
    @endforelse
</div>
