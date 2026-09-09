@extends('layouts.legal')

@section('title', 'Terms of use')

@section('content')
    <div class="flex flex-col gap-10">
        <header class="flex flex-col gap-3">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700 dark:text-sky-300">Patient mobile app · capstone deployment</p>
            <h1 class="text-4xl font-semibold tracking-tight text-slate-950 dark:text-white sm:text-5xl">Terms of use</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Effective {{ config('app.terms_effective_date', '2026-08-01') }} · Version {{ config('app.terms_version', '2026-08') }}
            </p>
        </header>

        <aside class="flex gap-4 rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm leading-6 text-sky-950 dark:border-sky-900/80 dark:bg-sky-950/40 dark:text-sky-100" aria-label="Demonstration scope">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 19 7v5.25c0 4.08-2.54 7.09-7 8-4.46-.91-7-3.92-7-8V7l7-3.25Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="m9.25 12 1.8 1.8 3.7-4" />
            </svg>
            <p>
                EyeCare is a temporary capstone demonstration. Use the patient mobile app only with synthetic test data supplied for the evaluation. Do not enter real patient or health information.
            </p>
        </aside>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Acceptance and scope</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                By tapping the acceptance controls during patient account registration or using the patient mobile app, you agree to these terms. They apply only to this temporary deployment and do not create a clinic-provider relationship, patient-care relationship, or promise of ongoing service.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Account responsibilities</h2>
            <ul class="flex list-disc flex-col gap-3 pl-5 leading-7 text-slate-600 dark:text-slate-300">
                <li>Provide accurate information for your own account and complete any required phone verification.</li>
                <li>Keep your password, verification codes, and device secure. Do not share your account or token.</li>
                <li>Use the app only for the capstone’s documented scenarios and report defects, security concerns, or accidental disclosure promptly.</li>
            </ul>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Prohibited use</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">You must not:</p>
            <ul class="flex list-disc flex-col gap-3 pl-5 leading-7 text-slate-600 dark:text-slate-300">
                <li>Upload or enter real patient or health information, payment details, government identifiers, or other sensitive personal data.</li>
                <li>Use the app to make, document, or communicate a clinical decision, diagnosis, prescription, or medical advice.</li>
                <li>Share credentials or verification codes, attempt to bypass authorization, probe unrelated systems, or conduct testing outside the agreed capstone scope.</li>
                <li>Copy, publish, or represent synthetic records as real people, real clinical outcomes, or production data.</li>
            </ul>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Availability and changes</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                The capstone team may reset data, change configuration, suspend access, or deploy fixes without notice. The app may be unavailable while phone authentication or SMS is disabled. The demonstration is provided for evaluation and carries no promise of uninterrupted availability, data preservation, or fitness for a production purpose.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Teardown</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                This deployment is scheduled to end no later than <strong class="font-semibold text-slate-900 dark:text-white">October 7, 2026</strong>. At teardown, the capstone team will revoke access and remove the demonstration database, stored objects, and backups, subject to approved academic recordkeeping.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">No medical or legal advice</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                EyeCare is a software demonstration, not a medical device, healthcare provider, or source of medical advice. These terms and the accompanying <a href="{{ route('legal.privacy') }}" class="font-semibold text-sky-700 underline decoration-sky-300 underline-offset-4 hover:text-sky-900 dark:text-sky-300 dark:decoration-sky-700 dark:hover:text-sky-200">privacy notice</a> are written for this temporary academic environment and are not a substitute for legal advice or a production policy review.
            </p>
        </section>
    </div>
@endsection
