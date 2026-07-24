<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Plans') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Manage subscription plans and pricing.') }}</flux:text>
        </div>

        <flux:button :href="route('admin.plans.create')" wire:navigate variant="primary" icon="plus">
            {{ __('New plan') }}
        </flux:button>
    </div>

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Price') }}</flux:table.column>
                <flux:table.column>{{ __('Max forms') }}</flux:table.column>
                <flux:table.column>{{ __('Max subs/month') }}</flux:table.column>
                <flux:table.column>{{ __('Subscriptions') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->plans as $plan)
                    <flux:table.row :key="$plan->id">
                        <flux:table.cell>
                            <div class="flex flex-col">
                                <flux:text class="font-medium">{{ $plan->name }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">{{ $plan->slug }}</flux:text>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:text>{{ $plan->formattedPrice() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:text>{{ $plan->hasUnlimitedForms() ? '∞' : $plan->max_forms }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:text>{{ $plan->hasUnlimitedSubmissions() ? '∞' : number_format($plan->max_submissions_per_month) }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="zinc">{{ $plan->active_subscriptions_count }} / {{ $plan->subscriptions_count }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($plan->is_default)
                                <flux:badge color="blue">{{ __('Default') }}</flux:badge>
                            @endif
                            @if ($plan->is_active)
                                <flux:badge color="green">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge color="zinc">{{ __('Inactive') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />

                                <flux:menu>
                                    <flux:menu.item :href="route('admin.plans.edit', $plan)" wire:navigate icon="pencil">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item
                                        href="#"
                                        icon="power"
                                        x-on:click.prevent="$wire.toggleActive({{ $plan->id }})"
                                    >
                                        {{ $plan->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item
                                        href="#"
                                        icon="trash"
                                        variant="danger"
                                        x-on:click.prevent="if (confirm('Delete this plan?')) { $wire.delete({{ $plan->id }}) }"
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
