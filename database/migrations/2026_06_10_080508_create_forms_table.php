<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->string('endpoint')->unique();
            $table->string('api_key', 64)->unique();
            $table->json('recipient_emails');
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject_template')->default('New submission for :form_name');
            $table->json('allowed_origins')->nullable();
            $table->boolean('store_submissions')->default(true);
            $table->boolean('send_email')->default(true);
            $table->boolean('success_notify_submitter')->default(false);
            $table->string('submitter_reply_to_field')->nullable();
            $table->string('success_message')->default('Thank you for your submission.');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('is_archived');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
