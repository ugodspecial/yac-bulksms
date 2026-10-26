<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('termii');
            // outbound | inbound | unknown
            $table->string('event_type', 20)->default('unknown')->index();
            $table->string('provider_message_id', 64)->nullable()->index();
            $table->string('phone', 20)->nullable();
            $table->string('status', 60)->nullable();
            // Stable hash used to ignore duplicate deliveries of the same event.
            $table->string('dedupe_key', 64)->nullable()->unique();
            $table->json('payload_summary')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false)->index();
            $table->string('processing_note', 255)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
    }
};
