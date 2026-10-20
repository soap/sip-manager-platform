<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a POST of the tenant API safe to repeat: with an Idempotency-Key header, the first request
 * runs and its successful answer is kept for 24 hours; a repeat with the same key and body gets that
 * answer again (header Idempotent-Replayed: true) and changes nothing. Secrets in the answer (a SIP
 * password) are kept as "[redacted]", since they are shown once only. A failed request forgets the
 * key, so the client may try again with it.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER, '');

        if ($key === '') {
            return $next($request);
        }

        if (strlen($key) > 255) {
            return response()->json(['message' => 'The Idempotency-Key header may be at most 255 characters.'], 422);
        }

        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        IdempotencyKey::query()->where('tenant_id', $this->context->id())->where('key', $key)->where('expires_at', '<', now())->delete();

        try {
            $record = IdempotencyKey::query()->create([
                'tenant_id' => $this->context->id(),
                'key' => $key,
                'request_hash' => $hash,
                'expires_at' => now()->addHours(IdempotencyKey::LIFETIME_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        if (! $response->isSuccessful() || ! $response instanceof JsonResponse) {
            $record->delete();

            return $response;
        }

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => Audit::redact((array) $response->getData(true)),
        ]);

        return $response;
    }

    private function replay(string $key, string $hash): JsonResponse
    {
        $record = IdempotencyKey::query()->where('tenant_id', $this->context->id())->where('key', $key)->first();

        if ($record !== null && ! hash_equals($record->request_hash, $hash)) {
            return response()->json(['message' => 'This Idempotency-Key was used with a different request.'], 422);
        }

        if ($record === null || ! $record->isComplete()) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still running. Try again shortly.'], 409);
        }

        return response()->json($record->response_body, (int) $record->response_status, ['Idempotent-Replayed' => 'true']);
    }
}
