<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_cases', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('title');
            $table->foreignId('category_id')->nullable()->constrained('report_categories');
            $table->foreignId('district_id')->nullable()->constrained('geographic_units');
            $table->string('status', 20)->default('OPEN');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('report_incident_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('incident_case_id')->constrained();
            $table->foreignId('linked_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'incident_case_id']);
        });

        Schema::create('report_information_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('question');
            $table->text('response')->nullable();
            $table->string('status', 20)->default('OPEN'); // OPEN, ANSWERED, CANCELLED
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('district_id')->nullable()->constrained('geographic_units');
            $table->string('trigger', 20);
            $table->text('reason');
            $table->string('escalation_state', 20)->default('OPEN');
            $table->foreignId('opened_by')->nullable()->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->string('decision', 40)->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['district_id', 'escalation_state']);
            $table->index(['report_id', 'trigger', 'escalation_state']);
        });

        Schema::create('case_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained();
            $table->foreignId('closed_by')->constrained('users');
            $table->text('outcome_summary');
            $table->text('response_steps')->nullable();
            $table->string('previous_status', 20);
            $table->timestamp('closed_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users');
            $table->text('reopen_reason')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['case_closures', 'escalations', 'report_information_requests', 'report_incident_links', 'incident_cases'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
