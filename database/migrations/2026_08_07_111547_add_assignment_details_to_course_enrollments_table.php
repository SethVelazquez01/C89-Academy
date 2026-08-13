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
            $table->boolean('is_required')->default(false)->after('assigned_by');
            $table->timestamp('due_at')->nullable()->after('is_required');

            $table->index(['course_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'due_at']);
            $table->dropColumn(['is_required', 'due_at']);
        });
    }
};
