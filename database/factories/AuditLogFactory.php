<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->staff(),
            'subject_type' => 'App\\Models\\Appointment',
            'subject_id' => 1,
            'action' => 'appointment.status_changed',
            'metadata' => null,
        ];
    }
}
