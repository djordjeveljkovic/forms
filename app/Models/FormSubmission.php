<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Database\Factories\FormSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $form_id
 * @property array<string, mixed> $submission_data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $referer
 * @property string $status
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'form_id',
    'submission_data',
    'ip_address',
    'user_agent',
    'referer',
    'status',
    'read_at',
])]
class FormSubmission extends Model
{
    /** @use HasFactory<FormSubmissionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submission_data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Get the form that owns this submission.
     *
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Get the email jobs for this submission.
     *
     * @return HasMany<EmailJob, $this>
     */
    public function emailJobs(): HasMany
    {
        return $this->hasMany(EmailJob::class, 'submission_id');
    }

    /**
     * Get the status enum.
     */
    public function statusEnum(): SubmissionStatus
    {
        return SubmissionStatus::from($this->status);
    }

    /**
     * Get a value from the submission data by key.
     */
    public function data(string $key, mixed $default = null): mixed
    {
        return data_get($this->submission_data, $key, $default);
    }
}
