<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Admin overview') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Global stats across all users, forms, and submissions.') }}</flux:text>
        </div>

        <flux:radio.group wire:model.live="range" variant="segmented">
            @foreach ($this->ranges as $value => $label)
                <flux:radio value="{{ $value }}">{{ $label }}</flux:radio>
            @endforeach
        </flux:radio.group>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <flux:text class="text-sm text-zinc-500">{{ __('Total users') }}</flux:text>
            <flux:heading size="2xl" level="2" class="mt-1">{{ number_format($this->totalUsers) }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">+{{ number_format($this->newUsersInRange) }} {{ __('in range') }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm text-zinc-500">{{ __('Admins') }}</flux:text>
            <flux:heading size="2xl" level="2" class="mt-1">{{ number_format($this->adminUserCount) }}</flux:heading>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm text-zinc-500">{{ __('Forms') }}</flux:text>
            <flux:heading size="2xl" level="2" class="mt-1">{{ number_format($this->totalForms) }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">{{ number_format($this->activeForms) }} {{ __('active') }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm text-zinc-500">{{ __('Submissions') }}</flux:text>
            <flux:heading size="2xl" level="2" class="mt-1">{{ number_format($this->totalSubmissions) }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">{{ number_format($this->totalSubmissionsAllTime) }} {{ __('all-time') }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm text-zinc-500">{{ __('Active subscriptions') }}</flux:text>
            <flux:heading size="2xl" level="2" class="mt-1">{{ number_format($this->activeSubscriptions) }}</flux:heading>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm text-zinc-500">{{ __('MRR') }}</flux:text>
            <flux:heading size="2xl" level="2" class="mt-1">${{ $this->mrrAmount }}</flux:heading>
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Signups over time') }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">{{ __('New users per day') }}</flux:text>

            <div class="mt-4 flex h-40 items-end gap-1">
                @foreach ($this->signupsByDay as $point)
                    @php $max = max(1, max(array_column($this->signupsByDay, 'count'))); @endphp
                    <div
                        class="flex-1 rounded-t bg-blue-500/80 transition hover:bg-blue-500"
                        style="height: {{ max(2, ($point['count'] / $max) * 100) }}%"
                        title="{{ $point['date'] }} — {{ $point['count'] }}"
                    ></div>
                @endforeach
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Top forms') }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">{{ __('By submissions in range') }}</flux:text>

            <div class="mt-4 space-y-2">
                @forelse ($this->topForms as $form)
                    <div class="flex items-center justify-between">
                        <flux:text class="truncate">{{ $form['name'] }}</flux:text>
                        <flux:badge color="zinc">{{ number_format($form['count']) }}</flux:badge>
                    </div>
                @empty
                    <flux:text class="text-sm text-zinc-500">{{ __('No submissions yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Subscriptions by plan') }}</flux:heading>
            <div class="mt-4 space-y-2">
                @foreach ($this->subscriptionsByPlan as $row)
                    <div class="flex items-center justify-between">
                        <flux:text>{{ $row['plan'] }}</flux:text>
                        <div class="flex items-center gap-2">
                            <flux:badge color="zinc">{{ $row['count'] }}</flux:badge>
                            <flux:text class="text-xs text-zinc-500">${{ number_format($row['price_cents'] / 100, 2) }}</flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Recent admin actions') }}</flux:heading>
            <div class="mt-4 flow-root">
                <ul role="list" class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->recentAdminActions as $audit)
                        <li class="flex items-center gap-3 py-2 text-sm">
                            <flux:badge color="zinc" size="sm">{{ $audit->action }}</flux:badge>
                            <span class="flex-1 truncate">
                                {{ $audit->user?->name ?? '—' }}
                            </span>
                            <span class="text-xs text-zinc-500">{{ $audit->created_at?->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-zinc-500">{{ __('No admin actions yet.') }}</flux:text>
                    @endforelse
                </ul>
            </div>
        </flux:card>
    </div>
</div>
