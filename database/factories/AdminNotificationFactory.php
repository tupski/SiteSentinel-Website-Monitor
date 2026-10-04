<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class AdminNotificationFactory extends Factory
{
    /** @var class-string<AdminNotification> */
    protected $model = AdminNotification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(10),
            'severity' => AdminNotification::SEVERITY_DANGER,
            'link_url' => '/admin/incidents',
            'read_at' => null,
            'dedupe_key' => 'incident.down:'.fake()->unique()->numberBetween(1, 999999),
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => null,
        ]);
    }
}
