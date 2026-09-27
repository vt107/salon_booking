<?php

namespace Tests\Feature\Telegram;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConfig;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramAdminConfigTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '7412345678:AAHabcdefghijklmnopqrstuvwxyz012345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => null, 'services.telegram.bot_username' => null, 'services.telegram.webhook_secret' => null]);
        Setting::set('shop.name', 'Salon Mây');
        $this->actingAs(User::factory()->admin()->create());
    }

    /** Nút nằm trong form (tab Telegram), không phải nút đầu trang */
    private function botAction(string $name): TestAction
    {
        return TestAction::make($name)->schemaComponent('botActions', schema: 'form');
    }

    private function fakeTelegram(bool $ok = true): void
    {
        Http::fake(fn (Request $request) => match (true) {
            ! $ok => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
            str_ends_with($request->url(), '/getMe') => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'salonmay_bot', 'first_name' => 'Salon Mây']]),
            str_ends_with($request->url(), '/getWebhookInfo') => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
            str_ends_with($request->url(), '/sendMessage') => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            default => Http::response(['ok' => true, 'result' => true]),
        });
    }

    public function test_admin_saves_a_verified_bot_token_encrypted(): void
    {
        $this->fakeTelegram();

        Livewire::test(Settings::class)
            ->fillForm(['bot.token' => self::TOKEN])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Đã kết nối bot @salonmay_bot')
            ->assertSet('data.bot.token', null);

        $config = app(TelegramConfig::class);
        $this->assertSame(self::TOKEN, $config->token());
        $this->assertSame('salonmay_bot', $config->username());
        $this->assertSame('admin', $config->source());
        $this->assertStringNotContainsString(self::TOKEN, (string) Setting::where('key', 'bot_token')->value('value'));
        $this->assertSame('7412345678…2345', $config->tokenHint());
    }

    public function test_rejected_token_is_not_saved(): void
    {
        $this->fakeTelegram(ok: false);

        Livewire::test(Settings::class)
            ->fillForm(['bot.token' => self::TOKEN, 'shop.name' => 'Tên mới'])
            ->call('save')
            ->assertHasFormErrors(['bot.token']);

        $this->assertFalse(app(TelegramConfig::class)->isConfigured());
        $this->assertSame('Salon Mây', Setting::get('shop.name'));
    }

    public function test_blank_token_keeps_the_current_bot_and_token_never_reaches_the_browser(): void
    {
        app(TelegramConfig::class)->saveBot(self::TOKEN, 'salonmay_bot');

        Livewire::test(Settings::class)
            ->assertDontSee(self::TOKEN)
            ->assertSee('7412345678…2345')
            ->fillForm(['shop.name' => 'Salon Mây'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::TOKEN, app(TelegramConfig::class)->token());
    }

    public function test_admin_token_takes_precedence_over_env_and_is_used_by_the_client(): void
    {
        config(['services.telegram.bot_token' => '111:ENVTOKENxxxxxxxxxxxxxxxxxxxxxxxxxxx']);
        $this->assertSame('env', app(TelegramConfig::class)->source());

        app(TelegramConfig::class)->saveBot(self::TOKEN, 'salonmay_bot');
        $this->fakeTelegram();
        app(TelegramClient::class)->sendMessage(1, 'hi');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'bot'.self::TOKEN.'/sendMessage'));
    }

    public function test_enabling_the_webhook_generates_a_secret_that_the_webhook_then_requires(): void
    {
        config(['app.url' => 'https://salonmay.vn']);
        app(TelegramConfig::class)->saveBot(self::TOKEN, 'salonmay_bot');
        $this->fakeTelegram();

        Livewire::test(Settings::class)
            ->callAction($this->botAction('enableWebhook'))
            ->assertNotified('Đã bật webhook: https://salonmay.vn/telegram/webhook');

        $secret = app(TelegramConfig::class)->webhookSecret();
        $this->assertNotEmpty($secret);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://salonmay.vn/telegram/webhook'
            && $r['secret_token'] === $secret);

        $this->postJson('/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();
        $this->postJson('/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }

    public function test_webhook_buttons_are_hidden_without_https(): void
    {
        app(TelegramConfig::class)->saveBot(self::TOKEN, 'salonmay_bot');

        Livewire::test(Settings::class)
            ->assertDontSee('Bật webhook')
            ->assertActionVisible($this->botAction('checkBot'))
            ->assertSee('make telegram');
    }

    public function test_forgetting_the_bot(): void
    {
        app(TelegramConfig::class)->saveBot(self::TOKEN, 'salonmay_bot');

        Livewire::test(Settings::class)->callAction($this->botAction('forgetBot'));

        $this->assertFalse(app(TelegramConfig::class)->isConfigured());
    }

    public function test_polling_uses_the_token_saved_in_admin(): void
    {
        app(TelegramConfig::class)->saveBot(self::TOKEN, 'salonmay_bot');
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/getUpdates')
            ? Http::response(['ok' => true, 'result' => []])
            : Http::response(['ok' => true, 'result' => ['username' => 'salonmay_bot']]));

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'bot'.self::TOKEN.'/getUpdates'));
    }
}
