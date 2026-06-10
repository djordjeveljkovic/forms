<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard.index') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="home" :href="route('dashboard.index')" :current="request()->routeIs('dashboard.index')" wire:navigate>
                    {{ __('Overview') }}
                </flux:navbar.item>
                <flux:navbar.item icon="document-text" :href="route('dashboard.forms.index')" :current="request()->routeIs('dashboard.forms.*')" wire:navigate>
                    {{ __('Forms') }}
                </flux:navbar.item>
                <flux:navbar.item icon="inbox" :href="route('dashboard.submissions.index')" :current="request()->routeIs('dashboard.submissions.*')" wire:navigate>
                    {{ __('Submissions') }}
                </flux:navbar.item>
                <flux:navbar.item icon="envelope" :href="route('dashboard.email-jobs.index')" :current="request()->routeIs('dashboard.email-jobs.*')" wire:navigate>
                    {{ __('Email jobs') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <x-desktop-user-menu />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard.index') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Forms')">
                    <flux:sidebar.item icon="home" :href="route('dashboard.index')" :current="request()->routeIs('dashboard.index')" wire:navigate>
                        {{ __('Overview') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('dashboard.forms.index')" :current="request()->routeIs('dashboard.forms.*')" wire:navigate>
                        {{ __('Forms') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="inbox" :href="route('dashboard.submissions.index')" :current="request()->routeIs('dashboard.submissions.*')" wire:navigate>
                        {{ __('Submissions') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="envelope" :href="route('dashboard.email-jobs.index')" :current="request()->routeIs('dashboard.email-jobs.*')" wire:navigate>
                        {{ __('Email jobs') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Account')">
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate>
                        {{ __('Settings') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
