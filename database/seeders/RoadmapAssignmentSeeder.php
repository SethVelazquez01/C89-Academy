<?php

namespace Database\Seeders;

use App\Models\RoadmapAssignment;
use Illuminate\Database\Seeder;

class RoadmapAssignmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RoadmapAssignment::factory()->create();
    }
}
