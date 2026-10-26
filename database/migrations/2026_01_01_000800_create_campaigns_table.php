<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('message');
            $table->string('sender_id', 30);
            $table->string('channel', 20)->default('generic');
            // bulk | individual
            $table->string('dispatch_mode', 20)->default('bulk');
            $table->string('status', 20)->default('draft')->index();
            $table->string('encoding', 16)->nullable();
            $table->unsignedInteger('max_segments')->default(1);
            $table->unsignedInteger('estimated_total_segments')->default(0);
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->json('exclusion_summary')->nullable();
            $table->json('audience')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('batch_size')->default(100);
            $table->decimal('balance_before', 14, 2)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
