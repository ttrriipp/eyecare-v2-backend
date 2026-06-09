<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsNotification>
 */
class SmsNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'notification_status_id' => NotificationStatus::query()->firstOrCreate([
                'name' => 'queued',
            ])->id,
            'event' => 'appointment_confirmed',
            'recipient' => fake()->safeEmail(),
            'message' => fake()->sentence(),
        ];
    }
}
