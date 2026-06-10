<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Forms') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Create, edit, and manage your form endpoints') }}</flux:text>
        </div>

        <flux:button :href="route('dashboard.forms.import')" wire:navigate variant="ghost" icon="arrow-up-tray">
            {{ __('Import JSON') }}
        </flux:button>
        <flux:button :href="route('dashboard.forms.create')" wire:navigate variant="primary" icon="plus">
            {{ __('New form') }}
        </flux:button>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search forms...')"
            class="flex-1"
        />

        <flux:select wire:model.live="statusFilter" class="sm:w-48">
            <option value="all">{{ __('All statuses') }}</option>
            <option value="active">{{ __('Active') }}</option>
            <option value="archived">{{ __('Archived') }}</option>
        </flux:select>
    </div>

    <flux:card>
        @if ($this->forms->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                <flux:icon name="document-text" class="size-10 text-zinc-300" />
                <flux:heading size="lg">{{ __('No forms yet') }}</flux:heading>
                <flux:text class="max-w-md text-zinc-500">
                    {{ __('Get started by creating your first form. You can configure recipients, customise the subject, and copy your unique endpoint.') }}
                </flux:text>
                <flux:button :href="route('dashboard.forms.create')" wire:navigate variant="primary" icon="plus">
                    {{ __('Create your first form') }}
                </flux:button>
            </div>
        @else
            <flux:table :paginate="$this->forms">
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Endpoint') }}</flux:table.column>
                    <flux:table.column>{{ __('Recipients') }}</flux:table.column>
                    <flux:table.column>{{ __('Submissions') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->forms as $form)
                        <flux:table.row :key="$form->id">
                            <flux:table.cell>
                                <div class="flex flex-col">
                                    <flux:link :href="route('dashboard.forms.edit', $form)" wire:navigate class="font-medium">
                                        {{ $form->name }}
                                    </flux:link>
                                    <flux:text class="text-xs text-zinc-500">{{ $form->slug }}</flux:text>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <code class="rounded bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800">{{ $form->endpoint }}</code>
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:text class="text-sm">
                                    {{ $form->recipient_emails ? count($form->recipient_emails) : 0 }}
                                </flux:text>
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge color="zinc">{{ number_format($form->submissions_count) }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                @php $status = $form->status(); @endphp
                                <flux:badge :color="$status->color()">{{ $status->label() }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />

                                    <flux:menu>
                                        <flux:menu.item :href="route('dashboard.forms.edit', $form)" wire:navigate icon="pencil">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        <flux:menu.item :href="route('dashboard.submissions.index', ['form' => $form->slug])" wire:navigate icon="inbox">
                                            {{ __('View submissions') }}
                                        </flux:menu.item>
                                        <flux:menu.item
                                            href="#"
                                            icon="key"
                                            x-on:click.prevent="$wire.regenerateApiKey({{ $form->id }})"
                                        >
                                            {{ __('Regenerate API key') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        @if ($form->is_archived)
                                            <flux:menu.item
                                                href="#"
                                                icon="arrow-uturn-up"
                                                x-on:click.prevent="$wire.restore({{ $form->id }})"
                                            >
                                                {{ __('Restore') }}
                                            </flux:menu.item>
                                        @else
                                            <flux:menu.item
                                                href="#"
                                                icon="archive-box"
                                                x-on:click.prevent="$wire.archive({{ $form->id }})"
                                            >
                                                {{ __('Archive') }}
                                            </flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
