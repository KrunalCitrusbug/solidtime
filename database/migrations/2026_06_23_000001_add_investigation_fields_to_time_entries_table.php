<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->timestamp('investigation_flagged_at')->nullable()->after('still_active_email_sent_at');
            $table->timestamp('long_entry_admin_notified_at')->nullable()->after('investigation_flagged_at');
            $table->text('investigation_reason')->nullable()->after('long_entry_admin_notified_at');
            $table->timestamp('investigation_reviewed_at')->nullable()->after('investigation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'investigation_flagged_at',
                'long_entry_admin_notified_at',
                'investigation_reason',
                'investigation_reviewed_at',
            ]);
        });
    }
};
