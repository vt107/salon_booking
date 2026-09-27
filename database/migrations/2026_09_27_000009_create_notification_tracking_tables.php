<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tin nhắn Telegram đã gửi cho admin, để sửa lại (bỏ nút, ghi trạng thái) khi booking đổi trạng thái
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            // chat_id của group Telegram là số âm
            $table->bigInteger('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('type', 30);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('notifiable');
            $table->string('type', 100);
            $table->string('channel', 20);
            $table->string('recipient');
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('telegram_messages');
    }
};
