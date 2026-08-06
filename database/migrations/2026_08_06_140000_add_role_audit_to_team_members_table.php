<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('role_changed_by')
                ->nullable()
                ->after('status_changed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('role_changed_at')->nullable()->after('role_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_changed_by');
            $table->dropColumn('role_changed_at');
        });
    }
};
