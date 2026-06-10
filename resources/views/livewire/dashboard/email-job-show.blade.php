<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <flux:link :href="route('dashboard.email-jobs.index')" wire:navigate>{{ __('Email jobs') }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <span>#{{ $job->id }}</span>
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">
                {{ __('Email job :id', ['id' => '#'.$job->id]) }}
            </flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                {{ $job->recipient }}
            </flux:text>
        </div>
        <div class="flex gap-2">
            @if ($job->status === \App\Enums\EmailJobStatus::Failed->value)
                <flux:button variant="primary" icon="arrow-path" wire:click="retry">
                    {{ __('Retry job') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="lg:col-span-2">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Details') }}</flux:heading>

                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Status') }}</dt>
                        <dd>
                            <flux:badge :color="$job->statusEnum()->color()">{{ $job->statusEnum()->label() }}</flux:badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Attempts') }}</dt>
                        <dd>{{ $job->attempts }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Recipient') }}</dt>
                        <dd class="break-all">{{ $job->recipient }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Subject') }}</dt>
                        <dd>{{ $job->subject }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-zinc-500">{{ __('Submission') }}</dt>
                        <dd>
                            <flux:link :href="route('dashboard.submissions.show', $job->submission)" wire:navigate>
                                {{ __('View submission :id', ['id' => '#'.$job->submission_id]) }}
                            </flux:link>
                        </dd>
                    </div>
                    @if ($job->error_message)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-zinc-500">{{ __('Error message') }}</dt>
                            <dd class="rounded bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">
                                {{ $job->error_message }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-3">
                <flux:heading size="lg" level="3">{{ __('Timeline') }}</flux:heading>

                <ol class="space-y-3">
                    @foreach ($this->timeline as $entry)
                        <li class="flex items-start gap-2 text-sm">
                            <flux:icon name="clock" class="mt-0.5 size-4 text-zinc-400" />
                            <div>
                                <div class="text-xs text-zinc-500">{{ $entry['label'] }}</div>
                                <div>{{ $entry['value'] ?? '—' }}</div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </flux:card>
    </div>
</div>
