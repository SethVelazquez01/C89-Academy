<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->string('status')->default('active')->after('role');
            $table->foreignId('created_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('status_changed_by')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');

            $table->index(['team_id', 'status', 'role'], 'team_members_team_status_role_index');
            $table->index(['user_id', 'status'], 'team_members_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex('team_members_team_status_role_index');
            $table->dropIndex('team_members_user_status_index');
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['status', 'status_changed_at']);
        });
    }
};
