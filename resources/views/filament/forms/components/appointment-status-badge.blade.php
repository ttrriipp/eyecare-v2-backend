@php
    $color = match($getState()) {
        'Scheduled' => 'info',
        'Checked In' => 'warning',
        'Fulfilled' => 'success',
        'Cancelled' => 'danger',
        'No Show' => 'gray',
        default => 'gray',
    };
@endphp

<x-filament::badge :color="$color">
    {{ $getState() }}
</x-filament::badge>
