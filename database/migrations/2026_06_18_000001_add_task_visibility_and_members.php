<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds per-task visibility:
     *  - is_public: a public task is usable by anyone with project access.
     *  - task_members: members granted access to a private task.
     * Existing tasks default to public so behaviour is unchanged until edited.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('is_public')->default(true)->after('name');
        });

        Schema::create('task_members', function (Blueprint $table): void {
            $table->foreignUuid('task_id')
                ->constrained('tasks')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('member_id')
                ->constrained('members')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['task_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_members');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('is_public');
        });
    }
};
