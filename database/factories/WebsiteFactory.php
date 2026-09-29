<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

final class WebsiteFactory extends Factory
{
    /** @var class-string<Website> */
    protected $model = Website::class;

    public function definition(): array
    {
        $host = 'example-'.fake()->unique()->numberBetween(1000, 9999).'.com';

        return [
            'name' => fake()->company(),
            'url' => "https://{$host}/",
            'scheme' => 'https',
            'host' => $host,
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
            'follow_redirects' => true,
            'monitor_ssl' => true,
            'monitor_redirects' => true,
            'monitor_content' => true,
            'monitor_security' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
