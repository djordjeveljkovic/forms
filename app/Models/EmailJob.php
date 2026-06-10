<?php

namespace App\Models;

use App\Enums\EmailJobStatus;
use Database\Factories\EmailJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property string $status
 * @property string $recipient
 * @property string $subject
 * @property string|null $body
 * @property int $attempts
 * @property string|null $error_message
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'submission_id',
    'status',
    'recipient',
    'subject',
    'body',
    'attempts',
    'error_message',
    'queued_at',
    'started_at',
    'completed_at',
])]
class EmailJob extends Model
{
    /** @use HasFactory<EmailJobFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Get the submission that owns this email job.
     *
     * @return BelongsTo<FormSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    /**
     * Get the status enum.
     */
    public function statusEnum(): EmailJobStatus
    {
        return EmailJobStatus::from($this->status);
    }

    /**
     * Mark the job as processing.
     */
    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => EmailJobStatus::Processing->value,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /**
     * Mark the job as sent.
     */
    public function markSent(): void
    {
        $this->forceFill([
            'status' => EmailJobStatus::Sent->value,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    /**
     * Mark the job as failed.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => EmailJobStatus::Failed->value,
            'completed_at' => now(),
            'error_message' => $reason,
        ])->save();
    }

    /**
     * Reset the job to pending for retry.
     */
    public function resetForRetry(): void
    {
        $this->forceFill([
            'status' => EmailJobStatus::Pending->value,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ])->save();
    }
}
