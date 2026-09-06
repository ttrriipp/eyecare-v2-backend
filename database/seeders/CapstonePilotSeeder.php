<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class CapstonePilotSeeder extends Seeder
{
    use WithoutModelEvents;

    private const AR_ASSET_ACTOR_EMAIL = 'pilot-ar-seeder@invalid.test';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->ensureArAssetBaseUrl();

        $this->call([
            RoleSeeder::class,
            AppointmentTypeSeeder::class,
            AppointmentStatusSeeder::class,
            NotificationStatusSeeder::class,
            InventoryMovementTypeSeeder::class,
            ClinicHoursSeeder::class,
            CatalogSeeder::class,
        ]);

        $this->call(ArAssetSeeder::class, parameters: [
            'actor' => $this->seedArAssetActor(),
        ]);
    }

    private function ensureArAssetBaseUrl(): void
    {
        $baseUrl = (string) config('ar.assets.base_url');

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || ! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('A public HTTPS AR asset base URL is required for the pilot-safe seeder.');
        }
    }

    private function seedArAssetActor(): User
    {
        $staffRole = Role::query()->where('name', Role::Staff)->firstOrFail();

        $actor = User::query()->updateOrCreate(
            ['email' => self::AR_ASSET_ACTOR_EMAIL],
            [
                'first_name' => null,
                'middle_name' => null,
                'last_name' => null,
                'phone' => null,
                'address' => null,
                'date_of_birth' => null,
                'password' => null,
                'role_id' => $staffRole->id,
                'is_optometrist' => false,
                'is_active' => true,
                'must_change_password' => false,
                'password_changed_at' => null,
                'privacy_notice_version' => null,
                'privacy_acknowledged_at' => null,
                'email_verified_at' => null,
            ],
        );

        $actor->roles()->sync([$staffRole->id]);

        return $actor;
    }
}
