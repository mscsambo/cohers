<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users');
            $table->string('reason', 500);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('findings')->nullable();
            $table->timestamps();
        });
        DB::table('roles')->insertOrIgnore(['name' => 'health_center', 'label' => 'Health Center Focal Point', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('health_reviews');
        DB::table('roles')->where('name', 'health_center')->delete();
    }
};
