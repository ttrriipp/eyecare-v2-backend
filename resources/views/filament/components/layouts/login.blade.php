@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="flex min-h-screen items-center justify-center p-6 lg:p-12">
        <div class="flex items-center gap-12">
            <div class="w-[22rem] shrink-0 rounded-lg border border-slate-200 bg-white p-10 shadow-sm dark:border-white/10 dark:bg-slate-900">
                <div class="mb-8 flex flex-col items-center gap-3 text-center">
                    @include('filament.admin.logo')

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Staff sign-in for Padilla Optical Clinic
                    </p>
                </div>

                {{ $slot }}
            </div>

            <div class="w-[32rem] shrink-0">
                <img
                    src="{{ asset('images/eyecare-light.svg') }}"
                    alt="EyeCare illustration"
                    class="block w-full dark:hidden"
                />
                <img
                    src="{{ asset('images/dark-mode.svg') }}"
                    alt="EyeCare illustration"
                    class="hidden w-full dark:block"
                />
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
