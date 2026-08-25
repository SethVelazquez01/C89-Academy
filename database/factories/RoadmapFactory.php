<?php

namespace Database\Factories;

use App\Enums\RoadmapStatus;
use App\Models\Roadmap;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Roadmap>
 */
class RoadmapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => null,
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(12),
            'status' => RoadmapStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapStatus::Published,
            'published_at' => now(),
        ]);
    }
}
