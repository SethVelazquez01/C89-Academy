<?php

namespace Database\Factories;

use App\Enums\RoadmapAssignmentStatus;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapAssignment>
 */
class RoadmapAssignmentFactory extends Factory
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
            'user_id' => User::factory(),
            'assigned_by' => null,
            'cancelled_by' => null,
            'status' => RoadmapAssignmentStatus::Active,
            'assigned_at' => now(),
            'due_at' => null,
            'cancelled_at' => null,
        ];
    }
}
