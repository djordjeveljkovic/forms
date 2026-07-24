@php
    use App\Models\User;

    $impersonator = \App\Models\User::query()->find($impersonatorId);
    $impersonated = auth()->user();
@endphp

@if ($impersonator && $impersonated)
    <div
        data-test="impersonation-banner"
        class="sticky top-0 z-50 flex items-center justify-between gap-3 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-100"
    >
        <div class="flex items-center gap-2">
            <flux:icon.megaphone class="size-4" />
            <span>
                You are impersonating
                <strong>{{ $impersonated->name }}</strong>
                &lt;{{ $impersonated->email }}&gt;
                as <strong>{{ $impersonator->name }}</strong>.
            </span>
        </div>

        <form method="POST" action="{{ route('admin.users.impersonate.stop') }}">
            @csrf
            <flux:button
                type="submit"
                size="xs"
                variant="filled"
                icon="arrow-uturn-left"
                data-test="stop-impersonating"
            >
                {{ __('Stop impersonating') }}
            </flux:button>
        </form>
    </div>
@endif
