<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            // 0 = Chủ nhật ... 6 = Thứ bảy (khớp Carbon::dayOfWeek)
            $table->unsignedTinyInteger('day_of_week')->unique();
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        // Ngày nghỉ của tiệm: Tết, lễ, nghỉ đột xuất
        Schema::create('closed_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closed_days');
        Schema::dropIfExists('business_hours');
    }
};
