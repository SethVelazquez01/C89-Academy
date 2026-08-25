<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Roadmap;
use App\Models\RoadmapItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapItem>
 */
class RoadmapItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roadmap_id' => Roadmap::factory(),
            'course_id' => Course::factory(),
            'exam_id' => null,
            'position' => 1,
        ];
    }
}
