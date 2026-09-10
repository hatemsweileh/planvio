<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 32);
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();

            // Forward reference: `projects` is created in band 000200. The column is declared
            // here and the foreign key is attached by 000901_add_deferred_foreign_keys.
            $table->unsignedBigInteger('project_id')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index(['workspace_id', 'email']);
            $table->index('invited_by');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
