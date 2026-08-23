<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('security_version')->default(1)->after('status');
            $table->text('mfa_secret')->nullable()->after('security_version');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $table->unsignedBigInteger('mfa_last_used_timestep')->nullable()->after('mfa_confirmed_at');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_last_used_timestep');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedInteger('security_version')->default(1)->after('abilities');
        });

        Schema::create('consent_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 80)->index();
            $table->string('document_version', 80);
            $table->string('source', 80);
            $table->text('legal_text');
            $table->char('legal_text_sha256', 64);
            $table->timestamp('consented_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'consented_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('security_version');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'security_version',
                'mfa_secret',
                'mfa_confirmed_at',
                'mfa_last_used_timestep',
                'mfa_recovery_codes',
            ]);
        });
    }
};
