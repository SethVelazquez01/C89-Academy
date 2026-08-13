<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->foreignId('last_cancelled_by')
                ->nullable()
                ->after('due_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('last_cancelled_at')->nullable()->after('last_cancelled_by');

            $table->index('last_cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropIndex(['last_cancelled_at']);
            $table->dropConstrainedForeignId('last_cancelled_by');
            $table->dropColumn('last_cancelled_at');
        });
    }
};
