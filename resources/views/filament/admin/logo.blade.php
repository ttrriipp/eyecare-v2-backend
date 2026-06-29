{{-- Eyecare brand lockup: a biconvex lens that reads as an eye, plus the wordmark.
     The mark uses the brand blue (#4F8DD7) in both modes; the wordmark inherits a
     light/dark-aware text color. Rendered in the panel sidebar/topbar only. --}}
<div class="fi-logo-eyecare flex items-center gap-2.5">
    <svg
        aria-hidden="true"
        class="h-8 w-8 shrink-0"
        viewBox="0 0 36 36"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
    >
        {{-- Biconvex lens / eye outline --}}
        <path
            d="M2.5 18C9 8 27 8 33.5 18C27 28 9 28 2.5 18Z"
            fill="none"
            stroke="#4F8DD7"
            stroke-width="2.25"
            stroke-linejoin="round"
        />
        {{-- Iris --}}
        <circle cx="18" cy="18" r="6.25" fill="#4F8DD7" />
        {{-- Catchlight --}}
        <circle cx="15.7" cy="15.7" r="1.7" fill="#FFFFFF" fill-opacity="0.9" />
    </svg>

    <span class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
        Eyecare
    </span>
</div>
