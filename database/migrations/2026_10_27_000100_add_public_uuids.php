<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a public UUIDv7 to every record that appears in dashboard links, and fills it
 * for existing rows. Integer ids remain the primary keys.
 */
return new class extends Migration
{
    private const TABLES = [
        'users', 'reports', 'report_attachments', 'escalations', 'geographic_units',
        'focal_point_assignments', 'routing_rules', 'report_categories', 'notification_logs', 'incident_cases',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'uuid')) {
                Schema::table($table, fn (Blueprint $t) => $t->uuid('uuid')->nullable()->after('id'));
            }

            // Backfill in id order so UUIDv7 time order follows creation order.
            DB::table($table)->whereNull('uuid')->orderBy('id')->select('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()]);
                }
            });

            Schema::table($table, fn (Blueprint $t) => $t->unique('uuid'));
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_uuid_unique');
                $t->dropColumn('uuid');
            });
        }
    }
};
