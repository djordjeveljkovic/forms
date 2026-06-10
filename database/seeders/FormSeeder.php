<?php

namespace Database\Seeders;

use App\Enums\FormFieldType;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Database\Seeder;

class FormSeeder extends Seeder
{
    /**
     * Seed sample forms, submissions, fields, and email jobs.
     */
    public function run(): void
    {
        $contact = Form::query()->create([
            'name' => 'Contact',
            'slug' => 'contact',
            'endpoint' => '/api/forms/contact',
            'description' => 'Public contact form for general enquiries.',
            'recipient_emails' => ['hello@example.com', 'support@example.com'],
            'subject_template' => 'New contact submission for :form_name',
            'submitter_reply_to_field' => 'email',
            'store_submissions' => true,
            'send_email' => true,
        ]);

        $contact->fields()->createMany([
            ['name' => 'full_name', 'label' => 'Full name', 'type' => FormFieldType::Text->value, 'required' => true, 'position' => 0, 'is_active' => true],
            ['name' => 'email', 'label' => 'Email address', 'type' => FormFieldType::Email->value, 'required' => true, 'position' => 1, 'is_active' => true],
            ['name' => 'phone', 'label' => 'Phone', 'type' => FormFieldType::Tel->value, 'required' => false, 'placeholder' => '+1 555 1234', 'position' => 2, 'is_active' => true],
            ['name' => 'subject', 'label' => 'Subject', 'type' => FormFieldType::Select->value, 'required' => true, 'options' => ['General enquiry', 'Sales', 'Support', 'Bug report'], 'position' => 3, 'is_active' => true],
            ['name' => 'message', 'label' => 'Message', 'type' => FormFieldType::Textarea->value, 'required' => true, 'help_text' => 'Tell us a bit more.', 'position' => 4, 'is_active' => true],
        ]);

        $newsletter = Form::query()->create([
            'name' => 'Newsletter signup',
            'slug' => 'newsletter-signup',
            'endpoint' => '/api/forms/newsletter-signup',
            'description' => 'Newsletter subscription form on the marketing site.',
            'recipient_emails' => ['marketing@example.com'],
            'subject_template' => 'New newsletter signup',
            'submitter_reply_to_field' => 'email',
            'store_submissions' => true,
            'send_email' => true,
        ]);

        $newsletter->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => FormFieldType::Email->value, 'required' => true, 'position' => 0, 'is_active' => true],
            ['name' => 'first_name', 'label' => 'First name', 'type' => FormFieldType::Text->value, 'required' => false, 'position' => 1, 'is_active' => true],
        ]);

        Form::factory()->archived()->create([
            'name' => 'Old careers form',
            'recipient_emails' => ['careers@example.com'],
        ]);

        // A "minimal" form with no fields - relies on auto-discovery on first submission.
        Form::query()->create([
            'name' => 'Quick feedback',
            'slug' => 'quick-feedback',
            'endpoint' => '/api/forms/quick-feedback',
            'description' => 'A minimal form. Fields are auto-discovered on the first submission.',
            'recipient_emails' => ['feedback@example.com'],
            'subject_template' => 'New feedback submission',
            'store_submissions' => true,
            'send_email' => true,
            'auto_discover_fields' => true,
        ]);

        // A "locked" form with no fields and auto-discovery explicitly disabled.
        Form::query()->create([
            'name' => 'Strict intake form',
            'slug' => 'strict-intake',
            'endpoint' => '/api/forms/strict-intake',
            'description' => 'Auto-discovery is disabled - the form must be configured before it can accept submissions.',
            'recipient_emails' => ['strict@example.com'],
            'subject_template' => 'New intake submission',
            'store_submissions' => true,
            'send_email' => true,
            'auto_discover_fields' => false,
        ]);

        // Seed 5 sample submissions for the contact form.
        for ($i = 0; $i < 5; $i++) {
            $submission = FormSubmission::factory()->for($contact)->create([
                'created_at' => now()->subDays(random_int(0, 14)),
                'submission_data' => [
                    'full_name' => fake()->name(),
                    'email' => fake()->safeEmail(),
                    'phone' => fake()->e164PhoneNumber(),
                    'subject' => fake()->randomElement(['General enquiry', 'Sales', 'Support', 'Bug report']),
                    'message' => fake()->paragraph(),
                ],
            ]);

            foreach ($contact->recipient_emails as $recipient) {
                EmailJob::factory()->for($submission, 'submission')->create([
                    'recipient' => $recipient,
                    'status' => fake()->boolean(70) ? 'sent' : 'pending',
                ]);
            }
        }
    }
}
