<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Email jobs') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Monitor and retry outgoing email delivery') }}</flux:text>
        </div>

        @if ($this->statusCounts['failed'] > 0)
            <flux:button type="button" variant="primary" icon="arrow-path" wire:click="retryAllFailed">
                {{ __('Retry all failed (:count)', ['count' => $this->statusCounts['failed']]) }}
            </flux:button>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->statuses() as $option)
            @php $count = $this->statusCounts[$option['value']] ?? 0; @endphp
            <button
                type="button"
                wire:click="$set('statusFilter', '{{ $option['value'] }}')"
                class="rounded-lg border p-3 text-left transition hover:border-zinc-400
                    {{ $statusFilter === $option['value'] ? 'border-blue-500 ring-1 ring-blue-500' : 'border-zinc-200 dark:border-zinc-700' }}"
            >
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ $option['label'] }}</flux:text>
                <flux:heading size="xl" level="3">{{ number_format($count) }}</flux:heading>
            </button>
        @endforeach
    </div>

    <flux:card>
        <div class="flex flex-col gap-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    :placeholder="__('Search by recipient or subject...')"
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
            </div>

            @if ($search || $statusFilter !== 'all' || $formFilter)
                <div>
                    <flux:button type="button" size="sm" variant="ghost" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:card>

    <flux:card>
        @if ($this->jobs->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                <flux:icon name="envelope" class="size-10 text-zinc-300" />
                <flux:heading size="lg">{{ __('No email jobs match your filters') }}</flux:heading>
            </div>
        @else
            <flux:table :paginate="$this->jobs">
                <flux:table.columns>
                    <flux:table.column>{{ __('Recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('Form / Submission') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->jobs as $job)
                        @php $status = $job->statusEnum(); @endphp
                        <flux:table.row :key="$job->id">
                            <flux:table.cell>
                                <flux:text class="text-sm">{{ $job->recipient }}</flux:text>
                                <flux:text class="line-clamp-1 text-xs text-zinc-500">{{ $job->subject }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="text-sm">{{ $job->submission?->form?->name }}</flux:text>
                                <flux:link :href="route('dashboard.submissions.show', $job->submission)" wire:navigate class="text-xs">
                                    {{ __('Submission #:id', ['id' => $job->submission_id]) }}
                                </flux:link>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$status->color()">{{ $status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="whitespace-nowrap text-sm">
                                    {{ $job->created_at?->diffForHumans() }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ $job->attempts }} {{ __('attempt(s)') }}
                                </flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:link :href="route('dashboard.email-jobs.show', $job)" wire:navigate>
                                        <flux:button variant="ghost" size="sm" icon="eye" />
                                    </flux:link>
                                    @if ($job->status === \App\Enums\EmailJobStatus::Failed->value)
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrow-path"
                                            wire:click="retry({{ $job->id }})"
                                        />
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
