{{-- EyeCare brand lockup: a biconvex lens that reads as an eye, plus the wordmark.
     The mark uses the brand blue (#4F8DD7) in both modes; the wordmark inherits a
     light/dark-aware text color. Rendered in the panel sidebar/topbar only. --}}
<div class="fi-logo-eyecare flex items-center gap-2.5">
    <img
        src="{{ asset('images/eyecare.svg') }}"
        alt=""
        aria-hidden="true"
        class="h-8 w-8 shrink-0 rounded"
    />

    <span class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
        EyeCare
    </span>
</div>
