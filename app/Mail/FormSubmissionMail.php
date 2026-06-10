<?php

namespace App\Mail;

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class FormSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Form $form,
        public FormSubmission $submission,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $from = new Address(
            $this->form->from_email ?: config('mail.from.address'),
            $this->form->from_name ?: config('mail.from.name'),
        );

        $replyTo = null;
        if ($this->form->submitter_reply_to_field) {
            $replyToEmail = $this->submission->data($this->form->submitter_reply_to_field);
            if (is_string($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $replyTo = new Address($replyToEmail);
            }
        }

        $envelope = new Envelope(
            from: $from,
            subject: $this->resolveSubject(),
        );

        if ($replyTo) {
            $envelope->replyTo($replyTo);
        }

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.form-submission',
            text: 'mail.form-submission-text',
            with: [
                'form' => $this->form,
                'submission' => $this->submission,
                'data' => $this->submission->submission_data,
                'submittedAt' => $this->submission->created_at,
            ],
        );
    }

    /**
     * Resolve the email subject using the form's subject template.
     */
    protected function resolveSubject(): string
    {
        $template = $this->form->subject_template ?: 'New submission for :form_name';

        return Str::of($template)->replace([
            ':form_name',
            ':form_slug',
        ], [
            $this->form->name,
            $this->form->slug,
        ])->value();
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
