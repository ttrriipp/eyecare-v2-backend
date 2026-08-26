@php
    $patient = $record ?? null;
    $account = $patient?->account;
    $frames = $account
        ? $account->savedFrames()->with(['variant.product'])->limit(3)->get()
        : collect();
@endphp

@if($account === null)
    <div class="text-sm text-gray-500 dark:text-gray-400">
        No linked account
    </div>
@elseif($frames->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400">
        No preferred frames
    </div>
@else
    <div class="space-y-3">
        @foreach($frames as $frame)
            @php
                $variant = $frame->variant;
                $product = $variant?->product;
                $available = $variant && $variant->is_active && !$variant->trashed()
                    && $product && $product->is_active && !$product->trashed()
                    && $variant->stock_quantity > 0;
            @endphp
            <div class="flex items-center gap-3">
                @if($variant?->images && count($variant->images) > 0)
                    <img src="{{ $variant->images[0] }}" alt="" class="w-10 h-10 rounded object-cover">
                @else
                    <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                        <x-heroicon-o-eye class="w-5 h-5 text-gray-400" />
                    </div>
                @endif
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium truncate">
                        {{ $product?->name ?? 'Unknown' }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                        {{ $variant?->name ?? 'Unknown' }}
                        @if($variant?->sku)
                            · {{ $variant->sku }}
                        @endif
                    </div>
                    <div class="text-xs text-gray-400">
                        {{ $frame->created_at->format('M j, Y') }}
                    </div>
                </div>
                <div>
                    @if($available)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                            Available
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            Unavailable
                        </span>
                    @endif
                </div>
            </div>
        @endforeach

        @if($account->savedFrames()->count() > 3)
            <a href="{{ route('filament.admin.resources.patients.resource', ['record' => $patient->getRouteKey()]) }}"
               class="text-xs text-primary-600 hover:text-primary-500 dark:text-primary-400">
                View all preferred frames →
            </a>
        @endif
    </div>
@endif
