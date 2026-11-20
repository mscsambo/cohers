<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_closures', function (Blueprint $table) {
            $table->text('reporter_message')->nullable()->after('response_steps');
        });
    }

    public function down(): void
    {
        Schema::table('case_closures', function (Blueprint $table) {
            $table->dropColumn('reporter_message');
        });
    }
};
