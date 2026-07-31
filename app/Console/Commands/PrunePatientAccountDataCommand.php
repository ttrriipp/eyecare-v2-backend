<?php

namespace App\Console\Commands;

use App\Actions\PatientAccounts\PrunePatientAccountData;
use Illuminate\Console\Command;

class PrunePatientAccountDataCommand extends Command
{
    protected $signature = 'patient-accounts:prune';

    protected $description = 'Prune expired OTPs, tokens, invitations, and terminal request history';

    public function handle(PrunePatientAccountData $prune): int
    {
        $results = $prune->handle();

        $this->info('Pruning complete:');
        $this->table(
            ['Category', 'Pruned'],
            collect($results)->map(fn ($count, $key) => [$key, $count])->toArray()
        );

        return self::SUCCESS;
    }
}
