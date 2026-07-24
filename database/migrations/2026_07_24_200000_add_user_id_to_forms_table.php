<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Add nullable user_id column.
     * 2. Back-fill existing forms with the first user (legacy data).
     * 3. Drop the global unique index on `slug` and replace with a
     *    per-user composite unique index on (user_id, slug).
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Back-fill legacy forms (created before ownership existed) with
        // the first user so the per-user unique index can be enforced.
        $firstUserId = DB::table('users')->orderBy('id')->value('id');
        if ($firstUserId !== null) {
            DB::table('forms')
                ->whereNull('user_id')
                ->update(['user_id' => $firstUserId]);
        }

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropUnique('forms_slug_unique');
            $table->unique(['user_id', 'slug'], 'forms_user_id_slug_unique');
            $table->index('user_id', 'forms_user_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropUnique('forms_user_id_slug_unique');
            $table->dropIndex('forms_user_id_index');
        });

        // Re-create the global unique index. Slugs that are no longer
        // globally unique after this migration will be lost on rollback,
        // so this is best-effort.
        Schema::table('forms', function (Blueprint $table): void {
            $table->unique('slug', 'forms_slug_unique');
        });

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
