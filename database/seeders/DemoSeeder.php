<?php

namespace Database\Seeders;

use App\Enums\EmailJobStatus;
use App\Enums\FormFieldType;
use App\Enums\SubmissionStatus;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seed a realistic mix of forms, accounts, submissions, and email jobs
 * for local development, demos, and QA. Safe to re-run: it truncates the
 * affected tables first.
 *
 * Run with:
 *   php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('email_jobs')->delete();
            DB::table('form_submissions')->delete();
            DB::table('form_fields')->delete();
            DB::table('forms')->delete();
            DB::table('audit_logs')->delete();
            DB::table('users')->delete();

            $this->seedAccounts();
            $contact = $this->seedContactForm();
            $this->seedContactSubmissions($contact);
            $this->seedNewsletterForm();
            $this->seedBugReportForm();
            $this->seedSurveyForm();
            $this->seedAutoDiscoverForm();
            $this->seedStrictForm();
            $this->seedHeartbeatForm();
            $this->seedDisabledForm();
            $this->seedArchivedForm();

            $this->command->info(sprintf(
                'Seeded %d users, %d forms, %d submissions, %d email jobs.',
                User::query()->count(),
                Form::query()->count(),
                FormSubmission::query()->count(),
                EmailJob::query()->count(),
            ));
        });
    }

    /**
     * Create the demo accounts.
     *
     * @return array<string, string>
     */
    protected function seedAccounts(): array
    {
        $accounts = [
            'admin@example.com' => ['Site Administrator', 'Full access. Use this to manage forms, submissions, and email jobs.'],
            'marketing@example.com' => ['Marketing Team',     'Receives the newsletter signups. Same dashboard access as admin.'],
            'support@example.com' => ['Support Team',       'Receives the contact form submissions.'],
        ];

        $passwords = [];
        foreach ($accounts as $email => [$name, $purpose]) {
            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $passwords[$email] = $purpose;
            $this->command->info("  Created user: {$email} (password: password)");
        }

        return $passwords;
    }

    /**
     * Build the public contact form.
     */
    protected function seedContactForm(): Form
    {
        $form = Form::query()->create([
            'name' => 'Contact',
            'slug' => 'contact',
            'endpoint' => '/api/forms/contact',
            'description' => 'Public contact form for general enquiries. Stores submissions and emails the support + marketing teams.',
            'recipient_emails' => ['support@example.com', 'marketing@example.com'],
            'subject_template' => 'New :form_name submission from :form_slug',
            'submitter_reply_to_field' => 'email',
            'allowed_origins' => ['http://localhost:8000', 'https://example.com'],
            'store_submissions' => true,
            'send_email' => true,
            'success_message' => 'Thanks! We will be in touch within one business day.',
            'auto_discover_fields' => true,
        ]);

        $form->fields()->createMany([
            ['name' => 'full_name', 'label' => 'Full name', 'type' => FormFieldType::Text->value,     'required' => true,  'position' => 0, 'placeholder' => 'Jane Doe'],
            ['name' => 'email',     'label' => 'Email',     'type' => FormFieldType::Email->value,    'required' => true,  'position' => 1, 'placeholder' => 'jane@example.com'],
            ['name' => 'phone',     'label' => 'Phone',     'type' => FormFieldType::Tel->value,      'required' => false, 'position' => 2, 'placeholder' => '+1 555 1234'],
            ['name' => 'subject',   'label' => 'Subject',   'type' => FormFieldType::Select->value,   'required' => true,  'position' => 3, 'options' => ['General enquiry', 'Sales', 'Support', 'Bug report']],
            ['name' => 'message',   'label' => 'Message',   'type' => FormFieldType::Textarea->value, 'required' => true,  'position' => 4, 'help_text' => 'Tell us a bit more.'],
        ]);

        return $form;
    }

    /**
     * Seed sample submissions for the contact form, plus email jobs in a
     * realistic mix of sent / pending / failed states.
     */
    protected function seedContactSubmissions(Form $contact): void
    {
        $rows = [
            ['Jane Doe',         'jane.doe@example.com',  '+1 555 1234',       'General enquiry', 'Hello, I would like to know more about your forms.'],
            ['John Smith',       'john.smith@example.com', '+44 20 7946 0958',  'Sales',           'Can I get a quote for the team plan?'],
            ['Ana Pereira',      'ana@example.com',       '+55 11 91234 5678', 'Support',          'The demo embedded form is not submitting.'],
            ['Lukas Müller',     'lukas@example.com',     '+49 30 12345678',  'Bug report',      'There is a typo on the contact page.'],
            ['Priya Patel',      'priya@example.com',     '+91 98765 43210',  'General enquiry', 'Where can I read the privacy policy?'],
        ];

        foreach ($rows as $i => [$name, $email, $phone, $subject, $message]) {
            $submission = FormSubmission::query()->create([
                'form_id' => $contact->id,
                'submission_data' => [
                    'full_name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'subject' => $subject,
                    'message' => $message,
                ],
                'ip_address' => '203.0.113.'.(10 + $i),
                'user_agent' => 'Mozilla/5.0 (compatible; TestBot/'.$i.')',
                'referer' => 'https://example.com/contact',
                'status' => $i === 2 ? SubmissionStatus::Read->value : SubmissionStatus::Received->value,
                'read_at' => $i === 2 ? now()->subHours(2) : null,
                'created_at' => now()->subDays(random_int(0, 14))->subMinutes(random_int(0, 59)),
            ]);

            foreach ($contact->recipient_emails as $rIndex => $recipient) {
                $status = match (true) {
                    $i === 4 && $rIndex === 0 => EmailJobStatus::Failed,
                    $i % 2 === 0 => EmailJobStatus::Sent,
                    default => EmailJobStatus::Pending,
                };
                EmailJob::query()->create([
                    'submission_id' => $submission->id,
                    'status' => $status->value,
                    'recipient' => $recipient,
                    'subject' => 'New Contact submission from Contact',
                    'attempts' => $status === EmailJobStatus::Failed ? 3 : ($status === EmailJobStatus::Sent ? 1 : 0),
                    'error_message' => $status === EmailJobStatus::Failed ? 'SMTP connection refused.' : null,
                    'queued_at' => $submission->created_at,
                    'started_at' => $status === EmailJobStatus::Pending ? null : $submission->created_at->copy()->addSeconds(5),
                    'completed_at' => in_array($status, [EmailJobStatus::Failed, EmailJobStatus::Sent], true)
                        ? $submission->created_at->copy()->addSeconds(15) : null,
                ]);
            }
        }
    }

    protected function seedNewsletterForm(): void
    {
        Form::query()->create([
            'name' => 'Newsletter signup',
            'slug' => 'newsletter-signup',
            'endpoint' => '/api/forms/newsletter-signup',
            'description' => 'Email-only signup on the marketing site. Does not store submissions - the email is the record.',
            'recipient_emails' => ['marketing@example.com'],
            'subject_template' => 'New newsletter signup',
            'submitter_reply_to_field' => 'email',
            'store_submissions' => false,
            'send_email' => true,
            'success_message' => 'You are on the list. Check your inbox for a confirmation.',
            'auto_discover_fields' => true,
        ])->fields()->createMany([
            ['name' => 'email',      'label' => 'Email',      'type' => FormFieldType::Email->value, 'required' => true,  'position' => 0],
            ['name' => 'first_name', 'label' => 'First name', 'type' => FormFieldType::Text->value,  'required' => false, 'position' => 1],
        ]);
    }

    protected function seedBugReportForm(): void
    {
        Form::query()->create([
            'name' => 'Bug report',
            'slug' => 'bug-report',
            'endpoint' => '/api/forms/bug-report',
            'description' => 'Detailed bug report with environment fields and severity selector.',
            'recipient_emails' => ['support@example.com'],
            'subject_template' => 'Bug: :form_name from :form_slug',
            'submitter_reply_to_field' => 'email',
            'store_submissions' => true,
            'send_email' => true,
            'success_message' => 'Thanks for the report - we will triage it shortly.',
        ])->fields()->createMany([
            ['name' => 'title',       'label' => 'Title',                 'type' => FormFieldType::Text->value,     'required' => true,  'position' => 0, 'placeholder' => 'Short summary'],
            ['name' => 'reporter',    'label' => 'Your email',            'type' => FormFieldType::Email->value,    'required' => true,  'position' => 1, 'placeholder' => 'you@example.com'],
            ['name' => 'severity',    'label' => 'Severity',              'type' => FormFieldType::Radio->value,    'required' => true,  'position' => 2, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'help_text' => 'How broken is it for you?'],
            ['name' => 'components',  'label' => 'Affected components',   'type' => FormFieldType::Checkbox->value, 'required' => false, 'position' => 3, 'options' => ['Forms list', 'Submission form', 'Email delivery', 'Settings', 'API']],
            ['name' => 'url',         'label' => 'Where it happens',      'type' => FormFieldType::Url->value,      'required' => false, 'position' => 4, 'placeholder' => 'https://'],
            ['name' => 'description', 'label' => 'Steps to reproduce',    'type' => FormFieldType::Textarea->value, 'required' => true,  'position' => 5, 'help_text' => 'What did you do, and what happened?'],
        ]);
    }

    protected function seedSurveyForm(): void
    {
        Form::query()->create([
            'name' => 'Customer survey',
            'slug' => 'customer-survey',
            'endpoint' => '/api/forms/customer-survey',
            'description' => 'Quarterly customer satisfaction survey.',
            'recipient_emails' => ['marketing@example.com'],
            'subject_template' => 'New survey response: :form_name',
            'store_submissions' => true,
            'send_email' => true,
        ])->fields()->createMany([
            ['name' => 'name',           'label' => 'Name',                'type' => FormFieldType::Text->value,   'required' => true,  'position' => 0],
            ['name' => 'email',          'label' => 'Email',               'type' => FormFieldType::Email->value,  'required' => true,  'position' => 1],
            ['name' => 'nps',            'label' => 'NPS (0-10)',         'type' => FormFieldType::Number->value, 'required' => true,  'position' => 2, 'help_text' => '0 = would not recommend, 10 = would absolutely recommend'],
            ['name' => 'survey_date',    'label' => 'Date of experience',  'type' => FormFieldType::Date->value,   'required' => true,  'position' => 3],
            ['name' => 'contact_window', 'label' => 'Best time to call',   'type' => FormFieldType::Time->value,   'required' => false, 'position' => 4],
        ]);
    }

    protected function seedAutoDiscoverForm(): void
    {
        Form::query()->create([
            'name' => 'Quick feedback',
            'slug' => 'quick-feedback',
            'endpoint' => '/api/forms/quick-feedback',
            'description' => 'Minimal form. The first submission creates field definitions automatically from the keys you send.',
            'recipient_emails' => ['feedback@example.com'],
            'subject_template' => 'Quick feedback',
            'store_submissions' => true,
            'send_email' => true,
            'auto_discover_fields' => true,
        ]);
    }

    protected function seedStrictForm(): void
    {
        Form::query()->create([
            'name' => 'Strict intake form',
            'slug' => 'strict-intake',
            'endpoint' => '/api/forms/strict-intake',
            'description' => 'Locked down - no fields and no auto-discovery. Returns 422 until an operator configures it.',
            'recipient_emails' => ['strict@example.com'],
            'subject_template' => 'New intake',
            'store_submissions' => true,
            'send_email' => true,
            'auto_discover_fields' => false,
        ]);
    }

    protected function seedHeartbeatForm(): void
    {
        Form::query()->create([
            'name' => 'Server heartbeat',
            'slug' => 'server-heartbeat',
            'endpoint' => '/api/forms/server-heartbeat',
            'description' => 'Internal heartbeat endpoint. Does not store submissions, only emails the on-call team.',
            'recipient_emails' => ['admin@example.com'],
            'subject_template' => 'Heartbeat from :form_slug',
            'store_submissions' => false,
            'send_email' => true,
        ]);
    }

    protected function seedDisabledForm(): void
    {
        Form::query()->create([
            'name' => 'Disabled form (do not use)',
            'slug' => 'disabled-demo',
            'endpoint' => '/api/forms/disabled-demo',
            'description' => 'Intentionally disabled - both storage and email are off. Submissions return 410.',
            'recipient_emails' => ['nobody@example.com'],
            'subject_template' => 'Should never be sent',
            'store_submissions' => false,
            'send_email' => false,
        ]);
    }

    protected function seedArchivedForm(): void
    {
        Form::factory()->archived()->create([
            'name' => 'Old careers form (archived)',
            'slug' => 'old-careers',
            'endpoint' => '/api/forms/old-careers',
            'description' => 'Retired careers intake. Archived so submissions are rejected with 410.',
            'recipient_emails' => ['careers@example.com'],
            'subject_template' => 'Archived careers form',
        ]);
    }
}
