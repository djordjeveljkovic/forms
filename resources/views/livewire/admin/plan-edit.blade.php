<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:link :href="route('admin.plans.index')" wire:navigate class="text-sm text-zinc-500">
            ← {{ __('Back to plans') }}
        </flux:link>
        <flux:heading size="xl" level="1">{{ __('Edit plan') }}</flux:heading>
        <flux:text class="text-zinc-500">{{ $plan->name }} ({{ $plan->slug }})</flux:text>
    </div>

    <flux:card>
        <form wire:submit="save" class="flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="slug" :label="__('Slug')" required />
            </div>

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="priceCents" type="number" min="0" :label="__('Price (cents)')" required />
                <flux:input wire:model="currency" maxlength="3" :label="__('Currency')" required />
                <flux:select wire:model="interval" :label="__('Interval')">
                    @foreach ($this->intervals() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="maxForms" type="number" min="1" :label="__('Max forms')" description="Leave blank for unlimited." />
                <flux:input wire:model="maxSubmissionsPerMonth" type="number" min="1" :label="__('Max submissions / month')" description="Leave blank for unlimited." />
            </div>

            <div class="flex flex-col gap-3">
                <flux:heading size="sm" level="3">{{ __('Features') }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach ($features as $i => $feature)
                        <flux:badge color="zinc" size="lg">
                            {{ $feature }}
                            <button type="button" wire:click="removeFeature({{ $i }})" class="ml-2 text-xs text-zinc-500 hover:text-red-500">×</button>
                        </flux:badge>
                    @endforeach
                </div>
                <div class="flex gap-2">
                    <flux:input wire:model="newFeature" :placeholder="__('Add feature')" class="flex-1" />
                    <flux:button type="button" wire:click="addFeature" variant="ghost" icon="plus">{{ __('Add') }}</flux:button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="sort" type="number" min="0" :label="__('Sort order')" />
                <flux:field variant="inline">
                    <flux:checkbox wire:model="isActive" :label="__('Active')" />
                </flux:field>
                <flux:field variant="inline">
                    <flux:checkbox wire:model="isDefault" :label="__('Default plan')" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:link :href="route('admin.plans.index')" wire:navigate variant="ghost">
                    {{ __('Cancel') }}
                </flux:link>
                <flux:button type="submit" variant="primary" icon="check">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
