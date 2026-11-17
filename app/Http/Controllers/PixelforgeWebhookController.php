<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Generation\ReceiveWebhookEvent;
use App\Infrastructure\Pixelforge\InvalidWebhookSignature;
use App\Infrastructure\Pixelforge\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge. Anyone can call this URL, so nothing in the body
 * is trusted until the signature over the raw bytes checks out.
 *
 * Any 2xx tells Pixelforge the event was delivered, so we answer 2xx for
 * everything we deliberately ignore (a ping, events we do not use) and only
 * ask for a retry (non-2xx) when the request is not genuine or not readable.
 */
final class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignatureVerifier $verifier,
        ReceiveWebhookEvent $receive,
    ): Response|JsonResponse {
        try {
            $verifier->verify($request->getContent(), $request->header('Pixelforge-Signature'), time());
        } catch (InvalidWebhookSignature $e) {
            Log::warning('Rejected a Pixelforge webhook.', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $event = json_decode($request->getContent(), true);
        $eventId = is_array($event) ? ($event['id'] ?? null) : null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if (! is_string($eventId) || ! is_string($type)) {
            return response()->json(['error' => 'Not an event.'], 400);
        }

        $generation = $event['data']['object'] ?? null;

        // Pings and any event type we do not act on: acknowledge and move on.
        if (! str_starts_with($type, 'generation.')) {
            return response()->noContent();
        }

        if (! is_array($generation) || ! is_string($generation['id'] ?? null)) {
            return response()->json(['error' => 'The event has no generation.'], 400);
        }

        $receive->handle($eventId, $type, $generation['id'], $event);

        return response()->noContent();
    }
}
