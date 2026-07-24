<x-layouts::auth.simple :title="__('Form created')">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col items-center gap-3 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                <flux:icon name="check" class="size-6" />
            </div>

            <flux:heading size="lg">{{ __('Your form is ready') }}</flux:heading>
            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('The form ":name" has been created. Drop the embed snippet on your site and you\'re ready to collect submissions.', ['name' => $form->name]) }}
            </flux:text>
        </div>

        {{-- Public submission URL --}}
        <div
            class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
            x-data="{ copied: false }"
        >
            <flux:text class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                {{ __('Public submission URL') }}
            </flux:text>
            <div class="flex items-center gap-2">
                <input
                    type="text"
                    readonly
                    value="{{ $payload['form_url'] }}"
                    class="flex-1 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    data-test="form-url"
                />
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="clipboard"
                    x-on:click="
                        navigator.clipboard.writeText(@js($payload['form_url']));
                        copied = true;
                        setTimeout(() => copied = false, 1500);
                    "
                    x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                ></flux:button>
            </div>
        </div>

        {{-- Embed snippet --}}
        <div
            class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
            x-data="{ copied: false }"
        >
            <flux:text class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                {{ __('Embed snippet') }}
            </flux:text>
            <p class="text-xs text-zinc-600 dark:text-zinc-400">
                {{ __('Paste this anywhere on your site. Visitors who submit it land in your dashboard.') }}
            </p>
            <div class="relative">
                <pre
                    class="max-h-72 overflow-auto rounded-md bg-zinc-50 p-3 font-mono text-xs leading-relaxed text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
                    data-test="embed-snippet"
                ><code>{{ $payload['embed_html'] }}</code></pre>
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="clipboard"
                    class="absolute right-2 top-2"
                    x-on:click="
                        navigator.clipboard.writeText(@js($payload['embed_html']));
                        copied = true;
                        setTimeout(() => copied = false, 1500);
                    "
                    x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                ></flux:button>
            </div>
        </div>

        {{-- Next steps --}}
        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/40">
            <flux:text class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                {{ __('Next steps') }}
            </flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button
                    size="sm"
                    variant="primary"
                    icon="pencil-square"
                    :href="route('dashboard.forms.edit', ['form' => $form->id])"
                    wire:navigate
                >
                    {{ __('Edit fields') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="inbox"
                    :href="route('dashboard.submissions.index')"
                    wire:navigate
                >
                    {{ __('View submissions') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="key"
                    :href="route('dashboard.agent-key')"
                    wire:navigate
                >
                    {{ __('Manage API key') }}
                </flux:button>
            </div>
        </div>
    </div>
</x-layouts::auth.simple>