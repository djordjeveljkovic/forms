<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:link :href="route('admin.users.index')" wire:navigate class="text-sm text-zinc-500">
            ← {{ __('Back to users') }}
        </flux:link>
        <div class="flex items-center gap-3">
            <flux:heading size="xl" level="1">{{ $user->name }}</flux:heading>
            @if ($user->isAdmin())
                <flux:badge color="blue">{{ __('Admin') }}</flux:badge>
            @else
                <flux:badge color="zinc">{{ __('User') }}</flux:badge>
            @endif
            @if (! $user->hasVerifiedEmail())
                <flux:badge color="amber">{{ __('Unverified') }}</flux:badge>
            @endif
        </div>
        <flux:text class="text-zinc-500">{{ $user->email }}</flux:text>
    </div>

    <div class="flex flex-wrap gap-2">
        <flux:button :href="route('admin.users.edit', $user)" wire:navigate icon="pencil">
            {{ __('Edit') }}
        </flux:button>
        <form method="POST" action="{{ route('admin.users.impersonate.start', $user) }}">
            @csrf
            <flux:button type="submit" icon="user-circle">
                {{ __('Impersonate') }}
            </flux:button>
        </form>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Plan') }}</flux:heading>
            @if ($this->currentPlan)
                <flux:heading size="xl" level="3" class="mt-2">{{ $this->currentPlan->name }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">{{ $this->currentPlan->formattedPrice() }}</flux:text>
                <div class="mt-3 space-y-1 text-sm">
                    <flux:text>
                        <strong>{{ $this->currentPlan->hasUnlimitedForms() ? '∞' : $this->currentPlan->max_forms }}</strong>
                        {{ __('forms') }}
                    </flux:text>
                    <flux:text>
                        <strong>{{ $this->currentPlan->hasUnlimitedSubmissions() ? '∞' : number_format($this->currentPlan->max_submissions_per_month) }}</strong>
                        {{ __('submissions / month') }}
                    </flux:text>
                </div>
            @else
                <flux:text class="mt-2 text-zinc-500">{{ __('No plan') }}</flux:text>
            @endif
        </flux:card>

        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Activity') }}</flux:heading>
            <div class="mt-2 space-y-1 text-sm">
                <flux:text>{{ __('Joined') }}: <strong>{{ $user->created_at?->toDayDateTimeString() }}</strong></flux:text>
                <flux:text>{{ __('Forms') }}: <strong>{{ $user->forms()->count() }}</strong></flux:text>
                <flux:text>{{ __('Forms-agent token') }}:
                    <strong>{{ $user->hasFormsAgentToken() ? __('Active') : __('None') }}</strong>
                </flux:text>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Subscription history') }}</flux:heading>
            <div class="mt-2 space-y-2 text-sm">
                @forelse ($this->subscriptions as $sub)
                    <div class="flex items-center justify-between">
                        <flux:text>{{ $sub->plan?->name ?? '—' }}</flux:text>
                        <flux:badge :color="$sub->isActive() ? 'green' : 'zinc'">{{ $sub->status }}</flux:badge>
                    </div>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No subscriptions.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>

    <flux:card>
        <flux:heading size="lg" level="2">{{ __('Forms owned') }}</flux:heading>
        <flux:table :paginate="$this->forms">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Slug') }}</flux:table.column>
                <flux:table.column>{{ __('Submissions') }}</flux:table.column>
                <flux:table.column>{{ __('Created') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->forms as $form)
                    <flux:table.row :key="$form->id">
                        <flux:table.cell>{{ $form->name }}</flux:table.cell>
                        <flux:table.cell><code class="text-xs">{{ $form->slug }}</code></flux:table.cell>
                        <flux:table.cell>{{ $form->submissions_count }}</flux:table.cell>
                        <flux:table.cell>{{ $form->created_at?->diffForHumans() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:card>
        <flux:heading size="lg" level="2">{{ __('Audit log') }}</flux:heading>
        <div class="mt-2 space-y-2 text-sm">
            @forelse ($this->auditLog as $entry)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:badge color="zinc" size="sm">{{ $entry->action }}</flux:badge>
                        <flux:text class="text-xs text-zinc-500">{{ json_encode($entry->metadata) }}</flux:text>
                    </div>
                    <flux:text class="text-xs text-zinc-500">{{ $entry->created_at?->diffForHumans() }}</flux:text>
                </div>
            @empty
                <flux:text class="text-zinc-500">{{ __('No audit entries.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>
</div>
