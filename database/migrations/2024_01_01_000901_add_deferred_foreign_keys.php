<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns in the contract reference tables that are created in a later band, so the
 * constraint cannot be declared inline. They are attached here, once every referenced table
 * exists. Both drivers support adding and dropping these by column, so the migration is
 * reversible on MySQL/MariaDB and on SQLite (which rebuilds the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            // cascadeOnDelete, not nullOnDelete: nulling project_id would silently promote a
            // project-scoped guest invitation into a full workspace invitation.
            $table->foreign('project_id')
                ->references('id')->on('projects')
                ->cascadeOnDelete();
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->foreign('ai_run_id')
                ->references('id')->on('ai_runs')
                ->nullOnDelete();
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->foreign('ai_run_id')
                ->references('id')->on('ai_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropForeign(['ai_run_id']);
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropForeign(['ai_run_id']);
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
        });
    }
};
