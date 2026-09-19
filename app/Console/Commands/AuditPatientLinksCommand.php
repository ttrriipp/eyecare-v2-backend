<?php

namespace App\Console\Commands;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\PatientAccounts\PatientAccountIdentityMatch;
use App\Actions\PatientAccounts\PatientAccountIdentityMatcher;
use App\Enums\AuditEvent;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPatientLinksCommand extends Command
{
    protected $signature = 'patient-links:audit-identity {--mark-review : Mark incompatible links for review}';

    protected $description = 'Audit existing patient-account links for identity compatibility';

    public function handle(PatientAccountIdentityMatcher $matcher, CreateAuditLog $auditLog): int
    {
        $markReview = $this->option('mark-review');

        $linkedPatientCount = Patient::query()
            ->whereNotNull('user_id')
            ->count();
        $linkedPatients = Patient::query()
            ->whereNotNull('user_id')
            ->lazyById(100);

        $this->info("Auditing {$linkedPatientCount} linked patient-account pairs...");

        $counts = [
            'eligible' => 0,
            'mismatched' => 0,
            'missing_fields' => 0,
            'marked' => 0,
            'skipped_already_marked' => 0,
        ];
        $reasonCounts = [];

        foreach ($linkedPatients as $patient) {
            $account = $patient->account;

            if ($account === null) {
                $counts['mismatched']++;

                continue;
            }

            if (! $markReview) {
                $match = $matcher->handle($account, $patient);
                $this->recordMatch($match, $counts, $reasonCounts);

                continue;
            }

            $result = DB::transaction(function () use ($patient, $matcher, $auditLog): array {
                // Lock order: User -> Patient, matching all link writers.
                $userId = Patient::query()->whereKey($patient->id)->value('user_id');

                if ($userId === null) {
                    return ['state' => 'missing'];
                }

                $account = User::query()->lockForUpdate()->find($userId);

                if ($account === null) {
                    return ['state' => 'mismatched'];
                }

                $lockedPatient = Patient::query()->lockForUpdate()->find($patient->id);

                if ($lockedPatient === null || $lockedPatient->user_id !== $account->id) {
                    return ['state' => 'changed'];
                }

                $match = $matcher->handle($account, $lockedPatient);

                if ($match->isEligible()) {
                    return ['state' => 'eligible'];
                }

                if ($lockedPatient->identity_review_required) {
                    return ['state' => 'already_marked', 'match' => $match];
                }

                $lockedPatient->markForIdentityReview();

                $auditLog->handle(
                    subject: $lockedPatient,
                    action: AuditEvent::PatientIdentityReviewRequired,
                    metadata: [
                        'reason' => 'reconciliation_audit',
                        'mismatched_fields' => $match->mismatchedFields,
                        'missing_fields' => $match->missingFields,
                    ],
                    actorId: null,
                );

                return [
                    'state' => 'marked',
                    'match' => $match,
                ];
            });

            if ($result['state'] === 'eligible') {
                $counts['eligible']++;

                continue;
            }

            if ($result['state'] === 'already_marked') {
                if ($result['match'] instanceof PatientAccountIdentityMatch) {
                    $this->recordReasonCounts($result['match'], $reasonCounts);
                }
                $counts['skipped_already_marked']++;

                continue;
            }

            if ($result['state'] === 'missing') {
                $counts['missing_fields']++;

                continue;
            }

            if ($result['state'] === 'changed') {
                $counts['mismatched']++;

                continue;
            }

            $counts['mismatched']++;

            if ($result['state'] === 'marked') {
                if ($result['match'] instanceof PatientAccountIdentityMatch) {
                    $this->recordReasonCounts($result['match'], $reasonCounts);
                }
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

        if ($reasonCounts !== []) {
            $this->newLine();
            $this->info('Safe reason counts:');
            ksort($reasonCounts);
            $this->table(
                ['Reason', 'Count'],
                array_map(
                    fn (string $reason, int $count): array => [$reason, $count],
                    array_keys($reasonCounts),
                    array_values($reasonCounts),
                ),
            );
        }

        if (! $markReview) {
            $this->newLine();
            $this->info('Dry run complete. Use --mark-review to mark incompatible links.');
        } else {
            $this->newLine();
            $this->info("Marked {$counts['marked']} links for review.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{eligible: int, mismatched: int, missing_fields: int, marked: int, skipped_already_marked: int}  $counts
     * @param  array<string, int>  $reasonCounts
     */
    private function recordMatch(PatientAccountIdentityMatch $match, array &$counts, array &$reasonCounts): void
    {
        $this->recordReasonCounts($match, $reasonCounts);

        if ($match->isEligible()) {
            $counts['eligible']++;

            return;
        }

        if ($match->mismatchedFields !== []) {
            $counts['mismatched']++;
        } else {
            $counts['missing_fields']++;
        }
    }

    /**
     * @param  array<string, int>  $reasonCounts
     */
    private function recordReasonCounts(PatientAccountIdentityMatch $match, array &$reasonCounts): void
    {
        foreach ($match->mismatchedFields as $field) {
            $reason = 'mismatched:'.$field;
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }

        foreach ($match->missingFields as $field) {
            $reason = 'missing:'.$field;
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }
    }
}
