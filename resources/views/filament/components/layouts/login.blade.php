@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 sm:p-6 lg:p-12">
        <div class="flex w-full max-w-5xl flex-col items-center gap-8 lg:flex-row lg:gap-12">
            <div class="w-full max-w-[22rem] rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8 lg:p-10 dark:border-white/10 dark:bg-slate-900">
                <div class="mb-8 flex flex-col items-center gap-3 text-center">
                    @include('filament.admin.logo')

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Staff sign-in for Padilla Optical Clinic
                    </p>
                </div>

                {{ $slot }}
            </div>

            <div class="w-full max-w-[32rem]">
                <img
                    src="{{ asset('images/eyecare-light.svg') }}"
                    alt="EyeCare illustration"
                    class="block w-full max-w-full dark:hidden"
                />
                <img
                    src="{{ asset('images/dark-mode.svg') }}"
                    alt="EyeCare illustration"
                    class="hidden w-full max-w-full dark:block"
                />
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
