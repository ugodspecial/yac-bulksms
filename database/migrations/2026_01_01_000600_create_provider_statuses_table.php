<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->string('status', 20)->default('unknown');
            $table->decimal('balance', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('sender_id', 30)->nullable();
            $table->string('sender_status', 20)->nullable();
            $table->text('sender_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_balance_at')->nullable();
            // Sanitised, human readable summary. Never contains secrets.
            $table->string('last_error', 255)->nullable();
            $table->string('last_error_type', 40)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_statuses');
    }
};
