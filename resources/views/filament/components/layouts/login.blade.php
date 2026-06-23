@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <style>
        body, html { background: #DCEEFB !important; }
        .fi-simple-layout, .fi-simple-main-ctn, .fi-simple-main { background: transparent !important; max-width: 100% !important; padding: 0 !important; }
        .eyecare-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 3rem 1rem; background: #DCEEFB; }
        .eyecare-inner { display: flex; width: 100%; max-width: 72rem; align-items: stretch; gap: 1.5rem; }
        .eyecare-card { background: #fff; border-radius: 1rem; padding: 2.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); flex: 0 0 auto; width: 100%; max-width: 26rem; display: flex; flex-direction: column; justify-content: center; }
        .eyecare-card h1 { font-size: 2.25rem; font-weight: 900; letter-spacing: .05em; color: #111827; text-align: center; margin: 0 0 .5rem; }
        .eyecare-card p { font-size: .875rem; font-style: italic; color: #6b7280; text-align: center; margin: 0 0 2rem; }
        .eyecare-images { flex: 1; display: none; gap: .75rem; }
        @media (min-width: 768px) { .eyecare-images { display: flex; } }
        .eyecare-img-left { flex: 1; }
        .eyecare-img-left img { width: 100%; height: 100%; object-fit: cover; border-radius: 1rem; }
        .eyecare-img-right { flex: 1; display: flex; flex-direction: column; gap: .75rem; }
        .eyecare-img-right img { width: 100%; height: 50%; object-fit: cover; border-radius: 1rem; }

        /* Dark mode — Filament adds .dark to <html> */
        .dark body, .dark html { background: #111827 !important; }
        .dark .eyecare-wrap { background: #111827; }
        .dark .eyecare-card { background: #1f2937; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
        .dark .eyecare-card h1 { color: #f9fafb; }
        .dark .eyecare-card p { color: #9ca3af; }
    </style>

    <div class="eyecare-wrap">
        <div class="eyecare-inner">
            <div class="eyecare-card">
                <h1>EYECARE</h1>
                <p>"When elegance meets convenience"</p>
                {{ $slot }}
            </div>

            <div class="eyecare-images">
                <div class="eyecare-img-left">
                    <img src="{{ asset('images/login/eyeglass1.png') }}" alt="Person wearing eyeglasses" />
                </div>
                <div class="eyecare-img-right">
                    <img src="{{ asset('images/login/eyeglass2.png') }}" alt="Eyeglasses on display" />
                    <img src="{{ asset('images/login/eyeglass3.png') }}" alt="Eyeglasses product" />
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
