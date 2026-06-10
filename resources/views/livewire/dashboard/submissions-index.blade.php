<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Submissions') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Inspect, search, filter, and export form submissions') }}</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button type="button" variant="ghost" icon="arrow-down-tray" wire:click="exportCsv">
                {{ __('Export CSV') }}
            </flux:button>
        </div>
    </div>

    <flux:card>
        <div class="flex flex-col gap-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    :placeholder="__('Search submissions...')"
                />

                <flux:select wire:model.live="formFilter">
                    <option value="">{{ __('All forms') }}</option>
                    @foreach ($this->forms as $form)
                        <option value="{{ $form->slug }}">{{ $form->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="statusFilter">
                    @foreach ($this->statuses() as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="deliveryFilter">
                    @foreach ($this->deliveryOptions() as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model.live="dateFrom" type="date" :label="__('From')" label:sr-only />
                <flux:input wire:model.live="dateTo" type="date" :label="__('To')" label:sr-only />
            </div>

            @if ($search || $formFilter || $statusFilter !== 'all' || $deliveryFilter !== 'all' || $dateFrom || $dateTo)
                <div>
                    <flux:button type="button" size="sm" variant="ghost" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:card>

    <flux:card>
        @if ($this->submissions->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                <flux:icon name="inbox" class="size-10 text-zinc-300" />
                <flux:heading size="lg">{{ __('No submissions match your filters') }}</flux:heading>
                <flux:text class="max-w-md text-zinc-500">
                    {{ __('Adjust your filters or wait for new submissions to come in.') }}
                </flux:text>
            </div>
        @else
            <flux:table :paginate="$this->submissions">
                <flux:table.columns>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column>{{ __('Form') }}</flux:table.column>
                    <flux:table.column>{{ __('Submission') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->submissions as $submission)
                        @php
                            $data = $submission->submission_data;
                            $preview = collect($data)
                                ->take(2)
                                ->map(fn ($v, $k) => is_scalar($v) ? ucwords((string) $k).': '.$v : ucwords((string) $k))
                                ->implode(' · ');
                            $statusEnum = $submission->statusEnum();
                            $emailSummary = [
                                'sent' => 0,
                                'failed' => 0,
                                'pending' => 0,
                            ];
                            foreach ($submission->emailJobs as $job) {
                                $key = match ($job->status) {
                                    'sent' => 'sent',
                                    'failed' => 'failed',
                                    default => 'pending',
                                };
                                $emailSummary[$key]++;
                            }
                        @endphp
                        <flux:table.row :key="$submission->id">
                            <flux:table.cell>
                                <flux:text class="whitespace-nowrap text-sm">
                                    {{ $submission->created_at?->diffForHumans() }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ $submission->created_at?->format('M j, H:i') }}
                                </flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="text-sm">{{ $submission->form?->name }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="max-w-xs truncate text-sm">{{ $preview ?: '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$statusEnum->color()">{{ $statusEnum->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-1">
                                    @if ($emailSummary['sent'])
                                        <flux:badge size="sm" color="green">{{ $emailSummary['sent'] }} ✓</flux:badge>
                                    @endif
                                    @if ($emailSummary['failed'])
                                        <flux:badge size="sm" color="red">{{ $emailSummary['failed'] }} ✗</flux:badge>
                                    @endif
                                    @if ($emailSummary['pending'])
                                        <flux:badge size="sm" color="zinc">{{ $emailSummary['pending'] }} …</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item :href="route('dashboard.submissions.show', $submission)" wire:navigate icon="eye">
                                            {{ __('View details') }}
                                        </flux:menu.item>
                                        <flux:menu.item
                                            href="#"
                                            icon="envelope-open"
                                            x-on:click.prevent="$wire.markRead({{ $submission->id }})"
                                        >
                                            {{ __('Mark as read') }}
                                        </flux:menu.item>
                                        <flux:menu.item
                                            href="#"
                                            icon="shield-exclamation"
                                            x-on:click.prevent="$wire.markSpam({{ $submission->id }})"
                                        >
                                            {{ __('Mark as spam') }}
                                        </flux:menu.item>
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
