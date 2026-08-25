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
        Schema::create('exam_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->longText('prompt');
            $table->json('options')->nullable();
            $table->text('correct_answer')->nullable();
            $table->unsignedSmallInteger('points');
            $table->unsignedInteger('position');
            $table->longText('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedSmallInteger('awarded_points')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'position']);
            $table->index(['exam_attempt_id', 'reviewed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_questions');
    }
};
