<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:link :href="route('admin.users.index')" wire:navigate class="text-sm text-zinc-500">
            ← {{ __('Back to users') }}
        </flux:link>
        <flux:heading size="xl" level="1">{{ __('Create user') }}</flux:heading>
        <flux:text class="text-zinc-500">{{ __('Add a new user and assign their role and plan.') }}</flux:text>
    </div>

    <flux:card>
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" required autofocus />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />

            <div class="flex flex-col gap-2">
                <flux:input
                    wire:model="password"
                    type="text"
                    :label="__('Password')"
                    description="At least 8 characters."
                    required
                />
                <flux:button type="button" wire:click="generatePassword" variant="ghost" size="sm" icon="arrow-path" class="self-start">
                    {{ __('Generate random password') }}
                </flux:button>
            </div>

            <flux:field variant="inline">
                <flux:checkbox wire:model="isAdmin" :label="__('Administrator')" />
                <flux:description>{{ __('Admins can access the /admin section and manage all users.') }}</flux:description>
            </flux:field>

            <flux:field variant="inline">
                <flux:checkbox wire:model="sendVerification" :label="__('Mark email as verified')" />
                <flux:description>{{ __('Skip the email verification step on signup.') }}</flux:description>
            </flux:field>

            <flux:select wire:model="planId" :label="__('Plan')">
                <option value="">{{ __('Default plan') }}</option>
                @foreach ($this->plans() as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->formattedPrice() }}</option>
                @endforeach
            </flux:select>

            <div class="flex items-center justify-end gap-2">
                <flux:link :href="route('admin.users.index')" wire:navigate variant="ghost">
                    {{ __('Cancel') }}
                </flux:link>
                <flux:button type="submit" variant="primary" icon="check">
                    {{ __('Create user') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
