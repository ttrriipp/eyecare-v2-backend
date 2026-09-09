@extends('layouts.legal')

@section('title', 'Privacy notice')

@section('content')
    <div class="flex flex-col gap-10">
        <header class="flex flex-col gap-3">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700 dark:text-sky-300">Patient mobile app · capstone deployment</p>
            <h1 class="text-4xl font-semibold tracking-tight text-slate-950 dark:text-white sm:text-5xl">Privacy notice</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Effective {{ config('app.privacy_policy_effective_date', '2026-08-01') }} · Version {{ config('app.privacy_policy_version', '2026-08') }}
            </p>
        </header>

        <aside class="flex gap-4 rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm leading-6 text-sky-950 dark:border-sky-900/80 dark:bg-sky-950/40 dark:text-sky-100" aria-label="Demonstration scope">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path stroke-linecap="round" d="M12 10.5v5.25M12 7.5h.01" />
            </svg>
            <p>
                This is a temporary capstone deployment for data gathering and demonstration. Use synthetic test data only. Do not enter real patient, health, payment, government, or other sensitive personal information. Patient registration and phone authentication may be unavailable while the demo configuration is active.
            </p>
        </aside>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Purpose and scope</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                This notice explains how EyeCare handles information when you create or use an account in the patient mobile app. It covers the app, its API, and the hosting services that support the temporary capstone environment.
            </p>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                This is a short-lived academic project, not a production healthcare privacy program or legal advice. The app is not for live clinic operations or urgent medical care.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Information processed</h2>
            <ul class="flex list-disc flex-col gap-3 pl-5 leading-7 text-slate-600 dark:text-slate-300">
                <li><strong class="font-semibold text-slate-900 dark:text-white">Registration and account data:</strong> your name, date of birth, verified phone number, optional email address, password, device or installation identifiers, authentication tokens, and the policy versions you accept. Passwords are stored as protected credentials, not readable text.</li>
                <li><strong class="font-semibold text-slate-900 dark:text-white">Patient-app activity:</strong> information you or an authorized clinic provides for account linking, appointment requests, prescriptions, optical orders, messages, attachments, notifications, and other features enabled for the demonstration.</li>
                <li><strong class="font-semibold text-slate-900 dark:text-white">Operational data:</strong> request, error, job, security, and audit records used to operate the service, prevent misuse, support users, and investigate failures. We aim to keep logs free of unnecessary clinical details.</li>
            </ul>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">How information is used</h2>
            <ul class="flex list-disc flex-col gap-3 pl-5 leading-7 text-slate-600 dark:text-slate-300">
                <li>Create and secure your account, verify contact ownership, and protect patient access.</li>
                <li>Link an account to the correct clinic record when an invitation or staff-reviewed request is used.</li>
                <li>Provide the patient-app workflows included in the capstone and measure reliability during evaluation.</li>
                <li>Respond to support requests, prevent abuse, and diagnose application or deployment errors.</li>
            </ul>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                We do not sell information or use it for advertising. Hosting, database, object-storage, email, SMS, and monitoring providers may process limited information only as needed to operate the service. SMS and phone authentication are disabled in the current demo unless the deployment is deliberately reconfigured.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Use synthetic data for this capstone</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                The capstone team supplies fictional accounts and records for demonstration. Do not use another person’s identity, upload real health information, or treat a generated record as a real clinical record. If you accidentally submit sensitive information, notify the capstone team immediately so it can be removed.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Security, choices, and retention</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                The deployment uses HTTPS, access controls, protected storage, password controls, and audit logging appropriate to a temporary demonstration. No online service can guarantee absolute security, so never submit information that would create a real-world risk if exposed.
            </p>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                You may ask the capstone team that gave you access to review, correct, or remove your demo account information, or to report an accidental disclosure. This environment is scheduled to be taken offline no later than <strong class="font-semibold text-slate-900 dark:text-white">October 7, 2026</strong>; access will be revoked and demonstration data, stored objects, and backups will be removed as part of teardown, subject to approved academic recordkeeping.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Policy versions and questions</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                When registration is enabled, the app can retrieve the current policy versions and links from the public <code class="rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-800 dark:bg-slate-800 dark:text-slate-200">/api/v1/auth/policies</code> endpoint, and the server records the versions and acceptance time you submit. Questions or data-handling concerns should be directed to the capstone team or clinic contact that provided your app access.
            </p>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                See the <a href="{{ route('legal.terms') }}" class="font-semibold text-sky-700 underline decoration-sky-300 underline-offset-4 hover:text-sky-900 dark:text-sky-300 dark:decoration-sky-700 dark:hover:text-sky-200">terms of use</a> for the conditions that apply to the patient mobile app.
            </p>
        </section>
    </div>
@endsection
