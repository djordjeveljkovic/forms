<?php

namespace App\Models;

use App\Enums\FormStatus;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $endpoint
 * @property string $api_key
 * @property array<int, string> $recipient_emails
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string $subject_template
 * @property array<int, string>|null $allowed_origins
 * @property bool $store_submissions
 * @property bool $send_email
 * @property bool $success_notify_submitter
 * @property string|null $submitter_reply_to_field
 * @property string $success_message
 * @property bool $is_archived
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'endpoint',
    'api_key',
    'recipient_emails',
    'from_email',
    'from_name',
    'subject_template',
    'allowed_origins',
    'store_submissions',
    'send_email',
    'success_notify_submitter',
    'submitter_reply_to_field',
    'success_message',
    'is_archived',
    'archived_at',
])]
#[Hidden(['api_key'])]
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_emails' => 'array',
            'allowed_origins' => 'array',
            'store_submissions' => 'boolean',
            'send_email' => 'boolean',
            'success_notify_submitter' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Form $form): void {
            $form->api_key ??= self::generateApiKey();
            $form->slug ??= self::generateUniqueSlug($form->name ?? 'form');
            $form->endpoint ??= '/api/forms/'.$form->slug;
            $form->subject_template ??= 'New submission for :form_name';
            $form->success_message ??= 'Thank you for your submission.';
            $form->store_submissions ??= true;
            $form->send_email ??= true;
            $form->is_archived ??= false;
            $form->recipient_emails ??= [];
        });
    }

    /**
     * Generate a unique API key.
     */
    public static function generateApiKey(): string
    {
        do {
            $key = Str::random(48);
        } while (self::query()->where('api_key', $key)->exists());

        return $key;
    }

    /**
     * Generate a unique slug from a name.
     */
    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'form';
        $slug = $base;
        $i = 2;

        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Regenerate the API key for this form.
     */
    public function regenerateApiKey(): string
    {
        $this->api_key = self::generateApiKey();
        $this->save();

        return $this->api_key;
    }

    /**
     * Get the status enum for the form.
     */
    public function status(): FormStatus
    {
        if ($this->is_archived) {
            return FormStatus::Archived;
        }

        if (! $this->send_email && ! $this->store_submissions) {
            return FormStatus::Disabled;
        }

        return FormStatus::Active;
    }

    /**
     * Get the submissions relation.
     *
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Get the configured fields for this form (ordered by position).
     *
     * @return HasMany<FormField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('position');
    }

    /**
     * Get the active configured fields for this form.
     *
     * @return Collection<int, FormField>
     */
    public function activeFields(): Collection
    {
        return $this->fields()->where('is_active', true)->get();
    }

    /**
     * Determine whether the form has at least one active field.
     */
    public function hasActiveFields(): bool
    {
        return $this->activeFields()->isNotEmpty();
    }

    /**
     * Scope to active (non-archived) forms.
     *
     * @param  Builder<Form>  $query
     * @return Builder<Form>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope to archived forms.
     *
     * @param  Builder<Form>  $query
     * @return Builder<Form>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }
}
