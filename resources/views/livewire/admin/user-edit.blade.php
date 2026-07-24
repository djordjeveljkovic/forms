<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:link :href="route('admin.users.show', $user)" wire:navigate class="text-sm text-zinc-500">
            ← {{ __('Back to user') }}
        </flux:link>
        <flux:heading size="xl" level="1">{{ __('Edit user') }}</flux:heading>
        <flux:text class="text-zinc-500">{{ $user->email }}</flux:text>
    </div>

    <flux:card>
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />

            <flux:field variant="inline">
                <flux:checkbox wire:model="isAdmin" :label="__('Administrator')" />
                <flux:description>{{ __('Admins can access the /admin section.') }}</flux:description>
            </flux:field>

            <flux:select wire:model="planId" :label="__('Plan')">
                <option value="">{{ __('No plan') }}</option>
                @foreach ($this->plans() as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->formattedPrice() }}</option>
                @endforeach
            </flux:select>

            <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm" level="3">{{ __('Reset password') }}</flux:heading>
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="forcePasswordReset" :label="__('Set a new password')" />
                </flux:field>
                @if ($forcePasswordReset)
                    <flux:input wire:model="password" type="text" :label="__('New password')" required minlength="8" />
                    <flux:text class="text-xs text-zinc-500">
                        {{ __('The new password will be shown ONCE in a toast after saving.') }}
                    </flux:text>
                @endif
            </div>

            <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm" level="3">{{ __('Two-factor authentication') }}</flux:heading>
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="resetTwoFactor" :label="__('Disable 2FA on next save')" />
                    <flux:description>{{ __('User will be forced to re-enroll.') }}</flux:description>
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:link :href="route('admin.users.show', $user)" wire:navigate variant="ghost">
                    {{ __('Cancel') }}
                </flux:link>
                <flux:button type="submit" variant="primary" icon="check">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
