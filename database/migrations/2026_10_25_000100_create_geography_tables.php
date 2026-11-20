<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geographic_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('geographic_units');
            $table->string('type', 20); // province, district, commune, village, zone
            $table->string('code', 20)->unique();
            $table->string('name_km');
            $table->string('name_en');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'parent_id']);
        });

        Schema::create('report_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_km');
            $table->string('sector', 20); // HUMAN, ANIMAL, ENVIRONMENT, UNKNOWN
            $table->string('default_priority', 10)->default('MEDIUM');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_categories');
        Schema::dropIfExists('geographic_units');
    }
};
