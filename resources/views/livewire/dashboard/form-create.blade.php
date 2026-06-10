<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <flux:link :href="route('dashboard.forms.index')" wire:navigate>{{ __('Forms') }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <span>{{ __('New form') }}</span>
    </div>

    <div>
        <flux:heading size="xl" level="1">{{ __('Create a new form') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">{{ __('Configure recipients, fields, behaviour, and your API endpoint.') }}</flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Basics') }}</flux:heading>

                <flux:input wire:model="name" :label="__('Name')" required placeholder="Contact form" />

                <flux:textarea
                    wire:model="description"
                    :label="__('Description')"
                    :placeholder="__('A short description for your team.')"
                    rows="2"
                />
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
                    {{ __('Define which fields this form accepts. Each submission is validated against this list.') }}
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
                            {{ __('No fields yet. Add at least one field so the form knows what to accept.') }}
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

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Submissions will be emailed to every address below.') }}
                </flux:text>

                @foreach ($recipientEmails as $index => $email)
                    <div class="flex items-end gap-2" wire:key="recipient-{{ $index }}">
                        <flux:input
                            wire:model="recipientEmails.{{ $index }}"
                            type="email"
                            :label="$index === 0 ? __('Email address') : null"
                            placeholder="team@example.com"
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
                    <flux:input
                        wire:model="fromEmail"
                        type="email"
                        :label="__('From email (optional)')"
                        placeholder="hello@example.com"
                    />
                    <flux:input
                        wire:model="fromName"
                        :label="__('From name (optional)')"
                        placeholder="Acme Forms"
                    />
                </div>

                <flux:input
                    wire:model="subjectTemplate"
                    :label="__('Subject template')"
                    required
                    placeholder="New submission for :form_name"
                />
                <flux:text class="-mt-3 text-xs text-zinc-500">
                    {{ __('Use :form_name and :form_slug placeholders.') }}
                </flux:text>

                <flux:input
                    wire:model="submitterReplyToField"
                    :label="__('Reply-to field (optional)')"
                    placeholder="email"
                />
                <flux:text class="-mt-3 text-xs text-zinc-500">
                    {{ __('Field key whose value is used as the email Reply-To.') }}
                </flux:text>

                <flux:textarea
                    wire:model="successMessage"
                    :label="__('Success message')"
                    rows="2"
                />
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Security') }}</flux:heading>

                <flux:textarea
                    wire:model="allowedOrigins"
                    :label="__('Allowed origins (optional)')"
                    rows="3"
                    placeholder="https://example.com&#10;https://www.example.com"
                />
                <flux:text class="-mt-3 text-xs text-zinc-500">
                    {{ __('One origin per line. Leave blank to allow any origin.') }}
                </flux:text>
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

        <div class="flex justify-end gap-2">
            <flux:button :href="route('dashboard.forms.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary" icon="check">
                {{ __('Create form') }}
            </flux:button>
        </div>
    </form>
</div>
