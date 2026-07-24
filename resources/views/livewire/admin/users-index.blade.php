<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Manage every user on the platform.') }}</flux:text>
        </div>

        <flux:button :href="route('admin.users.create')" wire:navigate variant="primary" icon="plus">
            {{ __('New user') }}
        </flux:button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search by name or email...')"
            class="lg:col-span-2"
        />

        <flux:select wire:model.live="roleFilter" label="Role">
            <option value="all">{{ __('All roles') }}</option>
            <option value="admin">{{ __('Admin') }}</option>
            <option value="user">{{ __('User') }}</option>
        </flux:select>

        <flux:select wire:model.live="planFilter" label="Plan">
            <option value="all">{{ __('All plans') }}</option>
            @foreach ($this->plans as $plan)
                <option value="{{ $plan->slug }}">{{ $plan->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="verifiedFilter" label="Verified">
            <option value="all">{{ __('Any') }}</option>
            <option value="yes">{{ __('Verified') }}</option>
            <option value="no">{{ __('Unverified') }}</option>
        </flux:select>
    </div>

    <flux:card>
        <flux:table :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column>
                    <button type="button" wire:click="$set('sort', 'name')">{{ __('Name') }}</button>
                </flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Plan') }}</flux:table.column>
                <flux:table.column>
                    <button type="button" wire:click="$set('sort', 'forms')">{{ __('Forms') }}</button>
                </flux:table.column>
                <flux:table.column>
                    <button type="button" wire:click="$set('sort', 'submissions')">{{ __('Submissions') }}</button>
                </flux:table.column>
                <flux:table.column>{{ __('Joined') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <div class="flex flex-col">
                                <flux:link :href="route('admin.users.show', $user)" wire:navigate class="font-medium">
                                    {{ $user->name }}
                                </flux:link>
                                <flux:text class="text-xs text-zinc-500">{{ $user->email }}</flux:text>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($user->isAdmin())
                                <flux:badge color="blue">{{ __('Admin') }}</flux:badge>
                            @else
                                <flux:badge color="zinc">{{ __('User') }}</flux:badge>
                            @endif
                            @if (! $user->hasVerifiedEmail())
                                <flux:badge color="amber" class="ml-1">{{ __('Unverified') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:text class="text-sm">
                                {{ $user->activeSubscription?->plan?->name ?? '—' }}
                            </flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="zinc">{{ number_format($user->forms_count) }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="zinc">{{ number_format($user->form_submissions_count) }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:text class="text-xs text-zinc-500">{{ $user->created_at?->diffForHumans() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />

                                <flux:menu>
                                    <flux:menu.item :href="route('admin.users.show', $user)" wire:navigate icon="eye">
                                        {{ __('View') }}
                                    </flux:menu.item>
                                    <flux:menu.item :href="route('admin.users.edit', $user)" wire:navigate icon="pencil">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item
                                        href="#"
                                        icon="shield-check"
                                        x-on:click.prevent="$wire.toggleAdmin({{ $user->id }})"
                                    >
                                        {{ $user->isAdmin() ? __('Demote to user') : __('Promote to admin') }}
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <form method="POST" action="{{ route('admin.users.impersonate.start', $user) }}" class="w-full">
                                        @csrf
                                        <flux:menu.item
                                            as="button"
                                            type="submit"
                                            icon="user-circle"
                                            class="w-full cursor-pointer"
                                        >
                                            {{ __('Impersonate') }}
                                        </flux:menu.item>
                                    </form>
                                    <flux:menu.separator />
                                    <flux:menu.item
                                        href="#"
                                        icon="trash"
                                        variant="danger"
                                        x-on:click.prevent="if (confirm('Delete this user and all their forms?')) { $wire.delete({{ $user->id }}) }"
                                    >
                                        {{ __('Delete') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
