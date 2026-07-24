<?php

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;

/**
 * Authorization for `FormSubmission` operations.
 *
 * Submissions are scoped via the form's owner — only the owner of
 * the parent form may view, list, or export submissions. There is no
 * other consumer of submissions in this app.
 */
class FormSubmissionPolicy
{
    public function view(User $user, FormSubmission $submission): bool
    {
        return $this->isOwner($user, $submission);
    }

    public function viewAny(User $user): bool
    {
        // Anyone signed in may visit the submissions list — the
        // index query scopes by their forms.
        return true;
    }

    protected function isOwner(User $user, FormSubmission $submission): bool
    {
        $form = $submission->form;

        // If the form was deleted or relation isn't loaded, fail closed.
        if ($form === null) {
            return false;
        }

        return (int) $form->user_id === (int) $user->getKey();
    }
}
