<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 20)->unique();
            $table->foreignId('reporter_id')->constrained('users');
            $table->foreignId('geographic_unit_id')->constrained('geographic_units'); // village or zone
            $table->foreignId('commune_id')->nullable()->constrained('geographic_units');
            $table->foreignId('district_id')->nullable()->constrained('geographic_units');
            $table->foreignId('category_id')->nullable()->constrained('report_categories');
            $table->string('subcategory')->nullable();
            $table->string('priority', 10)->nullable();
            $table->string('status', 20)->default('SUBMITTED');
            $table->string('escalation_state', 20)->default('NONE');
            $table->string('triage_status', 20)->default('PENDING'); // PENDING, DONE, FAILED, MANUAL
            $table->text('original_text');
            $table->string('affected_count', 60)->nullable();
            $table->string('observed_text', 120)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('language', 5)->default('km');
            $table->string('channel', 20)->default('telegram');
            $table->foreignId('possible_duplicate_of_id')->nullable()->constrained('reports');
            $table->decimal('duplicate_score', 4, 3)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamp('submitted_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index('submitted_at');
            $table->index(['district_id', 'status']);
            $table->index('category_id');
            $table->index('priority');
            $table->index('status');
        });

        Schema::create('report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->string('source', 20)->default('reporter'); // reporter, officer
            $table->string('kind', 20)->default('photo');
            $table->string('telegram_file_id')->nullable();
            $table->string('disk', 20)->default('local');
            $table->string('path')->nullable();
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('download_status', 20)->default('PENDING');
            $table->timestamps();
        });

        Schema::create('ai_triages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->string('provider', 30);
            $table->string('model', 80)->nullable();
            $table->text('input_text');
            $table->longText('raw_response')->nullable();
            $table->string('category_code', 40)->nullable();
            $table->string('subcategory')->nullable();
            $table->string('suggested_priority', 10)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('needs_manual_review')->default(true);
            $table->string('outcome', 20); // success, invalid, failed
            $table->text('error')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('focal_point_user_id')->constrained('users');
            $table->foreignId('assigned_by')->nullable()->constrained('users');
            $table->string('level', 10)->default('primary'); // primary, backup, manual
            $table->string('assignment_status', 20)->default('PENDING');
            $table->timestamp('assigned_at');
            $table->timestamp('ack_due_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('verification_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['focal_point_user_id', 'assignment_status']);
            $table->index(['assignment_status', 'ack_due_at']);
        });

        Schema::create('report_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('assignment_id')->nullable()->constrained('report_assignments');
            $table->foreignId('verifier_id')->constrained('users');
            $table->string('outcome', 20);
            $table->text('findings');
            $table->boolean('follow_up_needed')->default(false);
            $table->foreignId('duplicate_of_report_id')->nullable()->constrained('reports');
            $table->string('channel', 20)->default('telegram');
            $table->timestamp('verified_at');
            $table->timestamps();
        });

        Schema::create('report_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->string('channel', 20)->default('system');
            $table->timestamp('created_at');
            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['report_status_histories', 'report_verifications', 'report_assignments', 'ai_triages',
            'report_attachments', 'reports'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
