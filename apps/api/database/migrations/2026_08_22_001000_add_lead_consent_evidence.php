<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('consent_version', 80)->nullable()->after('message');
            $table->text('consent_text')->nullable()->after('consent_version');
            $table->char('consent_text_sha256', 64)->nullable()->after('consent_text');
            $table->timestamp('consented_at')->nullable()->after('consent_text_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['consent_version', 'consent_text', 'consent_text_sha256', 'consented_at']);
        });
    }
};
