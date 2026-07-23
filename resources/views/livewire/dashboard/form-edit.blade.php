<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <flux:link :href="route('dashboard.forms.index')" wire:navigate>{{ __('Forms') }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <span>{{ $form->name }}</span>
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $form->name }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                <code class="rounded bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">{{ $form->endpoint }}</code>
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button
                variant="primary"
                :href="route('dashboard.forms.demo', $form)"
                wire:navigate
                icon="play"
            >
                {{ __('Test form') }}
            </flux:button>
            <flux:button variant="ghost" :href="route('dashboard.submissions.index', ['form' => $form->slug])" wire:navigate icon="inbox">
                {{ __('View submissions') }}
            </flux:button>
        </div>
    </div>

    <flux:card>
        <div class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('API credentials') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">
                {{ __('Send this key with each submission as the X-Form-Key header or ?api_key= query parameter.') }}
            </flux:text>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="flex-1 break-all rounded bg-zinc-100 px-3 py-2 text-xs dark:bg-zinc-800">{{ $apiKey }}</code>
                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="clipboard"
                    x-on:click="navigator.clipboard.writeText('{{ $apiKey }}'); $flux.toast({ text: '{{ __('Copied!') }}' })"
                >
                    {{ __('Copy') }}
                </flux:button>
                <flux:button type="button" variant="ghost" size="sm" icon="arrow-path" wire:click="regenerateApiKey">
                    {{ __('Regenerate') }}
                </flux:button>
            </div>

            <div class="flex flex-wrap items-center gap-2 pt-2">
                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="arrow-down-tray"
                    wire:click="exportJson"
                >
                    {{ __('Export JSON') }}
                </flux:button>
                <flux:text class="text-xs text-zinc-500">
                    {{ __('Download this form\'s configuration (without the API key) for backup or import on another site.') }}
                </flux:text>
            </div>
        </div>
    </flux:card>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Basics') }}</flux:heading>

                <flux:input wire:model="name" :label="__('Name')" required />

                <flux:textarea wire:model="description" :label="__('Description')" rows="2" />
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ __('Fields') }}</flux:heading>
                    <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addField">
                        {{ __('Add field') }}
                    </flux:button>
                </div>

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Each submission is validated against this list. Existing submissions keep their stored data.') }}
                </flux:text>

                @error('fields')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                @forelse ($fields as $index => $field)
                    <div
                        wire:key="field-{{ $index }}"
                        class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                    >
                        <div class="flex items-center justify-between">
                            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                {{ __('Field :n', ['n' => $index + 1]) }}
                            </flux:text>
                            <div class="flex items-center gap-1">
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-up"
                                    wire:click="moveField({{ $index }}, 'up')"
                                    :disabled="$index === 0"
                                />
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-down"
                                    wire:click="moveField({{ $index }}, 'down')"
                                    :disabled="$index === count($fields) - 1"
                                />
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="removeField({{ $index }})"
                                />
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input
                                wire:model="fields.{{ $index }}.name"
                                :label="__('Key')"
                                placeholder="email"
                            />
                            <flux:input
                                wire:model="fields.{{ $index }}.label"
                                :label="__('Label')"
                                placeholder="Email address"
                            />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:select wire:model="fields.{{ $index }}.type" :label="__('Type')">
                                @foreach ($this->fieldTypes() as $type)
                                    <option value="{{ $type['value'] }}">{{ $type['label'] }}</option>
                                @endforeach
                            </flux:select>
                            <div class="flex items-end">
                                <flux:switch
                                    wire:model="fields.{{ $index }}.required"
                                    :label="__('Required')"
                                />
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input
                                wire:model="fields.{{ $index }}.placeholder"
                                :label="__('Placeholder (optional)')"
                            />
                            <flux:input
                                wire:model="fields.{{ $index }}.help_text"
                                :label="__('Help text (optional)')"
                            />
                        </div>

                        @if (in_array($field['type'] ?? 'text', ['select', 'radio', 'checkbox'], true))
                            <flux:textarea
                                wire:model="fields.{{ $index }}.options"
                                :label="__('Options (one per line)')"
                                rows="3"
                                placeholder="Option A&#10;Option B&#10;Option C"
                            />
                        @endif
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-200 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('No fields configured. Add at least one field so the form knows what to accept.') }}
                        </flux:text>
                    </div>
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ __('Recipients') }}</flux:heading>
                    <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addRecipient">
                        {{ __('Add recipient') }}
                    </flux:button>
                </div>

                @foreach ($recipientEmails as $index => $email)
                    <div class="flex items-end gap-2" wire:key="recipient-{{ $index }}">
                        <flux:input
                            wire:model="recipientEmails.{{ $index }}"
                            type="email"
                            :label="$index === 0 ? __('Email address') : null"
                            class="flex-1"
                        />
                        @if (count($recipientEmails) > 1)
                            <flux:button
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                wire:click="removeRecipient({{ $index }})"
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Email') }}</flux:heading>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="fromEmail" type="email" :label="__('From email (optional)')" />
                    <flux:input wire:model="fromName" :label="__('From name (optional)')" />
                </div>

                <flux:input wire:model="subjectTemplate" :label="__('Subject template')" required />
                <flux:text class="-mt-3 text-xs text-zinc-500">
                    {{ __('Use :form_name and :form_slug placeholders.') }}
                </flux:text>

                <flux:input wire:model="submitterReplyToField" :label="__('Reply-to field (optional)')" />

                <flux:textarea wire:model="successMessage" :label="__('Success message')" rows="2" />
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Security') }}</flux:heading>

                <flux:textarea
                    wire:model="allowedOrigins"
                    :label="__('Allowed origins (optional)')"
                    rows="3"
                />
                <flux:text class="-mt-3 text-xs text-zinc-500">
                    {{ __('One origin per line. Leave blank to allow any origin.') }}
                </flux:text>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Spam protection & redirect') }}</flux:heading>

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Spam protection is always on. Add a CAPTCHA when the form is on a high-traffic page.') }}
                </flux:text>

                <flux:input
                    wire:model="successRedirectUrl"
                    type="url"
                    :label="__('Success redirect URL (optional)')"
                    placeholder="https://example.com/thank-you"
                />
                <flux:text class="-mt-3 text-xs text-zinc-500">
                    {{ __('Where to send the user after a successful submission. Leave blank to return JSON. The form can override this with a hidden _redirect field.') }}
                </flux:text>

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input
                        wire:model="minSubmissionSeconds"
                        type="number"
                        min="0"
                        max="600"
                        :label="__('Minimum submission time (seconds)')"
                        description="Reject submissions faster than this. 0 disables."
                    />
                    <flux:input
                        wire:model="honeypotField"
                        :label="__('Honeypot field name')"
                        description="Hidden field that bots fill in. Keep the default unless you have a reason to change it."
                    />
                </div>

                <flux:select wire:model="captchaProvider" :label="__('CAPTCHA provider')">
                    <option value="none">{{ __('None') }}</option>
                    <option value="turnstile">{{ __('Cloudflare Turnstile') }}</option>
                </flux:select>

                @if ($captchaProvider === 'turnstile')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input
                            wire:model="captchaSiteKey"
                            :label="__('Turnstile site key')"
                            placeholder="0x4AAAAAAA..."
                        />
                        <flux:input
                            wire:model="captchaSecretKey"
                            type="password"
                            :label="__('Turnstile secret key')"
                            placeholder="{{ $form->captcha_secret_key ? __('•••••••• (leave blank to keep)') : '0x4AAAAAAA...' }}"
                            view
                        />
                    </div>
                    <flux:text class="-mt-3 text-xs text-zinc-500">
                        {{ __('Get keys from ') }}<flux:link href="https://www.cloudflare.com/products/turnstile/" target="_blank">cloudflare.com/products/turnstile</flux:link>.
                    </flux:text>
                @endif
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Behaviour') }}</flux:heading>

                <flux:switch wire:model="storeSubmissions" :label="__('Store submissions in database')" />
                <flux:switch wire:model="sendEmail" :label="__('Send email notifications')" />
                <flux:switch
                    wire:model="autoDiscoverFields"
                    :label="__('Auto-discover fields on first submission')"
                    description="When no fields are configured, the first submission creates field definitions from the incoming data."
                />
            </div>
        </flux:card>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:button
                type="button"
                variant="danger"
                icon="trash"
                x-on:click.prevent="if (confirm('{{ __('Are you sure? This will delete the form and all its submissions.') }}')) $wire.delete()"
            >
                {{ __('Delete form') }}
            </flux:button>

            <div class="flex gap-2">
                <flux:button :href="route('dashboard.forms.index')" wire:navigate variant="ghost">
                    {{ __('Back') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="check">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </div>
    </form>
</div>
