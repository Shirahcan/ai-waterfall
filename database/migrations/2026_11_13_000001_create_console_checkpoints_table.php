<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The kit's default console rewind points (ai-client 1.71.0, shared console C3). PUBLISHED, never auto-loaded:
 * `php artisan vendor:publish --tag=ai-client-console`. For a single-product host only (no product column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_checkpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_kind', 64);
            $table->string('subject_id', 64);
            $table->string('session_id', 64)->nullable();
            $table->longText('design');
            $table->string('created_by_ref', 64)->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_kind', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_checkpoints');
    }
};
