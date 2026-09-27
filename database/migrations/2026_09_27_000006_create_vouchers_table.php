<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 10);
            $table->unsignedInteger('value');
            // Trần giảm cho voucher phần trăm
            $table->unsignedInteger('max_discount')->nullable();
            $table->unsignedInteger('min_order_amount')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedSmallInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->string('scope', 20)->default('all');
            $table->boolean('first_booking_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Dịch vụ được áp dụng khi scope = services
        Schema::create('voucher_service', function (Blueprint $table) {
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->primary(['voucher_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_service');
        Schema::dropIfExists('vouchers');
    }
};
