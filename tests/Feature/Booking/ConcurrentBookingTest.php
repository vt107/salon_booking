<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Nhiều khách đặt cùng một khung giờ cùng lúc, mỗi người một tiến trình PHP và kết nối DB riêng.
 * Dữ liệu phải được commit thật để các tiến trình con nhìn thấy, nên không dùng RefreshDatabase.
 */
class ConcurrentBookingTest extends TestCase
{
    use BuildsSalon, DatabaseTruncation;

    private const REQUESTS = 12;

    protected function setUp(): void
    {
        parent::setUp();
        // Tiến trình con chạy theo giờ thật nên không đóng băng thời gian
        $this->buildSalon(freezeTime: false);
    }

    protected function tearDown(): void
    {
        // Dọn dữ liệu đã commit để không lọt sang các test dùng RefreshDatabase chạy sau
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_only_one_customer_gets_the_same_slot_of_the_same_staff(): void
    {
        $results = $this->raceFor(now()->addDay()->setTime(10, 0), $this->tuan->id);

        $this->assertCount(1, $results->where('ok', true), $results->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, Booking::where('staff_id', $this->tuan->id)->count());
    }

    public function test_any_staff_requests_fill_each_free_staff_exactly_once(): void
    {
        $startAt = now()->addDay()->setTime(10, 0);

        $results = $this->raceFor($startAt, 'any');

        // Tuấn và Nam đều cắt tóc được và đều rảnh lúc 10:00
        $winners = $results->where('ok', true);
        $this->assertCount(2, $winners, $results->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertEqualsCanonicalizing([$this->tuan->id, $this->nam->id], $winners->pluck('staff_id')->all());

        $this->assertSame(2, Booking::blocking()->where('start_at', $startAt)->count());
        $this->assertSame(0, Booking::where('status', '!=', BookingStatus::Pending)->count());
    }

    /**
     * @return Collection<int, array{ok: bool, staff_id?: int, error?: string}>
     */
    private function raceFor($startAt, int|string $staffId): Collection
    {
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];

        // Chờ các tiến trình khởi động Laravel xong rồi cùng xuất phát
        $goAt = sprintf('%.3F', microtime(true) + 3);

        $results = Process::pool(function (Pool $pool) use ($startAt, $staffId, $env, $goAt) {
            foreach (range(1, self::REQUESTS) as $i) {
                $pool->path(base_path())->env($env)->command([
                    PHP_BINARY, 'tests/Support/create-booking.php',
                    sprintf('09100000%02d', $i), $startAt->format('Y-m-d H:i'), (string) $this->haircut->id, (string) $staffId, $goAt,
                ]);
            }
        })->start()->wait();

        $results = collect($results->collect())->map(fn ($result) => json_decode($result->output(), true)
            ?? ['ok' => false, 'error' => $result->errorOutput() ?: $result->output()]);

        // Người thua chỉ được nhận lỗi nghiệp vụ, không được là deadlock / lỗi SQL
        foreach ($results->where('ok', false) as $result) {
            $this->assertStringStartsWith('BookingException:', $result['error']);
        }

        return $results;
    }
}
