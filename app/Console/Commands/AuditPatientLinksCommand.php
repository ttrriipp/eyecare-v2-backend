<?php

namespace App\Console\Commands;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\PatientAccounts\PatientAccountIdentityMatcher;
use App\Enums\AuditEvent;
use App\Models\Patient;
use Illuminate\Console\Command;

class AuditPatientLinksCommand extends Command
{
    protected $signature = 'patient-links:audit-identity {--mark-review : Mark incompatible links for review}';

    protected $description = 'Audit existing patient-account links for identity compatibility';

    public function handle(PatientAccountIdentityMatcher $matcher, CreateAuditLog $auditLog): int
    {
        $markReview = $this->option('mark-review');

        $linkedPatients = Patient::query()
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        $this->info("Auditing {$linkedPatients->count()} linked patient-account pairs...");

        $counts = [
            'eligible' => 0,
            'mismatched' => 0,
            'missing_fields' => 0,
            'marked' => 0,
            'skipped_already_marked' => 0,
        ];

        foreach ($linkedPatients as $patient) {
            $account = $patient->user;

            if ($account === null) {
                $counts['mismatched']++;

                continue;
            }

            $match = $matcher->handle($account, $patient);

            if ($match->isEligible()) {
                $counts['eligible']++;

                continue;
            }

            if (! empty($match->mismatchedFields)) {
                $counts['mismatched']++;
            } else {
                $counts['missing_fields']++;
            }

            if ($markReview) {
                if ($patient->identity_review_required) {
                    $counts['skipped_already_marked']++;

                    continue;
                }

                $patient->markForIdentityReview();
                $auditLog->handle(
                    subject: $patient,
                    action: AuditEvent::PatientIdentityReviewRequired,
                    metadata: [
                        'reason' => 'reconciliation_audit',
                        'mismatched_fields' => $match->mismatchedFields,
                        'missing_fields' => $match->missingFields,
                    ],
                    actorId: null,
                );
                $counts['marked']++;
            }
        }

        $this->newLine();
        $this->info('Results:');
        $this->table(['Category', 'Count'], [
            ['Eligible', $counts['eligible']],
            ['Mismatched', $counts['mismatched']],
            ['Missing fields', $counts['missing_fields']],
            ['Already marked', $counts['skipped_already_marked']],
            ['Newly marked', $counts['marked']],
        ]);

        if (! $markReview) {
            $this->newLine();
            $this->info('Dry run complete. Use --mark-review to mark incompatible links.');
        } else {
            $this->newLine();
            $this->info("Marked {$counts['marked']} links for review.");
        }

        return self::SUCCESS;
    }
}
