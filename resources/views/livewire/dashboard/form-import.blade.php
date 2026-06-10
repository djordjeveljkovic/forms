<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <flux:link :href="route('dashboard.forms.index')" wire:navigate>{{ __('Forms') }}</flux:link>
        <flux:icon name="chevron-right" class="size-4" />
        <span>{{ __('Import') }}</span>
    </div>

    <div>
        <flux:heading size="xl" level="1">{{ __('Import a form configuration') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            {{ __('Upload a JSON file exported from this app, paste its contents, or paste a fields-only array.') }}
        </flux:text>
    </div>

    @if ($previewMode === 'fields' && $preview)
        <flux:callout variant="info" icon="sparkles">
            {{ __('Fields-only payload detected. Fill in the form details below to create a new form.') }}
        </flux:callout>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Source') }}</flux:heading>

                <flux:input
                    type="file"
                    wire:model="file"
                    accept="application/json,.json"
                    :label="__('Upload JSON file')"
                />

                <flux:text class="text-center text-xs uppercase tracking-wide text-zinc-500">{{ __('or') }}</flux:text>

                <flux:textarea
                    wire:model.live.debounce.300ms="rawJson"
                    :label="__('Paste JSON')"
                    rows="10"
                    placeholder='{ "form": { "name": "Contact", ... }, "fields": [...] }'
                />

                @if ($importError)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        {{ $importError }}
                    </flux:callout>
                @endif
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ __('Preview') }}</flux:heading>
                    @if ($preview)
                        <flux:badge color="green" icon="check">
                            @if ($previewMode === 'full')
                                {{ __('Full form config') }}
                            @else
                                {{ __('Fields-only payload') }}
                            @endif
                        </flux:badge>
                    @endif
                </div>

                @if (! $preview)
                    <div class="flex h-48 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="document-magnifying-glass" class="size-8 text-zinc-300" />
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Upload a file or paste JSON to preview.') }}
                        </flux:text>
                    </div>
                @elseif ($previewMode === 'full')
                    @php
                        $formData = $preview['form'] ?? [];
                        $fieldsList = $preview['fields'] ?? [];
                    @endphp
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Name') }}</dt>
                            <dd class="font-medium">{{ $formData['name'] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Recipients') }}</dt>
                            <dd class="text-right text-xs">
                                @foreach (($formData['recipient_emails'] ?? []) as $recipient)
                                    <div>{{ $recipient }}</div>
                                @endforeach
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Subject') }}</dt>
                            <dd class="text-right text-xs">{{ $formData['subject_template'] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Fields') }}</dt>
                            <dd>{{ count($fieldsList) }}</dd>
                        </div>
                    </dl>

                    <flux:separator />

                    <div>
                        <flux:text class="mb-2 text-xs uppercase tracking-wide text-zinc-500">{{ __('Field summary') }}</flux:text>
                        <ul class="space-y-1 text-sm">
                            @foreach ($fieldsList as $field)
                                <li class="flex items-center gap-2">
                                    <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">{{ $field['name'] }}</code>
                                    <flux:text class="truncate text-zinc-500">{{ $field['label'] }}</flux:text>
                                    <flux:badge size="sm" color="zinc">{{ $field['type'] }}</flux:badge>
                                    @if (! empty($field['required']))
                                        <flux:badge size="sm" color="amber">required</flux:badge>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <flux:text class="text-sm text-zinc-500">
                        {{ __('Provide form details below to combine with the staged field definitions.') }}
                    </flux:text>

                    <div class="flex flex-col gap-3">
                        @foreach ($preview as $field)
                            <div class="flex items-center gap-2">
                                <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">{{ $field['name'] }}</code>
                                <flux:text class="truncate text-zinc-500">{{ $field['label'] }}</flux:text>
                                <flux:badge size="sm" color="zinc">{{ $field['type'] }}</flux:badge>
                                @if (! empty($field['required']))
                                    <flux:badge size="sm" color="amber">required</flux:badge>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </flux:card>
    </div>

    @if ($previewMode === 'fields' && $preview)
        <flux:card>
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ __('Form details') }}</flux:heading>
                    <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addFormRecipient">
                        {{ __('Add recipient') }}
                    </flux:button>
                </div>

                <flux:input wire:model="formName" :label="__('Name')" required />

                <flux:textarea wire:model="formDescription" :label="__('Description')" rows="2" />

                <div>
                    <flux:text class="mb-2 text-sm font-medium">{{ __('Recipients') }} *</flux:text>
                    @foreach ($formRecipientEmails as $index => $email)
                        <div class="mb-2 flex items-end gap-2" wire:key="form-recipient-{{ $index }}">
                            <flux:input
                                wire:model="formRecipientEmails.{{ $index }}"
                                type="email"
                                :label="$index === 0 ? __('Email address') : null"
                                placeholder="team@example.com"
                                class="flex-1"
                            />
                            @if (count($formRecipientEmails) > 1)
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="removeFormRecipient({{ $index }})"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="formFromEmail" type="email" :label="__('From email (optional)')" />
                    <flux:input wire:model="formFromName" :label="__('From name (optional)')" />
                </div>

                <flux:input wire:model="formSubjectTemplate" :label="__('Subject template')" />

                <flux:textarea wire:model="formSuccessMessage" :label="__('Success message')" rows="2" />

                <flux:textarea
                    wire:model="formAllowedOrigins"
                    :label="__('Allowed origins (optional)')"
                    rows="2"
                />

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:switch wire:model="formStoreSubmissions" :label="__('Store submissions')" />
                    <flux:switch wire:model="formSendEmail" :label="__('Send email notifications')" />
                </div>
            </div>
        </flux:card>
    @endif

    <div class="flex justify-end gap-2">
        <flux:button :href="route('dashboard.forms.index')" wire:navigate variant="ghost">
            {{ __('Cancel') }}
        </flux:button>
        <flux:button
            type="button"
            variant="primary"
            icon="arrow-down-tray"
            wire:click="import"
            :disabled="! $preview"
        >
            {{ __('Import form') }}
        </flux:button>
    </div>
</div>
