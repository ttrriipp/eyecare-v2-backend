<x-filament::button
    type="button"
    color="success"
    icon="heroicon-o-check-circle"
    size="sm"
    x-on:click="if(confirm('Are you sure you want to complete this visit? This action cannot be undone.')) $dispatch('completeVisit')"
>
    Complete Visit
</x-filament::button>
