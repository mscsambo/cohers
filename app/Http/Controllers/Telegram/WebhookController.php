<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Http\Request;

/**
 * POST /api/telegram/webhook — verifies the secret header, stores the
 * update ID, queues work and acknowledges promptly.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, TelegramWebhookService $webhook)
    {
        if (! $webhook->verifySecret($request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(401);
        }

        $webhook->ingest($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
