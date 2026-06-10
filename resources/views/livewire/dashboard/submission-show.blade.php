<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <flux:link :href="route('dashboard.submissions.index')" wire:navigate>{{ __('Submissions') }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <span>#{{ $submission->id }}</span>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">
                {{ __('Submission :id', ['id' => '#'.$submission->id]) }}
            </flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                {{ __('For :form · received :when', [
                    'form' => $submission->form?->name,
                    'when' => $submission->created_at?->toDayDateTimeString(),
                ]) }}
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" :href="route('dashboard.forms.edit', $submission->form)" wire:navigate icon="cog-6-tooth">
                {{ __('Form settings') }}
            </flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="lg:col-span-2">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Submission data') }}</flux:heading>

                @forelse ($this->dataRows as $row)
                    <div class="flex flex-col gap-1 border-b border-zinc-100 pb-3 last:border-0 dark:border-zinc-700">
                        <div class="flex items-baseline gap-2">
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">
                                {{ $row['label'] }}
                            </flux:text>
                            <flux:text class="text-xs text-zinc-400">
                                {{ $row['key'] }}
                            </flux:text>
                        </div>
                        @if (is_array($row['value']) || is_object($row['value']))
                            <pre class="overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800"><code>{{ $row['formatted'] }}</code></pre>
                        @else
                            <flux:text class="whitespace-pre-wrap text-sm">{{ $row['formatted'] }}</flux:text>
                        @endif
                    </div>
                @empty
                    <flux:text class="text-sm text-zinc-500">{{ __('No data was captured.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>

        <div class="flex flex-col gap-4">
            <flux:card>
                <div class="flex flex-col gap-3">
                    <flux:heading size="lg" level="3">{{ __('Status') }}</flux:heading>
                    <div class="flex items-center gap-2">
                        <flux:badge :color="$submission->statusEnum()->color()">
                            {{ $submission->statusEnum()->label() }}
                        </flux:badge>
                        @if ($submission->read_at)
                            <flux:text class="text-xs text-zinc-500">
                                {{ __('Read :when', ['when' => $submission->read_at->diffForHumans()]) }}
                            </flux:text>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2 pt-2">
                        <flux:button type="button" size="sm" variant="ghost" icon="envelope-open" wire:click="markRead">
                            {{ __('Mark as read') }}
                        </flux:button>
                        <flux:button type="button" size="sm" variant="ghost" icon="shield-exclamation" wire:click="markSpam">
                            {{ __('Mark as spam') }}
                        </flux:button>
                        <flux:button type="button" size="sm" variant="ghost" icon="archive-box" wire:click="archive">
                            {{ __('Archive') }}
                        </flux:button>
                        <flux:button
                            type="button"
                            size="sm"
                            variant="danger"
                            icon="trash"
                            x-on:click.prevent="if (confirm('{{ __('Delete this submission?') }}')) $wire.delete()"
                        >
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex flex-col gap-3">
                    <flux:heading size="lg" level="3">{{ __('Metadata') }}</flux:heading>
                    <dl class="space-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('IP address') }}</dt>
                            <dd>{{ $submission->ip_address ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('User agent') }}</dt>
                            <dd class="break-all text-xs">{{ $submission->user_agent ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Referer') }}</dt>
                            <dd class="break-all text-xs">{{ $submission->referer ?: '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex flex-col gap-3">
                    <flux:heading size="lg" level="3">{{ __('Email delivery') }}</flux:heading>
                    @forelse ($submission->emailJobs as $job)
                        <div class="flex flex-col gap-1 border-b border-zinc-100 pb-3 last:border-0 dark:border-zinc-700">
                            <div class="flex items-center justify-between gap-2">
                                <flux:text class="truncate text-sm">{{ $job->recipient }}</flux:text>
                                <flux:badge size="sm" :color="$job->statusEnum()->color()">{{ $job->statusEnum()->label() }}</flux:badge>
                            </div>
                            @if ($job->error_message)
                                <flux:text class="text-xs text-red-600 dark:text-red-400">{{ $job->error_message }}</flux:text>
                            @endif
                            <flux:link :href="route('dashboard.email-jobs.show', $job)" wire:navigate class="text-xs">
                                {{ __('View job') }}
                            </flux:link>
                        </div>
                    @empty
                        <flux:text class="text-sm text-zinc-500">{{ __('No email jobs queued.') }}</flux:text>
                    @endforelse
                </div>
            </flux:card>
        </div>
    </div>
</div>
