<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppression_entries', function (Blueprint $table) {
            $table->id();
            // E.164 digits without a leading "+".
            $table->string('phone', 20)->unique();
            $table->string('reason', 191)->nullable();
            $table->string('source', 30)->default('manual')->index();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_entries');
    }
};
