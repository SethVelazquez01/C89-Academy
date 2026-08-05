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
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('resource_disk')->nullable()->after('content');
            $table->string('resource_name')->nullable()->after('resource_path');
            $table->string('resource_mime')->nullable()->after('resource_name');
            $table->unsignedBigInteger('resource_size')->nullable()->after('resource_mime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn([
                'resource_disk',
                'resource_name',
                'resource_mime',
                'resource_size',
            ]);
        });
    }
};
