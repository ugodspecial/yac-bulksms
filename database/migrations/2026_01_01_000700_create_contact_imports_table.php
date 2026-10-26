<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename', 191);
            $table->string('stored_path', 255);
            $table->string('disk', 30)->default('local');
            $table->string('file_type', 10)->default('csv');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('max_rows')->nullable();
            $table->string('error_report_path', 255)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('name', 120)->nullable();
            $table->string('phone_raw', 60)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->json('custom_fields')->nullable();
            // valid | invalid | duplicate
            $table->string('status', 20)->default('valid')->index();
            $table->string('error', 191)->nullable();
            $table->timestamps();

            $table->unique(['contact_import_id', 'row_number']);
            $table->index(['contact_import_id', 'status']);
            $table->index('phone_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_rows');
        Schema::dropIfExists('contact_imports');
    }
};
