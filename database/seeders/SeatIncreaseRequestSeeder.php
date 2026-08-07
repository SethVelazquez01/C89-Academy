<?php

namespace Database\Seeders;

use App\Models\SeatIncreaseRequest;
use Illuminate\Database\Seeder;

class SeatIncreaseRequestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SeatIncreaseRequest::factory()->create();
    }
}
