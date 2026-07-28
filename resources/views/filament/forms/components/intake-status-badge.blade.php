@php
    $color = match($getState()) {
        'Draft' => 'warning',
        'Submitted' => 'info',
        'Verified' => 'success',
        default => 'gray',
    };
@endphp

<x-filament::badge :color="$color">
    {{ $getState() }}
</x-filament::badge>
