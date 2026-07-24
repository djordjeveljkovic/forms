<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Forms agent API key') }}</flux:heading>
        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('Hand this key to an AI agent so it can create forms on your behalf via the ') }}
            <code class="rounded bg-zinc-100 px-1 py-0.5 font-mono text-xs dark:bg-zinc-800">/api/agent/forms</code>
            {{ __(' endpoint.') }}
        </flux:text>
    </div>

    <flux:card class="space-y-4">
        <div class="flex flex-col gap-1">
            <flux:heading size="sm">{{ __('Status') }}</flux:heading>
            @if ($this->hasToken)
                <div class="flex items-center gap-2">
                    <flux:badge color="emerald" variant="solid">
                        {{ __('Active') }}
                    </flux:badge>
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Key ending in …:fingerprint', ['fingerprint' => $this->tokenFingerprint]) }}
                    </flux:text>
                </div>

                <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex flex-col">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Created') }}
                        </dt>
                        <dd>{{ $this->currentToken->created_at->diffForHumans() }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Last used') }}
                        </dt>
                        <dd>
                            {{ $this->currentToken->last_used_at?->diffForHumans() ?? __('Never') }}
                        </dd>
                    </div>
                </dl>
            @else
                <div class="flex items-center gap-2">
                    <flux:badge color="zinc">{{ __('No key') }}</flux:badge>
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Generate one to start handing forms off to AI agents.') }}
                    </flux:text>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
            @if ($this->hasToken)
                <flux:button
                    variant="primary"
                    icon="arrow-path"
                    wire:click="generate"
                    wire:confirm="{{ __('Generating a new key will immediately invalidate the current one. Continue?') }}"
                >
                    {{ __('Rotate key') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    icon="trash"
                    wire:click="confirmRevoke"
                >
                    {{ __('Revoke key') }}
                </flux:button>
            @else
                <flux:button
                    variant="primary"
                    icon="key"
                    wire:click="generate"
                >
                    {{ __('Generate key') }}
                </flux:button>
            @endif
        </div>
    </flux:card>

    <flux:callout variant="info" icon="information-circle">
        <flux:callout.heading>{{ __('How to use this key') }}</flux:callout.heading>
        <flux:callout.text>
            <ol class="ml-4 list-decimal space-y-2 text-sm">
                <li>
                    {{ __('Hand the plaintext key to your AI agent and ask it to read ') }}
                    <code class="rounded bg-white px-1 py-0.5 font-mono text-xs dark:bg-zinc-800">/llms.txt</code>
                    {{ __(' to learn the create-form convention.') }}
                </li>
                <li>
                    {{ __('The agent will POST your snippet to ') }}
                    <code class="rounded bg-white px-1 py-0.5 font-mono text-xs dark:bg-zinc-800">/api/agent/forms</code>
                    {{ __(' and receive a copy-pasteable HTML snippet back.') }}
                </li>
                <li>
                    {{ __('Drop the snippet on your site. Submissions arrive in ') }}
                    <a href="{{ route('dashboard.submissions.index') }}" class="underline" wire:navigate>
                        {{ __('your submissions dashboard') }}
                    </a>
                    {{ __(' and the existing email pipeline delivers notifications.') }}
                </li>
            </ol>
        </flux:callout.text>
    </flux:callout>

    <flux:modal wire:model="revealModalOpen" class="md:w-[36rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Your new API key') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Copy this now — for security reasons we will never show it to you again.') }}
                </flux:text>
            </div>

            <div
                class="flex items-center gap-2"
                x-data="{ copied: false }"
            >
                <input
                    type="text"
                    readonly
                    value="{{ $revealedKey }}"
                    class="flex-1 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    data-test="revealed-key"
                    x-ref="key"
                />
                <flux:button
                    size="sm"
                    variant="primary"
                    icon="clipboard"
                    x-on:click="
                        navigator.clipboard.writeText(@js($revealedKey));
                        copied = true;
                        setTimeout(() => copied = false, 1500);
                    "
                    x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                ></flux:button>
            </div>

            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text class="text-sm">
                    {{ __('Treat this key like a password. Anyone with it can create forms under your account.') }}
                </flux:callout.text>
            </flux:callout>

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="closeRevealModal">
                    {{ __('I have saved it') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="revokeModalOpen" class="md:w-[28rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Revoke API key?') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Any AI agent currently using this key will immediately lose access.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="subtle" wire:click="closeRevokeModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="danger" icon="trash" wire:click="revoke">
                    {{ __('Revoke') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>