<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Analytics') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Overview of form activity and email delivery') }}</flux:text>
        </div>

        <flux:radio.group wire:model.live="range" variant="segmented">
            @foreach ($this->ranges as $value => $label)
                <flux:radio value="{{ $value }}">{{ $label }}</flux:radio>
            @endforeach
        </flux:radio.group>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <div class="flex flex-col gap-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Submissions in range') }}</flux:text>
                <flux:heading size="xl" level="2">{{ number_format($this->totalSubmissions) }}</flux:heading>
                <flux:text class="text-xs text-zinc-500">
                    {{ __('All time: :count', ['count' => number_format($this->totalSubmissionsAllTime)]) }}
                </flux:text>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Active forms') }}</flux:text>
                <flux:heading size="xl" level="2">{{ number_format($this->activeForms) }}</flux:heading>
                <flux:text class="text-xs text-zinc-500">
                    {{ __('Total: :count', ['count' => number_format($this->totalForms)]) }}
                </flux:text>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Average per day') }}</flux:text>
                <flux:heading size="xl" level="2">{{ number_format($this->averagePerDay, 1) }}</flux:heading>
                <flux:text class="text-xs text-zinc-500">
                    {{ __('Email success: :rate%', ['rate' => number_format($this->emailSuccessRate, 1)]) }}
                </flux:text>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Email failure rate') }}</flux:text>
                <flux:heading size="xl" level="2">{{ number_format($this->emailFailureRate, 1) }}%</flux:heading>
                <flux:text class="text-xs text-zinc-500">
                    {{ __('Sent: :sent · Failed: :failed', [
                        'sent' => number_format($this->emailStatusBreakdown['sent']),
                        'failed' => number_format($this->emailStatusBreakdown['failed']),
                    ]) }}
                </flux:text>
            </div>
        </flux:card>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="lg:col-span-2">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="3">{{ __('Submissions over time') }}</flux:heading>

                @if (collect($this->submissionsByDay)->sum('count') === 0)
                    <div class="flex h-48 items-center justify-center rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">{{ __('No submissions yet in this range.') }}</flux:text>
                    </div>
                @else
                    @php
                        $series = $this->submissionsByDay;
                        $max = max(1, max(array_column($series, 'count')));
                    @endphp
                    <div class="flex h-48 items-end gap-1">
                        @foreach ($series as $point)
                            <div
                                class="flex-1 rounded-t bg-blue-500/80 hover:bg-blue-500"
                                style="height: {{ max(2, ($point['count'] / $max) * 100) }}%"
                                title="{{ $point['label'] }}: {{ $point['count'] }}"
                            ></div>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-xs text-zinc-500">
                        <span>{{ $series[0]['label'] ?? '' }}</span>
                        <span>{{ $series[count($series) - 1]['label'] ?? '' }}</span>
                    </div>
                @endif
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-3">
                <flux:heading size="lg" level="3">{{ __('Top forms') }}</flux:heading>

                @forelse ($this->submissionsByForm as $form)
                    <div class="flex items-center justify-between text-sm">
                        <flux:link :href="route('dashboard.submissions.index', ['form' => $form['slug']])" wire:navigate>
                            {{ $form['name'] }}
                        </flux:link>
                        <flux:badge color="zinc">{{ number_format($form['count']) }}</flux:badge>
                    </div>
                @empty
                    <flux:text class="text-sm text-zinc-500">{{ __('No data yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>
</div>
