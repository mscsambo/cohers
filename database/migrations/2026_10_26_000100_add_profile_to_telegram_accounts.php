<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('username');
            $table->string('language_code', 10)->nullable()->after('first_name');
            $table->timestamp('last_seen_at')->nullable()->after('linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'language_code', 'last_seen_at']);
        });
    }
};
