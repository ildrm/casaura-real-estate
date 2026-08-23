<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 32)->index();
            $table->string('operator_reference', 160)->nullable();
            $table->string('output_storage_key', 500)->nullable();
            $table->char('output_checksum_sha256', 64)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['subject_user_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};
