<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // SĐT là định danh chính: khách đặt lịch không cần tài khoản
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable()->index();
            $table->string('password')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birthday')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_blocked')->default(false);

            $table->unsignedBigInteger('telegram_user_id')->nullable()->unique();
            $table->string('telegram_username', 64)->nullable();
            $table->string('preferred_channel', 20)->default('email');

            // Thống kê lưu sẵn, cập nhật khi booking completed / no_show
            $table->unsignedInteger('total_visits')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->unsignedSmallInteger('no_show_count')->default(0);
            $table->dateTime('last_visit_at')->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
