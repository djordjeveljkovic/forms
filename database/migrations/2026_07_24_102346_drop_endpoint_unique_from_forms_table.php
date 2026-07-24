<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the global unique index on `forms.endpoint`.
     *
     * Forms are now user-scoped (see 2026_07_24_200000_add_user_id_to_forms_table.php)
     * so two different users are entitled to own a form with the same
     * human-readable slug and therefore the same `endpoint` string
     * (`/api/forms/{slug}` or `/api/submit/{slug}`). The form is
     * addressed by `(user_id, slug)` in the agent API, and by its
     * per-form `api_key` in the legacy API, so the column does not
     * need to be globally unique anymore.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropUnique('forms_endpoint_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Best-effort: if duplicate endpoint values already exist in the
     * table, the unique index cannot be re-added until they are
     * resolved.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->unique('endpoint', 'forms_endpoint_unique');
        });
    }
};
