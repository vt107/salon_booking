<?php

namespace Tests\Feature\Telegram;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\TelegramMessageType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Telegram\DailyReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private const GROUP = -1001234;

    private User $manager;

    /** Update mà getUpdates giả lập sẽ trả về */
    private array $pendingUpdates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();

        config([
            'services.telegram.bot_token' => 'TEST-TOKEN',
            'services.telegram.bot_username' => 'salon_bot',
            'services.telegram.webhook_secret' => 'shh',
        ]);
        Setting::set('telegram.group_chat_id', (string) self::GROUP);
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($request->url(), '/getUpdates') => Http::response(['ok' => true, 'result' => $this->pendingUpdates]),
            str_ends_with($request->url(), '/getMe') => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'salon_bot']]),
            default => Http::response(['ok' => true, 'result' => ['message_id' => 555]]),
        });

        $this->manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Chị Lan', 'telegram_user_id' => 42]);
    }

    private function customerBooking(array $overrides = []): Booking
    {
        return app(BookingService::class)->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan, overrides: $overrides));
    }

    /** @return list<Request> */
    private function calls(string $method): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), "/{$method}"))->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function webhook(array $update, string $secret = 'shh')
    {
        return $this->postJson(route('telegram.webhook'), $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    private function tap(Booking $booking, string $data, int $fromId = 42)
    {
        return $this->webhook(['update_id' => 1, 'callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => $fromId],
            'data' => $data,
            'message' => ['message_id' => 555, 'chat' => ['id' => self::GROUP]],
        ]]);
    }

    public function test_new_customer_booking_is_posted_to_the_group_with_approval_buttons(): void
    {
        $booking = $this->customerBooking(['customerNote' => 'Da đầu <nhạy cảm>']);

        [$request] = $this->calls('sendMessage');
        $this->assertSame(self::GROUP, $request['chat_id']);
        $this->assertStringContainsString($booking->code, $request['text']);
        $this->assertStringContainsString('Da đầu &lt;nhạy cảm&gt;', $request['text']); // nội dung khách nhập được escape
        $this->assertSame("confirm:{$booking->id}", $request['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $message = TelegramMessage::sole();
        $this->assertSame([self::GROUP, 555, TelegramMessageType::NewBooking], [$message->chat_id, $message->message_id, $message->type]);
    }

    public function test_bookings_created_by_staff_are_not_announced(): void
    {
        $this->customerBooking(['createdBy' => $this->manager, 'source' => BookingSource::Phone]);

        $this->assertSame([], $this->calls('sendMessage'));
    }

    public function test_without_a_group_each_linked_approver_is_messaged_privately(): void
    {
        Setting::set('telegram.group_chat_id', null);
        User::factory()->create(['role' => UserRole::Admin, 'telegram_user_id' => 43]);
        User::factory()->create(['role' => UserRole::Admin, 'telegram_user_id' => 44, 'notify_telegram' => false]);
        User::factory()->create(['role' => UserRole::Staff, 'telegram_user_id' => 45]);

        $this->customerBooking();

        $this->assertEqualsCanonicalizing([42, 43], array_map(fn ($r) => $r['chat_id'], $this->calls('sendMessage')));
    }

    public function test_messages_are_updated_when_the_booking_is_handled_in_the_admin(): void
    {
        $booking = $this->customerBooking();

        app(BookingService::class)->confirm($booking, $this->manager);

        [$edit] = $this->calls('editMessageText');
        $this->assertSame(555, $edit['message_id']);
        $this->assertStringContainsString('Đã xác nhận</b> bởi Chị Lan', $edit['text']);
        $this->assertSame([], $edit['reply_markup']['inline_keyboard']); // hết nút duyệt
    }

    public function test_webhook_requires_the_secret(): void
    {
        $this->webhook(['update_id' => 1], 'wrong')->assertForbidden();
        $this->postJson(route('telegram.webhook'), ['update_id' => 1])->assertForbidden();
        $this->webhook(['update_id' => 1])->assertOk();
    }

    public function test_manager_confirms_from_telegram(): void
    {
        $booking = $this->customerBooking();

        $this->tap($booking, "confirm:{$booking->id}")->assertOk();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->confirmedBy->is($this->manager));
        $this->assertStringContainsString('Đã xác nhận', $this->calls('answerCallbackQuery')[0]['text']);
    }

    public function test_unlinked_or_staff_accounts_cannot_approve(): void
    {
        User::factory()->create(['role' => UserRole::Staff, 'telegram_user_id' => 99]);
        $booking = $this->customerBooking();

        $this->tap($booking, "confirm:{$booking->id}", fromId: 99);
        $this->tap($booking, "confirm:{$booking->id}", fromId: 12345);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertTrue(collect($this->calls('answerCallbackQuery'))->every(fn ($r) => $r['show_alert'] === true));
    }

    public function test_reject_asks_for_a_reason_first(): void
    {
        $booking = $this->customerBooking();

        $this->tap($booking, "reject:{$booking->id}");
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertSame("reject:{$booking->id}:1", $this->calls('editMessageReplyMarkup')[0]['reply_markup']['inline_keyboard'][1][0]['callback_data']);

        $this->tap($booking, "reject:{$booking->id}:1");
        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
        $this->assertSame('Thợ bận đột xuất', $booking->fresh()->status_reason);
    }

    public function test_second_admin_tapping_an_already_handled_booking_gets_an_alert(): void
    {
        $booking = $this->customerBooking();
        app(BookingService::class)->reject($booking, $this->manager, 'Hết chỗ');

        $this->tap($booking, "confirm:{$booking->id}");

        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
        $answer = collect($this->calls('answerCallbackQuery'))->last();
        $this->assertTrue($answer['show_alert']);
        $this->assertStringContainsString('Từ chối', $answer['text']);
    }

    public function test_account_is_linked_with_a_one_time_start_token(): void
    {
        $admin = User::factory()->admin()->create(['telegram_link_token' => 'tok123', 'telegram_link_expires_at' => now()->addMinutes(10)]);

        $this->webhook(['update_id' => 2, 'message' => ['text' => '/start tok123', 'chat' => ['id' => 777], 'from' => ['id' => 777, 'username' => 'chutiem']]]);

        $admin->refresh();
        $this->assertSame([777, 'chutiem', null], [$admin->telegram_user_id, $admin->telegram_username, $admin->telegram_link_token]);

        $expired = User::factory()->admin()->create(['telegram_link_token' => 'old', 'telegram_link_expires_at' => now()->subMinute()]);
        $this->webhook(['update_id' => 3, 'message' => ['text' => '/start old', 'chat' => ['id' => 888], 'from' => ['id' => 888]]]);
        $this->assertNull($expired->fresh()->telegram_user_id);
    }

    public function test_chatid_command_helps_setting_up_a_group(): void
    {
        $this->webhook(['update_id' => 4, 'message' => ['text' => '/chatid@salon_bot', 'chat' => ['id' => -100999], 'from' => ['id' => 1]]]);

        $this->assertStringContainsString('-100999', $this->calls('sendMessage')[0]['text']);
    }

    public function test_approval_reminder_is_sent_once_close_to_the_deadline(): void
    {
        $booking = $this->customerBooking(); // hạn duyệt 09:30
        $this->travelTo(Carbon::parse('2026-10-05 09:20'));

        $this->artisan('bookings:remind-pending-approvals')->assertSuccessful();
        $this->artisan('bookings:remind-pending-approvals')->assertSuccessful();

        $this->assertSame(1, $booking->telegramMessages()->where('type', TelegramMessageType::ApprovalReminder)->count());
    }

    public function test_daily_summary_and_report(): void
    {
        $booking = $this->customerBooking();
        $this->occupy($this->nam, '2026-10-06 09:00', 30); // ngày mai, không thuộc tóm tắt hôm nay

        $summary = app(DailyReports::class)->summary(today());
        $this->assertStringContainsString('1 lịch (1 chờ duyệt)', $summary);
        $this->assertStringContainsString('Tuấn', $summary);
        $this->assertStringNotContainsString('Nam', $summary);

        app(BookingService::class)->confirm($booking, $this->manager);
        $this->travelTo(Carbon::parse('2026-10-05 10:40'));
        app(BookingService::class)->complete($booking, $this->manager, PaymentMethod::Cash);

        $report = app(DailyReports::class)->report(today());
        $this->assertStringContainsString('Doanh thu: <b>100.000đ</b> (1 lịch hoàn thành)', $report);
        $this->assertStringContainsString('Ngày mai: 1 lịch', $report);
    }

    public function test_daily_summary_is_broadcast_once_at_the_configured_time(): void
    {
        Setting::set('telegram.daily_summary_time', '07:00');
        $this->travelTo(Carbon::parse('2026-10-05 07:00:20'));

        $this->artisan('telegram:daily-reports');
        $this->artisan('telegram:daily-reports');

        $this->assertCount(1, $this->calls('sendMessage'));
    }

    public function test_telegram_outage_never_breaks_booking(): void
    {
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('timeout')]);

        $booking = $this->customerBooking();

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(0, TelegramMessage::count());
    }

    public function test_polling_processes_updates(): void
    {
        $booking = $this->customerBooking();
        $this->pendingUpdates = [[
            'update_id' => 10,
            'callback_query' => ['id' => 'cb', 'from' => ['id' => 42], 'data' => "confirm:{$booking->id}", 'message' => ['message_id' => 555, 'chat' => ['id' => self::GROUP]]],
        ]];

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }
}
