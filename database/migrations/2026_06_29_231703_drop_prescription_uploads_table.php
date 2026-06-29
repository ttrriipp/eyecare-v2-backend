<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('prescription_uploads');
    }

    public function down(): void
    {
        Schema::create('prescription_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->enum('status', ['pending', 'approved', 'rejected']);
            $table->text('admin_notes')->nullable();
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }
};
