@php
    /** @var \App\Models\Form $form */
    /** @var \App\Models\FormSubmission $submission */
    /** @var array<string, mixed> $data */
@endphp
New submission for {{ $form->name }}

A new submission was received on {{ $submittedAt?->toDayDateTimeString() }}.

@foreach ($data as $key => $value)
{{ is_string($key) ? ucwords(str_replace(['_', '-'], ' ', $key)) : 'Field '.$key }}:
@if (is_array($value) || is_object($value))
@foreach (json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) as $line)
{{ $line }}
@endforeach
@else
{{ is_string($value) ? $value : json_encode($value) }}
@endif

@endforeach
@if ($submission->ip_address)
IP address: {{ $submission->ip_address }}
@endif
@if ($submission->user_agent)
User agent: {{ $submission->user_agent }}
@endif
