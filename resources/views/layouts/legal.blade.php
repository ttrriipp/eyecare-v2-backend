<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="EyeCare capstone demonstration policies">

        <title>@yield('title') · EyeCare</title>

        @fonts

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css'])
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:text-sm focus:font-medium focus:text-slate-900 focus:shadow-lg dark:focus:bg-slate-900 dark:focus:text-white"
        >
            Skip to content
        </a>

        <header class="border-b border-slate-200/80 bg-white/90 dark:border-slate-800 dark:bg-slate-900/90">
            <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-5 lg:px-8">
                <a href="{{ url('/admin/login') }}" class="flex items-center gap-3" aria-label="EyeCare staff sign-in">
                    <img
                        src="{{ asset('images/eyecare.svg') }}"
                        alt=""
                        aria-hidden="true"
                        class="h-9 w-9 rounded-lg"
                    >
                    <span class="text-lg font-semibold tracking-tight text-slate-950 dark:text-white">EyeCare</span>
                </a>

                <nav class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2 text-sm" aria-label="Policy navigation">
                    <a
                        href="{{ route('legal.privacy') }}"
                        @if (request()->routeIs('legal.privacy')) aria-current="page" @endif
                        class="font-medium text-slate-600 underline-offset-4 hover:text-slate-950 hover:underline dark:text-slate-300 dark:hover:text-white"
                    >
                        Privacy notice
                    </a>
                    <a
                        href="{{ route('legal.terms') }}"
                        @if (request()->routeIs('legal.terms')) aria-current="page" @endif
                        class="font-medium text-slate-600 underline-offset-4 hover:text-slate-950 hover:underline dark:text-slate-300 dark:hover:text-white"
                    >
                        Terms of use
                    </a>
                    <a
                        href="{{ url('/admin/login') }}"
                        class="rounded-full bg-slate-900 px-4 py-2 font-medium text-white transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200 dark:focus:ring-offset-slate-900"
                    >
                        Staff sign-in
                    </a>
                </nav>
            </div>
        </header>

        <main id="main-content" class="mx-auto w-full max-w-5xl flex-1 px-6 py-12 sm:py-16 lg:px-8">
            <div class="mx-auto max-w-3xl">
                @yield('content')
            </div>
        </main>

        <footer class="border-t border-slate-200/80 bg-white/70 dark:border-slate-800 dark:bg-slate-900/70">
            <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-6 text-sm text-slate-500 dark:text-slate-400 lg:px-8">
                <p>EyeCare · temporary academic demonstration</p>
                <p>For authorized evaluators and staff only</p>
            </div>
        </footer>
    </body>
</html>
