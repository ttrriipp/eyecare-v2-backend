<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_order_eyewear_specifications')) {
            return;
        }

        Schema::create('job_order_eyewear_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->unique()->constrained('job_orders')->restrictOnDelete();
            $table->foreignId('prescription_id')->constrained('prescriptions')->restrictOnDelete();
            $table->foreignId('frame_job_order_item_id')->nullable()->constrained('job_order_items')->nullOnDelete();
            $table->foreignId('lens_package_job_order_item_id')
                ->constrained('job_order_items')
                ->restrictOnDelete()
                ->name('joes_lens_pkg_item_fk');

            $table->string('frame_source', 30)->default('catalog');

            // Lens construction snapshots
            $table->string('lens_design_snapshot')->nullable();
            $table->string('lens_material_snapshot')->nullable();
            $table->string('refractive_index_snapshot')->nullable();
            $table->json('lens_options_snapshot')->nullable();

            // Dispensing measurements (encrypted)
            $table->string('distance_pd_mode', 20)->default('binocular');
            $table->text('distance_pd_binocular')->nullable();
            $table->text('distance_pd_od')->nullable();
            $table->text('distance_pd_os')->nullable();
            $table->text('near_pd_binocular')->nullable();
            $table->text('near_pd_od')->nullable();
            $table->text('near_pd_os')->nullable();
            $table->text('fitting_height_od')->nullable();
            $table->text('fitting_height_os')->nullable();
            $table->text('segment_height_od')->nullable();
            $table->text('segment_height_os')->nullable();

            // Lab instructions (encrypted)
            $table->text('lab_instructions')->nullable();

            // Approval attribution
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Verification attribution
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_eyewear_specifications');
    }
};
