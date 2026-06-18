<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Build the index without holding a write lock on the table, so it can be
     * applied to large production tables without downtime. CREATE INDEX
     * CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     *
     * Every reporting/aggregation query filters time entries by
     * `organization_id` together with a `start` date range. Without a matching
     * composite index PostgreSQL has to fall back to a sequential scan (or scan
     * the single-column `start` index across every organization), which makes
     * the reporting endpoints slow as the table grows.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS time_entries_organization_id_start_index ON time_entries (organization_id, start)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS time_entries_organization_id_start_index');
    }
};
