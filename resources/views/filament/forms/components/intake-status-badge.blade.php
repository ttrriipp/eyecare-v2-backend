@php
    $color = match($getState()) {
        'Incomplete' => 'warning',
        'Needs review' => 'info',
        'Verified' => 'success',
        default => 'gray',
    };
@endphp

<x-filament::badge :color="$color">
    {{ $getState() }}
</x-filament::badge>
