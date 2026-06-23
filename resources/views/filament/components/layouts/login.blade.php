@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="flex min-h-screen items-center justify-center bg-[#DCEEFB] px-4 py-12">
        <div class="flex w-full max-w-5xl items-stretch gap-6">

            {{-- Left: Login Card --}}
            <div class="flex w-full max-w-md flex-col justify-center rounded-2xl bg-white p-10 shadow-sm">
                <div class="mb-8 text-center">
                    <h1 class="text-4xl font-black tracking-wide text-gray-900">EYECARE</h1>
                    <p class="mt-2 text-sm italic text-gray-500">"When elegance meets convenience"</p>
                </div>

                {{ $slot }}
            </div>

            {{-- Right: Image Collage --}}
            <div class="hidden flex-1 gap-3 md:flex">
                <div class="flex-1">
                    <img
                        src="{{ asset('images/login/eyeglass1.png') }}"
                        alt="Person wearing eyeglasses"
                        class="h-full w-full rounded-2xl object-cover"
                    />
                </div>
                <div class="flex flex-1 flex-col gap-3">
                    <img
                        src="{{ asset('images/login/eyeglass2.png') }}"
                        alt="Eyeglasses on display"
                        class="h-1/2 w-full rounded-2xl object-cover"
                    />
                    <img
                        src="{{ asset('images/login/eyeglass3.png') }}"
                        alt="Eyeglasses product"
                        class="h-1/2 w-full rounded-2xl object-cover"
                    />
                </div>
            </div>

        </div>
    </div>
</x-filament-panels::layout.base>
