<?php

namespace Database\Factories;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class WaMessageFactory extends Factory
{
    protected $model = WaMessage::class;

    public function definition(): array
    {
        return [
            'wa_contact_id' => WaContact::factory(),
            'meta_id' => 'wamid.' . $this->faker->unique()->md5(),
            'direction' => WaMessage::IN,
            'type' => 'text',
            'body' => $this->faker->sentence(),
            'status' => WaMessage::INCOMING,
            'sent_at' => now(),
        ];
    }

    public function out(): static
    {
        return $this->state(fn () => [
            'direction' => WaMessage::OUT, 'status' => WaMessage::SENT,
        ]);
    }
}