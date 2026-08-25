<?php

namespace Database\Seeders;

use App\Models\ExamAttemptEvent;
use Illuminate\Database\Seeder;

class ExamAttemptEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ExamAttemptEvent::factory()->count(3)->create();
    }
}
