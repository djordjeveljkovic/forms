@php
    /** @var \App\Models\Form $form */
    /** @var \App\Models\FormSubmission $submission */
    /** @var array<string, mixed> $data */
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
    {{ $form->name }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
# New submission for {{ $form->name }}

A new submission was received on **{{ $submittedAt?->toDayDateTimeString() }}**.

@foreach ($data as $key => $value)
**{{ is_string($key) ? ucwords(str_replace(['_', '-'], ' ', $key)) : 'Field '.$key }}:**
@if (is_array($value) || is_object($value))
```json
{!! json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) !!}
```
@else
{{ is_string($value) ? $value : json_encode($value) }}
@endif

@endforeach

@if ($submission->ip_address)
**IP address:** {{ $submission->ip_address }}
@endif

@if ($submission->user_agent)
**User agent:** {{ $submission->user_agent }}
@endif

{{-- Subcopy --}}
<x-slot:subcopy>
<x-mail::subcopy>
    Manage submissions for this form in the {{ config('app.name') }} dashboard.
</x-mail::subcopy>
</x-slot:subcopy>

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
