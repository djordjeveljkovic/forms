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

    public function test_snippet_contains_action_url_and_hidden_user_api(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'forms_sk_abc123');

        $this->assertStringContainsString('action="'.url('/api/submit/contact').'"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_user_api"', $html);
        $this->assertStringContainsString('value="forms_sk_abc123"', $html);
    }

    public function test_snippet_renders_all_active_fields(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'forms_sk_abc123');

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

        $html = $generator->build($form, 'forms_sk_abc123');

        $this->assertStringContainsString('left:-9999px', $html);
        $this->assertStringContainsString('name="website"', $html);
    }

    public function test_snippet_includes_timestamp_when_min_seconds_positive(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user, ['min_submission_seconds' => 5]);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'forms_sk_abc123');

        $this->assertStringContainsString('name="_timestamp"', $html);
    }

    public function test_snippet_omits_timestamp_when_min_seconds_zero(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user, ['min_submission_seconds' => 0]);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'forms_sk_abc123');

        $this->assertStringNotContainsString('name="_timestamp"', $html);
    }

    public function test_query_string_mode_puts_key_in_action_url(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'forms_sk_abc123', useQueryString: true);

        $this->assertStringContainsString('user_api=forms_sk_abc123', $html);
        $this->assertStringNotContainsString('name="_user_api"', $html);
    }

    public function test_snippet_escapes_user_supplied_field_values(): void
    {
        $user = User::factory()->create();
        $form = $this->makeForm($user);
        $generator = new EmbedSnippetGenerator;

        $html = $generator->build($form, 'forms_sk_abc"injected');

        // The injected quote character must be HTML-escaped.
        $this->assertStringNotContainsString('abc"injected', $html);
        $this->assertStringContainsString('abc&quot;injected', $html);
    }
}
