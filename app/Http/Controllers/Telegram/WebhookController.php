<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramConfig;
use App\Services\Telegram\UpdateHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    public function __invoke(Request $request, UpdateHandler $handler, TelegramConfig $config): Response
    {
        $secret = (string) $config->webhookSecret();

        // Chỉ Telegram biết secret (gửi kèm lúc setWebhook)
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $handler->handle($request->all());
        } catch (Throwable $e) {
            // Luôn trả 200: nếu lỗi, Telegram sẽ gửi lại update đó liên tục
            Log::error('Lỗi xử lý update Telegram', ['error' => $e->getMessage(), 'update_id' => $request->input('update_id')]);
        }

        return response()->noContent(200);
    }
}
