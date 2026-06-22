<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved reports become owned by their creator so the saved-report list only
     * shows a user their own reports (not every manager's/admin's reports).
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignUuid('user_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
