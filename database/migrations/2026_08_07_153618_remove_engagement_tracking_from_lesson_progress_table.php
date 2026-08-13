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
        $columns = array_values(array_filter(
            ['engaged_seconds', 'last_engaged_at'],
            fn (string $column): bool => Schema::hasColumn('lesson_progress', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('lesson_progress', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->unsignedInteger('engaged_seconds')->default(0)->after('started_at');
            $table->timestamp('last_engaged_at')->nullable()->after('engaged_seconds');
        });
    }
};
