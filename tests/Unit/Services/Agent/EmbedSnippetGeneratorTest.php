<?php

namespace Tests\Unit\Services\Agent;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Services\Agent\EmbedSnippetGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbedSnippetGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function makeForm(User $user, array $overrides = []): Form
    {
        $form = Form::factory()->ownedBy($user)->create($overrides + [
            'name' => 'contact',
            'slug' => 'contact',
            'min_submission_seconds' => 0,
            'honeypot_field' => 'website',
        ]);

        FormField::query()->create([
            'form_id' => $form->id,
            'name' => 'email',
            'label' => 'Email',
            'type' => FormFieldType::Email->value,
            'required' => true,
            'position' => 0,
            'is_active' => true,
        ]);

        FormField::query()->create([
            'form_id' => $form->id,
            'name' => 'message',
            'label' => 'Message',
            'type' => FormFieldType::Textarea->value,
            'required' => true,
            'position' => 1,
            'is_active' => true,
        ]);

        return $form;
    }

    public function test_snippet_targets_legacy_per_form_endpoint_with_hidden_api_key(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'KFS_per_form_secret_123');

        // Action posts to the legacy /api/forms/{slug} endpoint —
        // the same route the dashboard has always used, now reused
        // for visitor submissions against agent-created forms.
        $this->assertStringContainsString('action="'.url('/api/forms/contact').'"', $html);
        $this->assertStringNotContainsString('/api/submit/', $html);
        $this->assertStringNotContainsString('user_api', $html);

        // Per-form api_key is in a hidden body field — never in the
        // URL (so it never ends up in browser history or Referer
        // headers), never as a header (HTML forms cannot set headers).
        $this->assertStringContainsString('name="api_key"', $html);
        $this->assertStringContainsString('value="KFS_per_form_secret_123"', $html);
    }

    public function test_snippet_renders_all_active_fields(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'KFS_secret');

        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('<textarea', $html);
    }

    public function test_snippet_includes_honeypot(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user, ['honeypot_field' => 'website']);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'KFS_secret');

        $this->assertStringContainsString('left:-9999px', $html);
        $this->assertStringContainsString('name="website"', $html);
    }

    public function test_snippet_includes_timestamp_when_min_seconds_positive(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user, ['min_submission_seconds' => 5]);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'KFS_secret');

        $this->assertStringContainsString('name="_timestamp"', $html);
    }

    public function test_snippet_omits_timestamp_when_min_seconds_zero(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user, ['min_submission_seconds' => 0]);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'KFS_secret');

        $this->assertStringNotContainsString('name="_timestamp"', $html);
    }

    public function test_snippet_uses_placeholder_when_api_key_empty(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        // An empty key (e.g. when rendering a preview for a browser
        // request that didn't authenticate) uses a placeholder string
        // so the rendered HTML does not contain a working key.
        $html = $generator->build($form, '');

        $this->assertStringContainsString('__YOUR_FORM_KEY__', $html);
    }

    public function test_snippet_escapes_user_supplied_field_values(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'KFS_secret"injected');

        // The injected quote character must be HTML-escaped.
        $this->assertStringNotContainsString('KFS_secret"injected', $html);
        $this->assertStringContainsString('KFS_secret&quot;injected', $html);
    }
}
