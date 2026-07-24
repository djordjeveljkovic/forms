<?php

namespace App\Policies;

use App\Models\EmailJob;
use App\Models\User;

/**
 * Authorization for `EmailJob` operations.
 *
 * Email jobs are scoped via the submission's form's owner.
 */
class EmailJobPolicy
{
    public function view(User $user, EmailJob $job): bool
    {
        return $this->isOwner($user, $job);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    protected function isOwner(User $user, EmailJob $job): bool
    {
        $submission = $job->submission;
        if ($submission === null) {
            return false;
        }

        $form = $submission->form;
        if ($form === null) {
            return false;
        }

        return (int) $form->user_id === (int) $user->getKey();
    }
}
