<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20);
            $table->string('name', 120)->nullable();
            $table->text('rendered_message');
            $table->unsignedInteger('segments')->default(1);
            // pending | processing | accepted | delivered | failed | unknown | skipped
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider', 20)->default('termii');
            $table->string('provider_message_id', 64)->nullable()->index();
            $table->string('provider_status', 60)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 191)->nullable();
            $table->boolean('ambiguous')->default(false)->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('claim_token', 40)->nullable()->index();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
