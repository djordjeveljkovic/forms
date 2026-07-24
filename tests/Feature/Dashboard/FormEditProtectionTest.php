<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\FormEdit;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for the "Spam protection & redirect" settings on the form edit
 * / create pages.
 */
class FormEditProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_save_spam_protection_settings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('successRedirectUrl', 'https://example.com/thank-you')
            ->set('minSubmissionSeconds', 5)
            ->set('honeypotField', 'company_url')
            ->set('captchaProvider', 'none')
            ->call('save')
            ->assertHasNoErrors();

        $form->refresh();
        $this->assertSame('https://example.com/thank-you', $form->success_redirect_url);
        $this->assertSame(5, $form->min_submission_seconds);
        $this->assertSame('company_url', $form->honeypot_field);
    }

    public function test_can_save_turnstile_settings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('captchaProvider', 'turnstile')
            ->set('captchaSiteKey', '1x00000000000000000000AA')
            ->set('captchaSecretKey', '1x0000000000000000000000000000000AA')
            ->call('save')
            ->assertHasNoErrors();

        $form->refresh();
        $this->assertSame('turnstile', $form->captcha_provider);
        $this->assertSame('1x00000000000000000000AA', $form->captcha_site_key);
        $this->assertSame('1x0000000000000000000000000000000AA', $form->captcha_secret_key);
    }

    public function test_turnstile_secret_key_is_encrypted_in_db(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('captchaProvider', 'turnstile')
            ->set('captchaSiteKey', 'site-key')
            ->set('captchaSecretKey', 'super-secret-key-789')
            ->call('save')
            ->assertHasNoErrors();

        // The raw DB value (the encrypted column) should NOT contain
        // the plain-text secret.
        $raw = \DB::table('forms')->where('id', $form->id)->value('captcha_secret_key');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('super-secret-key-789', $raw);

        // The model attribute should transparently decrypt it.
        $form->refresh();
        $this->assertSame('super-secret-key-789', $form->captcha_secret_key);
    }

    public function test_clearing_turnstile_secret_key_keeps_existing_value(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create([
            'captcha_provider' => 'turnstile',
            'captcha_site_key' => 'site-key',
            'captcha_secret_key' => 'original-secret',
        ]);

        Livewire::test(FormEdit::class, ['form' => $form])
            // Simulate the password field being empty in the form.
            ->set('captchaSecretKey', '')
            ->call('save')
            ->assertHasNoErrors();

        $form->refresh();
        $this->assertSame('original-secret', $form->captcha_secret_key);
    }

    public function test_success_redirect_url_must_be_a_valid_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('successRedirectUrl', 'not-a-url')
            ->call('save')
            ->assertHasErrors(['successRedirectUrl']);
    }

    public function test_min_submission_seconds_must_be_within_range(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('minSubmissionSeconds', -1)
            ->call('save')
            ->assertHasErrors(['minSubmissionSeconds']);

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('minSubmissionSeconds', 700) // over 600
            ->call('save')
            ->assertHasErrors(['minSubmissionSeconds']);
    }

    public function test_honeypot_field_name_must_be_a_valid_identifier(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('honeypotField', 'has spaces')
            ->call('save')
            ->assertHasErrors(['honeypotField']);
    }

    public function test_captcha_provider_must_be_a_known_value(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('captchaProvider', 'recaptcha')
            ->call('save')
            ->assertHasErrors(['captchaProvider']);
    }
}
