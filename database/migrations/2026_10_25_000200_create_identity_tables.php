<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('user_geographic_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geographic_unit_id')->constrained();
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'geographic_unit_id']);
        });

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->bigInteger('private_chat_id')->nullable();
            $table->string('username')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->string('status', 20)->default('active'); // active, blocked, unlinked
            $table->timestamps();
        });

        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('focal_point_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('geographic_unit_id')->constrained();
            $table->string('sector', 20);
            $table->string('level', 10)->default('primary'); // primary, backup
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['geographic_unit_id', 'sector', 'level', 'is_active']);
        });

        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('rule_type', 30); // keyword_priority, category_priority
            $table->string('pattern')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('report_categories');
            $table->string('priority', 10);
            $table->boolean('escalate_district')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['routing_rules', 'focal_point_assignments', 'telegram_link_tokens', 'telegram_accounts',
            'user_geographic_scopes', 'user_roles', 'role_permissions', 'permissions', 'roles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
