<?php

namespace Database\Factories;

use App\Models\WaContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class WaContactFactory extends Factory
{
    protected $model = WaContact::class;

    public function definition(): array
    {
        return [
            'phone' => '1'.$this->faker->unique()->numerify('##########'),
            'profile_name' => $this->faker->name(),
        ];
    }

    public function optedOut(): static
    {
        return $this->state(fn () => [
            'opted_out_at' => now(), 'opt_out_source' => 'inbound_keyword',
        ]);
    }
}
