<?php

namespace App\Console\Commands;

use App\Actions\Encounters\AuditLegacyPatientIntakes;
use Illuminate\Console\Command;

class AuditLegacyPatientIntakesCommand extends Command
{
    protected $signature = 'encounters:audit-legacy-intakes';

    protected $description = 'Audit legacy patient intakes for cleanup readiness';

    public function handle(AuditLegacyPatientIntakes $audit): int
    {
        $results = $audit->handle();

        $this->info('Legacy Patient Intake Audit:');
        $this->table(
            ['Metric', 'Value'],
            collect($results)->map(fn ($value, $key) => [$key, $value])->toArray()
        );

        if ($results['cleanup_ready']) {
            $this->info('✅ No active or future dependencies on legacy intakes. Cleanup is safe.');
        } else {
            $this->warn('⚠️  Active or future dependencies exist. Cleanup is NOT safe.');
        }

        return self::SUCCESS;
    }
}
