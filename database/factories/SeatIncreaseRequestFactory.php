<?php

namespace Database\Factories;

use App\Enums\SeatIncreaseRequestStatus;
use App\Models\SeatIncreaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatIncreaseRequest>
 */
class SeatIncreaseRequestFactory extends Factory
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
            'requested_by' => User::factory(),
            'requested_administrator_seats' => fake()->numberBetween(0, 3),
            'requested_collaborator_seats' => fake()->numberBetween(1, 10),
            'justification' => fake()->sentence(),
            'status' => SeatIncreaseRequestStatus::Pending,
            'pending_marker' => true,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeatIncreaseRequestStatus::Approved,
            'pending_marker' => null,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => fake()->sentence(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeatIncreaseRequestStatus::Rejected,
            'pending_marker' => null,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => fake()->sentence(),
        ]);
    }
}
