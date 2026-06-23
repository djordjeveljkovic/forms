<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Forms') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold" wire:navigate>
                <span class="flex h-8 w-8 items-center justify-center rounded-md bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                    <x-app-logo-icon class="size-5 fill-current" />
                </span>
                <span>{{ config('app.name', 'Forms') }}</span>
            </a>
            <nav class="flex items-center gap-3 text-sm">
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center rounded-md border border-zinc-200 px-3 py-1.5 font-medium hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600" wire:navigate>
                        {{ __('Dashboard') }}
                    </a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="inline-flex items-center rounded-md px-3 py-1.5 font-medium hover:text-zinc-700 dark:hover:text-zinc-300" wire:navigate>
                            {{ __('Log in') }}
                        </a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="inline-flex items-center rounded-md border border-zinc-900 bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-800 dark:border-white dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200" wire:navigate>
                            {{ __('Get started') }}
                        </a>
                    @endif
                @endauth
            </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl px-6 pb-16">
            <section class="grid gap-8 py-12 md:grid-cols-2 md:items-center md:py-20">
                <div>
                    <flux:heading size="xl" level="1" class="text-4xl font-semibold tracking-tight md:text-5xl">
                        {{ __('Forms your way.') }}
                    </flux:heading>
                    <p class="mt-4 max-w-prose text-base text-zinc-600 dark:text-zinc-400">
                        {{ __('Build, share, and process web forms without writing a backend. Each form gets a secure API endpoint, a public schema, an email pipeline, and a dashboard for inspecting every submission.') }}
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        @auth
                            <a href="{{ route('dashboard.forms.create') }}" class="inline-flex items-center rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200" wire:navigate>
                                {{ __('Create a form') }}
                            </a>
                            <a href="{{ route('dashboard.forms.index') }}" class="inline-flex items-center rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600" wire:navigate>
                                {{ __('View my forms') }}
                            </a>
                        @else
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="inline-flex items-center rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200" wire:navigate>
                                    {{ __('Create your first form') }}
                                </a>
                            @endif
                            <a href="{{ route('login') }}" class="inline-flex items-center rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600" wire:navigate>
                                {{ __('Log in') }}
                            </a>
                        @endauth
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <pre class="overflow-x-auto rounded-md bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100"><code>POST /api/forms/contact
Content-Type: application/json
X-Form-Key: &lt;your-api-key&gt;

{
  "data": {
    "email": "jane@example.com",
    "message": "Hello there!"
  }
}</code></pre>
                    <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Each form ships with a per-form API key, configurable rate limits, allowed-origin checks, and a JSON schema for its fields.') }}
                    </p>
                </div>
            </section>

            <section class="grid gap-6 py-8 md:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading size="lg" level="3" class="text-base font-semibold">{{ __('Define fields') }}</flux:heading>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Configure the inputs once. Each submission is validated against the same schema, with type-aware rules for email, number, date, and more.') }}
                    </p>
                </div>
                <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading size="lg" level="3" class="text-base font-semibold">{{ __('Forward to email') }}</flux:heading>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Send submissions to as many recipients as you need, with retries, failed-job replay, and a per-job timeline.') }}
                    </p>
                </div>
                <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading size="lg" level="3" class="text-base font-semibold">{{ __('Inspect everything') }}</flux:heading>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Search, filter, mark as read or spam, archive, and export every submission. The dashboard never blocks on a new submission.') }}
                    </p>
                </div>
            </section>
        </main>
    </body>
</html>
