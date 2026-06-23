@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="min-h-screen bg-blue-200 flex items-center justify-center px-4 py-12">
        <div class="flex w-full max-w-4xl items-stretch gap-6">

            {{-- Left: Login Card --}}
            <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-10 flex flex-col justify-center">
                <div class="mb-8 text-center">
                    <h1 class="text-4xl font-black tracking-tight text-gray-900 uppercase">EyeCare</h1>
                    <p class="mt-1 text-sm italic text-gray-500">"When elegance meets convenience"</p>
                </div>

                {{ $slot }}
            </div>

            {{-- Right: Image Collage --}}
            <div class="hidden md:flex flex-1 gap-3">
                <div class="flex-1">
                    <img
                        src="{{ asset('images/login/eyeglass1.png') }}"
                        alt="Eyeglasses product"
                        class="h-full w-full object-cover rounded-2xl"
                    />
                </div>

                <div class="flex flex-1 flex-col gap-3">
                    <img
                        src="{{ asset('images/login/eyeglass2.png') }}"
                        alt="Eyeglasses on case"
                        class="h-1/2 w-full object-cover rounded-2xl"
                    />
                    <img
                        src="{{ asset('images/login/eyeglass3.png') }}"
                        alt="Optical store display"
                        class="h-1/2 w-full object-cover rounded-2xl"
                    />
                </div>
            </div>

        </div>
    </div>
</x-filament-panels::layout.base>
