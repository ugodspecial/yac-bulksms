<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20)->default('termii');
            // send | send_bulk | balance | sender_ids | history | webhook
            $table->string('operation', 30)->index();
            $table->string('endpoint', 120)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Redacted request summary - never contains the API key.
            $table->json('request_meta')->nullable();
            // Trimmed response summary.
            $table->json('response_meta')->nullable();
            $table->string('provider_message_id', 64)->nullable();
            $table->string('error_type', 40)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['campaign_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
