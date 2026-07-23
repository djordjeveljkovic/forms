<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <flux:link :href="route('dashboard.forms.index')" wire:navigate>{{ __('Forms') }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <flux:link :href="route('dashboard.forms.edit', $form)" wire:navigate>{{ $form->name }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <span>{{ __('Demo') }}</span>
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">
                {{ __('Test :form', ['form' => $form->name]) }}
            </flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                {{ __('Submit real test data and see how your form behaves.') }}
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" :href="route('dashboard.forms.edit', $form)" wire:navigate icon="cog-6-tooth">
                {{ __('Form settings') }}
            </flux:button>
        </div>
    </div>

    <div class="flex gap-1 rounded-lg border border-zinc-200 p-1 dark:border-zinc-700">
        <button
            type="button"
            wire:click="setTab('test')"
            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition
                {{ $activeTab === 'test' ? 'bg-blue-500 text-white' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"
        >
            <span class="inline-flex items-center justify-center gap-2">
                <flux:icon name="play" class="size-4" />
                {{ __('Test') }}
            </span>
        </button>
        <button
            type="button"
            wire:click="setTab('code')"
            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition
                {{ $activeTab === 'code' ? 'bg-blue-500 text-white' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"
        >
            <span class="inline-flex items-center justify-center gap-2">
                <flux:icon name="code-bracket" class="size-4" />
                {{ __('Embed code') }}
            </span>
        </button>
    </div>

    @if ($activeTab === 'test')
        <div class="grid gap-4 lg:grid-cols-3">
            <flux:card class="lg:col-span-2">
                <form wire:submit="submit" class="flex flex-col gap-4">
                    @forelse ($this->fields as $field)
                        @php
                            $required = $field->required;
                            $typeValue = $field->typeEnum()->value;
                        @endphp
                        <div wire:key="field-{{ $field->id }}">
                            @switch($typeValue)
                                @case('textarea')
                                    <flux:textarea
                                        wire:model="values.{{ $field->name }}"
                                        :label="$field->label"
                                        :placeholder="$field->placeholder"
                                        :required="$required"
                                        :description="$field->help_text"
                                        rows="4"
                                    />
                                    @break
                                @case('select')
                                    <flux:select wire:model="values.{{ $field->name }}" :label="$field->label" :required="$required" :description="$field->help_text" :placeholder="$field->placeholder">
                                        @foreach ((array) $field->options as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </flux:select>
                                    @break
                                @case('radio')
                                    <flux:radio.group wire:model="values.{{ $field->name }}" :label="$field->label" :description="$field->help_text">
                                        @foreach ((array) $field->options as $option)
                                            <flux:radio value="{{ $option }}" :label="$option" />
                                        @endforeach
                                    </flux:radio.group>
                                    @break
                                @case('checkbox')
                                    <div>
                                        <flux:text class="mb-2 text-sm font-medium">{{ $field->label }}{{ $required ? ' *' : '' }}</flux:text>
                                        @if ($field->help_text)
                                            <flux:text class="mb-2 text-xs text-zinc-500">{{ $field->help_text }}</flux:text>
                                        @endif
                                        <div class="space-y-1">
                                            @foreach ((array) $field->options as $option)
                                                <flux:checkbox
                                                    wire:model="values.{{ $field->name }}"
                                                    value="{{ $option }}"
                                                    :label="$option"
                                                />
                                            @endforeach
                                        </div>
                                    </div>
                                    @break
                                @case('hidden')
                                    <flux:input wire:model="values.{{ $field->name }}" type="hidden" />
                                    @break
                                @case('number')
                                    <flux:input wire:model="values.{{ $field->name }}" type="number" :label="$field->label" :placeholder="$field->placeholder" :required="$required" :description="$field->help_text" />
                                    @break
                                @case('date')
                                    <flux:input wire:model="values.{{ $field->name }}" type="date" :label="$field->label" :required="$required" :description="$field->help_text" />
                                    @break
                                @case('time')
                                    <flux:input wire:model="values.{{ $field->name }}" type="time" :label="$field->label" :required="$required" :description="$field->help_text" />
                                    @break
                                @case('email')
                                    <flux:input wire:model="values.{{ $field->name }}" type="email" :label="$field->label" :placeholder="$field->placeholder" :required="$required" :description="$field->help_text" />
                                    @break
                                @case('url')
                                    <flux:input wire:model="values.{{ $field->name }}" type="url" :label="$field->label" :placeholder="$field->placeholder" :required="$required" :description="$field->help_text" />
                                    @break
                                @case('tel')
                                    <flux:input wire:model="values.{{ $field->name }}" type="tel" :label="$field->label" :placeholder="$field->placeholder" :required="$required" :description="$field->help_text" />
                                    @break
                                @case('file')
                                    <flux:input wire:model="values.{{ $field->name }}" type="file" :label="$field->label" :required="$required" :description="$field->help_text" />
                                    @break
                                @default
                                    <flux:input wire:model="values.{{ $field->name }}" type="text" :label="$field->label" :placeholder="$field->placeholder" :required="$required" :description="$field->help_text" />
                            @endswitch
                        </div>
                    @empty
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('This form has no fields configured yet. Edit the form to add some.') }}
                        </flux:text>
                    @endforelse

                    @if ($this->fields->isNotEmpty())
                        @if ($form->hasTurnstile())
                            <div class="flex flex-col gap-1">
                                <flux:text class="text-xs text-zinc-500">
                                    {{ __('This form has Cloudflare Turnstile enabled. Use the embed snippet for a real widget; the test form above will accept any token locally.') }}
                                </flux:text>
                                <flux:input
                                    wire:model="values.cf-turnstile-response"
                                    :label="__('Turnstile token (test)')"
                                    placeholder="XXXX.DUMMY.TOKEN.XXXX"
                                />
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-2 pt-2">
                            <flux:button type="button" variant="ghost" wire:click="resetForm">
                                {{ __('Reset') }}
                            </flux:button>
                            <flux:button type="submit" variant="primary" icon="paper-airplane">
                                {{ __('Submit test submission') }}
                            </flux:button>
                        </div>
                    @endif
                </form>
            </flux:card>

            <flux:card>
                <div class="flex flex-col gap-3">
                    <flux:heading size="lg" level="3">{{ __('Response') }}</flux:heading>

                    @if (! $result)
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Submit the form to see the API response here.') }}
                        </flux:text>
                    @else
                        <div class="flex items-center gap-2">
                            <flux:badge :color="$result['status'] < 400 ? 'green' : 'red'">
                                {{ __('HTTP :code', ['code' => $result['status']]) }}
                            </flux:badge>
                        </div>

                        @if (($result['status'] ?? 0) < 400)
                            <flux:callout variant="success" icon="check-circle">
                                {{ $result['body']['message'] ?? __('Submitted successfully.') }}
                            </flux:callout>
                        @else
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                {{ $result['body']['message'] ?? __('Validation failed.') }}
                            </flux:callout>

                            @if (! empty($result['body']['errors']))
                                <ul class="space-y-1 text-xs text-red-600 dark:text-red-400">
                                    @foreach ($result['body']['errors'] as $field => $messages)
                                        @foreach ((array) $messages as $message)
                                            <li><code class="font-mono">{{ $field }}</code>: {{ $message }}</li>
                                        @endforeach
                                    @endforeach
                                </ul>
                            @endif
                        @endif

                        <flux:separator />

                        <details>
                            <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700">
                                {{ __('Raw response') }}
                            </summary>
                            <pre class="mt-2 overflow-x-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800"><code>{{ json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        </details>
                    @endif
                </div>
            </flux:card>
        </div>
    @else
        <flux:card>
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Embed this form on your site') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">
                        {{ __('Drop one of the snippets below into your HTML. Submissions hit the same API endpoint as this demo.') }}
                    </flux:text>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Endpoint') }}</dt>
                        <dd class="mt-1">
                            <code class="block break-all rounded bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800">
                                {{ url('/api/forms/'.$form->slug) }}
                            </code>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('API key') }}</dt>
                        <dd class="mt-1 flex items-center gap-1">
                            <code class="flex-1 break-all rounded bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800">{{ $apiKey }}</code>
                            <flux:button
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="clipboard"
                                x-on:click="navigator.clipboard.writeText('{{ $apiKey }}'); $flux.toast({ text: '{{ __('Copied!') }}' })"
                            />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Method') }}</dt>
                        <dd class="mt-1"><flux:badge color="zinc">POST</flux:badge></dd>
                    </div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ __('Vanilla HTML form') }}</flux:heading>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText(document.getElementById('snippet-html').innerText); $flux.toast({ text: '{{ __('Copied!') }}' })"
                    >
                        {{ __('Copy') }}
                    </flux:button>
                </div>

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Simplest option: the form posts directly to the API. The API key is passed as a query string because browsers do not allow custom headers on plain HTML forms.') }}
                </flux:text>

                <pre id="snippet-html" class="overflow-x-auto rounded bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100 dark:bg-zinc-950"><code>{{ $this->htmlSnippet }}</code></pre>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ __('JavaScript (fetch)') }}</flux:heading>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText(document.getElementById('snippet-js').innerText); $flux.toast({ text: '{{ __('Copied!') }}' })"
                    >
                        {{ __('Copy') }}
                    </flux:button>
                </div>

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Use this when you want to handle the response in JavaScript and update the UI without a page reload.') }}
                </flux:text>

                <pre id="snippet-js" class="overflow-x-auto rounded bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100 dark:bg-zinc-950"><code>{{ $this->jsSnippet }}</code></pre>
            </div>
        </flux:card>
    @endif
</div>
