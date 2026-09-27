<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            $table->dateTime('start_at');
            $table->dateTime('end_at');
            // end_at + buffer của dịch vụ: khoảng thời gian thợ bị chiếm, dùng để kiểm tra trùng lịch
            $table->dateTime('occupied_until');

            $table->string('status', 20)->default('pending');
            // Quá hạn mà admin chưa duyệt => tự hủy
            $table->dateTime('approval_deadline_at')->nullable();
            $table->string('source', 20)->default('web');
            $table->foreignId('qr_code_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_staff_auto_assigned')->default(false);

            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->dateTime('paid_at')->nullable();

            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();

            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            // Lý do từ chối / hủy
            $table->string('status_reason')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'start_at']);
            $table->index('start_at');
            $table->index(['status', 'start_at']);
            $table->index(['status', 'approval_deadline_at']);
            $table->index(['customer_id', 'start_at']);
            $table->index('paid_at');
        });

        // Snapshot tên / giá / thời lượng tại lúc đặt: đổi giá sau này không ảnh hưởng lịch sử
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('service_name');
            $table->unsignedInteger('price');
            // Phần giảm giá voucher phân bổ cho dịch vụ này (báo cáo doanh thu theo dịch vụ)
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // User (admin/nhân viên) hoặc Customer; NULL = hệ thống
            $table->nullableMorphs('actor');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('voucher_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('discount_amount');
            // Booking bị hủy / từ chối => trả lại lượt dùng
            $table->dateTime('released_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['voucher_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_usages');
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
