<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;

/**
 * Authorization for `Form` operations.
 *
 * Forms are user-scoped SaaS resources: every form belongs to exactly
 * one user (the owner), and only the owner may view, edit, archive,
 * regenerate the API key, or delete it. The `view` capability gates
 * both the dashboard Livewire pages and the API endpoints that
 * accept a per-form api_key — note that those endpoints (e.g.
 * `POST /api/forms/{slug}`) authenticate the caller via api_key
 * and DO NOT call into this policy, so a holder of the api_key can
 * still submit even without being the owner. That's intentional: the
 * api_key IS the credential for that form.
 */
class FormPolicy
{
    /**
     * Determine whether the user can view the form on the dashboard
     * (edit page, demo page, forms list row, analytics, etc.).
     */
    public function view(User $user, Form $form): bool
    {
        return $this->isOwner($user, $form);
    }

    /**
     * Determine whether the user can update the form's configuration.
     */
    public function update(User $user, Form $form): bool
    {
        return $this->isOwner($user, $form);
    }

    /**
     * Determine whether the user can archive or restore the form.
     */
    public function archive(User $user, Form $form): bool
    {
        return $this->isOwner($user, $form);
    }

    /**
     * Determine whether the user can regenerate the form's api_key.
     */
    public function regenerateApiKey(User $user, Form $form): bool
    {
        return $this->isOwner($user, $form);
    }

    /**
     * Determine whether the user can delete the form outright.
     */
    public function delete(User $user, Form $form): bool
    {
        return $this->isOwner($user, $form);
    }

    /**
     * The owner check — single source of truth.
     *
     * Admins are allowed to do anything a regular user can do plus
     * operate on resources they don't own, so the check short-circuits
     * when the caller is an admin.
     */
    protected function isOwner(User $user, Form $form): bool
    {
        return $user->isAdmin() || (int) $form->user_id === (int) $user->getKey();
    }
}
