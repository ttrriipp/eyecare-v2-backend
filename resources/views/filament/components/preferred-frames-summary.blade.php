@if($frames->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400">
        No preferred frames
    </div>
@else
    <div class="space-y-3">
        @foreach($frames as $frame)
            @php
                $variant = $frame->variant;
                $product = $variant?->product;
                $frameUrl = $frameUrls[$frame->id] ?? null;
                $availability = match (true) {
                    $variant === null => 'Inactive',
                    $variant->trashed() || !$variant->is_active => 'Inactive',
                    $product === null => 'Inactive',
                    $product->trashed() || !$product->is_active => 'Inactive',
                    $variant->stock_quantity <= 0 => 'Out of stock',
                    $variant->isLowStock() => 'Low stock',
                    default => 'Available',
                };
                $imagePath = is_array($variant?->images) ? ($variant->images[0] ?? null) : null;
                $imageUrl = match (true) {
                    ! is_string($imagePath) || trim($imagePath) === '' => null,
                    filter_var($imagePath, FILTER_VALIDATE_URL) !== false => $imagePath,
                    default => \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath),
                };
                $availabilityClasses = match ($availability) {
                    'Available' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                    'Low stock' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                    'Out of stock' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                    default => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                };
            @endphp
            @if($frameUrl !== null)
                <a href="{{ $frameUrl }}" class="group flex items-center gap-3 rounded-md transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:hover:bg-white/5">
            @else
                <div class="flex items-center gap-3">
            @endif
                @if($imageUrl !== null)
                    <img src="{{ $imageUrl }}" alt="" class="h-10 w-10 rounded object-cover">
                @else
                    <div class="flex h-10 w-10 items-center justify-center rounded bg-gray-200 dark:bg-gray-700">
                        <x-heroicon-o-eye class="h-5 w-5 text-gray-400" />
                    </div>
                @endif
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium">
                        {{ $product?->name ?? 'Unknown frame' }}
                    </div>
                    <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ $variant?->name ?? 'Unknown variant' }}
                        @if($variant?->sku)
                            · {{ $variant->sku }}
                        @endif
                    </div>
                    <div class="text-xs text-gray-400">
                        {{ $frame->created_at?->format('M j, Y') ?? '—' }}
                    </div>
                </div>
                <span class="inline-flex shrink-0 items-center rounded px-2 py-0.5 text-xs font-medium {{ $availabilityClasses }}">
                    {{ $availability }}
                </span>
            @if($frameUrl !== null)
                </a>
            @else
                </div>
            @endif
        @endforeach

        @if($total > 0 && $patientUrl !== null)
            <a href="{{ $patientUrl }}" class="text-xs text-primary-600 hover:text-primary-500 dark:text-primary-400">
                View all preferred frames →
            </a>
        @endif
    </div>
@endif
