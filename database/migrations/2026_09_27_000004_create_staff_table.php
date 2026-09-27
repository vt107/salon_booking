<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            // Tài khoản đăng nhập (không bắt buộc: có thợ chỉ nhận lịch, không vào admin)
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->string('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_bookable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Dịch vụ thợ làm được; NULL = dùng giá / thời lượng mặc định của dịch vụ
        Schema::create('staff_service', function (Blueprint $table) {
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('custom_price')->nullable();
            $table->unsignedSmallInteger('custom_duration_minutes')->nullable();

            $table->primary(['staff_id', 'service_id']);
        });

        // Lịch làm việc cố định theo tuần; ca gãy (nghỉ trưa) = nhiều dòng trong một ngày
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['staff_id', 'day_of_week']);
        });

        Schema::create('staff_time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_offs');
        Schema::dropIfExists('staff_schedules');
        Schema::dropIfExists('staff_service');
        Schema::dropIfExists('staff');
    }
};
