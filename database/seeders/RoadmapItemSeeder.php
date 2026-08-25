<?php

namespace Database\Seeders;

use App\Models\RoadmapItem;
use Illuminate\Database\Seeder;

class RoadmapItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RoadmapItem::factory()->create();
    }
}
