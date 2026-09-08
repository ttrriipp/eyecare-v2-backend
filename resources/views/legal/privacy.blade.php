@extends('layouts.legal')

@section('title', 'Privacy notice')

@section('content')
    <div class="flex flex-col gap-10">
        <header class="flex flex-col gap-3">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700 dark:text-sky-300">Policy · capstone deployment</p>
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
                EyeCare is a temporary staff-only academic demonstration. It uses synthetic clinic records and is not intended to collect real patient, participant, or clinical data.
            </p>
        </aside>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Purpose and scope</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                This notice describes the limited information handled by the EyeCare capstone environment while it is available to authorized evaluators and staff. It applies to the web admin panel, connected Android demonstration client, and the services that support them.
            </p>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                This is a deployment notice for a short-lived academic project, not a production privacy program or legal advice. Do not use the environment for live clinic operations.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Information processed</h2>
            <ul class="flex list-disc flex-col gap-3 pl-5 leading-7 text-slate-600 dark:text-slate-300">
                <li><strong class="font-semibold text-slate-900 dark:text-white">Staff account details:</strong> name, email address, role, account status, and authentication or audit events needed to protect the panel.</li>
                <li><strong class="font-semibold text-slate-900 dark:text-white">Synthetic clinic records:</strong> fictional patients, appointments, prescriptions, catalog items, inventory, messages, and related workflow records created for demonstration and testing.</li>
                <li><strong class="font-semibold text-slate-900 dark:text-white">Operational data:</strong> request, error, job, and security logs used to keep the deployment available and investigate failures. Logs should not contain clinical record contents.</li>
            </ul>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">What this deployment does not collect</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                The demo is configured without participant accounts, participant research data, phone-based authentication, or SMS workflows. Please do not enter real patient information, health information, payment details, government identifiers, or other sensitive personal data.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">How information is used</h2>
            <ul class="flex list-disc flex-col gap-3 pl-5 leading-7 text-slate-600 dark:text-slate-300">
                <li>Authenticate authorized staff and enforce role-based access to demonstration workflows.</li>
                <li>Show the capstone’s appointment, clinical workflow, inventory, messaging, catalog, and augmented-reality proof-of-concept features.</li>
                <li>Monitor reliability, prevent misuse, and diagnose deployment or application errors.</li>
            </ul>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                We do not sell the information in this environment or use it for advertising. Hosting, database, object-storage, email, and monitoring providers may process limited technical data only as needed to operate the demonstration.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Security and retention</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                The deployment uses HTTPS, role-based authorization, protected object storage, password controls, and audit logging appropriate to a temporary demonstration. No online service can guarantee absolute security, so never upload information that would create a real-world risk if exposed.
            </p>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                This environment is scheduled to be taken offline no later than <strong class="font-semibold text-slate-900 dark:text-white">October 7, 2026</strong>. The capstone team will revoke access and remove the demonstration database, stored objects, and backups as part of teardown, subject to any approved academic recordkeeping requirement.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Questions</h2>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                Questions about this notice or a data-handling concern should be directed to the capstone team that provided your staff access. Before teardown, you may ask that team whether an approved synthetic record export is available.
            </p>
            <p class="leading-7 text-slate-600 dark:text-slate-300">
                See the <a href="{{ route('legal.terms') }}" class="font-semibold text-sky-700 underline decoration-sky-300 underline-offset-4 hover:text-sky-900 dark:text-sky-300 dark:decoration-sky-700 dark:hover:text-sky-200">terms of use</a> for the conditions that apply to this demonstration.
            </p>
        </section>
    </div>
@endsection
