<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->boolean('identity_review_required')->default(false)->after('phone_lookup_hash');
            $table->timestamp('identity_review_required_at')->nullable()->after('identity_review_required');

            $table->index('identity_review_required');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['identity_review_required']);
            $table->dropColumn(['identity_review_required', 'identity_review_required_at']);
        });
    }
};
