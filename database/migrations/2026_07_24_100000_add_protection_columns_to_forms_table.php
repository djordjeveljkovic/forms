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
        Schema::table('forms', function (Blueprint $table): void {
            $table->string('success_redirect_url')
                ->nullable()
                ->after('success_message');
            $table->unsignedSmallInteger('min_submission_seconds')
                ->default(3)
                ->after('success_redirect_url');
            $table->string('honeypot_field', 64)
                ->default('website')
                ->after('min_submission_seconds');
            $table->string('captcha_provider', 32)
                ->default('none')
                ->after('honeypot_field');
            $table->string('captcha_site_key')
                ->nullable()
                ->after('captcha_provider');
            $table->text('captcha_secret_key')
                ->nullable()
                ->after('captcha_site_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn([
                'success_redirect_url',
                'min_submission_seconds',
                'honeypot_field',
                'captcha_site_key',
                'captcha_secret_key',
                'captcha_provider',
                'honeypot_field',
            ]);
        });
    }
};
