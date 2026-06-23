<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The form's `description` and `success_message` columns were originally
     * declared as 255-char strings, but the validation rules in
     * FormCreate/FormEdit allow up to 2000 characters. SQLite silently
     * truncates and MySQL throws a "Data too long for column" error when
     * a long value is saved. Widen the columns to `text` so both drivers
     * accept the same input.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->text('description')->nullable()->change();
            $table->text('success_message')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->string('description')->nullable()->change();
            $table->string('success_message')->change();
        });
    }
};
