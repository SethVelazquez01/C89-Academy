<?php

namespace Database\Seeders;

use App\Models\ExamAttemptQuestion;
use Illuminate\Database\Seeder;

class ExamAttemptQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ExamAttemptQuestion::factory()->count(3)->create();
    }
}
