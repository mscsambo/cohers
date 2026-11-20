<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->bigInteger('chat_id');
            $table->string('state', 60)->default('idle');
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->json('payload');
            $table->string('status', 20)->default('QUEUED'); // QUEUED, PROCESSED, FAILED
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained();
            $table->foreignId('recipient_id')->nullable()->constrained('users');
            $table->bigInteger('chat_id')->nullable();
            $table->string('channel', 20)->default('telegram');
            $table->string('message_type', 40);
            $table->string('idempotency_key', 120)->unique();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('delivery_status', 20)->default('PENDING');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['delivery_status', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('channel', 20)->default('web');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('group', 40)->default('general');
            $table->string('type', 20)->default('string'); // string, int, bool, text
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('incentive_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('report_id')->nullable()->constrained();
            $table->integer('points');
            $table->string('reason', 60);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'report_id', 'reason']);
        });
    }

    public function down(): void
    {
        foreach (['incentive_transactions', 'system_settings', 'audit_logs', 'notification_logs', 'telegram_updates', 'telegram_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
