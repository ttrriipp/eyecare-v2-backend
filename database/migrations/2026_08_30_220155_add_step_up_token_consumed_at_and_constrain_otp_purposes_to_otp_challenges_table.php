<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PURPOSES = [
        'registration',
        'login_step_up',
        'password_recovery',
        'add_contact',
        'replace_primary_contact',
        'invitation_acceptance',
        'sensitive_change',
        'step_up',
    ];

    private const PURPOSE_CONSTRAINT = 'otp_challenges_purpose_check';

    private const SQLITE_INSERT_TRIGGER = 'otp_challenges_purpose_insert_check';

    private const SQLITE_UPDATE_TRIGGER = 'otp_challenges_purpose_update_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->timestamp('step_up_token_consumed_at')->nullable()->after('consumed_at');
        });

        $this->addPurposeConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropPurposeConstraint();

        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropColumn('step_up_token_consumed_at');
        });
    }

    private function addPurposeConstraint(): void
    {
        $connection = Schema::getConnection();
        $purposes = implode(', ', array_map(
            static fn (string $purpose): string => "'".str_replace("'", "''", $purpose)."'",
            self::PURPOSES,
        ));

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            $connection->statement(
                'ALTER TABLE otp_challenges ADD CONSTRAINT '.self::PURPOSE_CONSTRAINT." CHECK (purpose IN ({$purposes}))",
            );

            return;
        }

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $connection->statement(
            'CREATE TRIGGER '.self::SQLITE_INSERT_TRIGGER.' '
            .'BEFORE INSERT ON otp_challenges '
            .'FOR EACH ROW WHEN NEW.purpose NOT IN ('.$purposes.') '
            .'BEGIN SELECT RAISE(ABORT, \'Invalid OTP purpose.\'); END',
        );
        $connection->statement(
            'CREATE TRIGGER '.self::SQLITE_UPDATE_TRIGGER.' '
            .'BEFORE UPDATE OF purpose ON otp_challenges '
            .'FOR EACH ROW WHEN NEW.purpose NOT IN ('.$purposes.') '
            .'BEGIN SELECT RAISE(ABORT, \'Invalid OTP purpose.\'); END',
        );
    }

    private function dropPurposeConstraint(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement(
                'ALTER TABLE otp_challenges DROP CHECK '.self::PURPOSE_CONSTRAINT,
            );
        } elseif (in_array($driver, ['pgsql', 'sqlsrv'], true)) {
            $connection->statement(
                'ALTER TABLE otp_challenges DROP CONSTRAINT '.self::PURPOSE_CONSTRAINT,
            );
        } elseif ($driver === 'sqlite') {
            $connection->statement('DROP TRIGGER IF EXISTS '.self::SQLITE_INSERT_TRIGGER);
            $connection->statement('DROP TRIGGER IF EXISTS '.self::SQLITE_UPDATE_TRIGGER);
        }
    }
};
