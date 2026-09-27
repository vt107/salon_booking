<?php

namespace App\Notifications\Customer;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email gửi khách về một lịch hẹn. Mỗi loại email chỉ khai báo tiêu đề, lời dẫn và nút bấm;
 * phần chi tiết lịch hẹn dùng chung template mail/booking.
 */
abstract class BookingMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Booking $booking)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(Customer $notifiable): array
    {
        return filled($notifiable->email) ? ['mail'] : [];
    }

    abstract protected function subject(): string;

    abstract protected function heading(): string;

    /** @return list<string> các đoạn văn (markdown) */
    abstract protected function paragraphs(): array;

    /** Lịch còn hiệu lực thì mời khách xem / hủy; lịch đã kết thúc thì mời đặt lịch mới */
    protected function action(): array
    {
        return $this->booking->status->isBlocking()
            ? ['Xem hoặc hủy lịch hẹn', $this->booking->manageUrl()]
            : ['Đặt lịch khác', route('booking.create')];
    }

    public function toMail(Customer $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['items', 'staff', 'voucher']);
        [$actionText, $actionUrl] = $this->action();

        return (new MailMessage)
            ->subject("{$this->subject()} · {$booking->code}")
            ->markdown('mail.booking', [
                'customer' => $notifiable,
                'booking' => $booking,
                'heading' => $this->heading(),
                'paragraphs' => $this->paragraphs(),
                'actionText' => $actionText,
                'actionUrl' => $actionUrl,
            ]);
    }

    protected function when(): string
    {
        $weekdays = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
        $start = $this->booking->start_at;

        return $start->format('H:i').', '.$weekdays[$start->dayOfWeek].' '.$start->format('d/m/Y');
    }
}
