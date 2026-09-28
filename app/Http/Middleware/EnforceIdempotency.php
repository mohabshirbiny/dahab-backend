<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainApiException;
use App\Models\IdempotencyKey;
use App\Support\RequestContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `idempotent` — the server-side Idempotency-Key layer (spec 007 research R2,
 * Part 2 "Idempotency").
 *
 * A key is scoped to (actor, route). The first request claims it as
 * `in_flight` and runs; a response below 500 is stored for 24 h and replayed
 * for the same key and request with `Idempotent-Replayed: true`, without
 * running again. The same key with a different request is refused (never the
 * other request's response); a key still running is refused; a 5xx marks the
 * key `failed` so a retry runs again; an `in_flight` claim older than
 * STALE_SECONDS is treated as abandoned and taken over.
 *
 * Runs after `auth:*` and SetDatabaseActor (the table is under RLS), so list
 * it last on a route. Responses are stored and replayed byte for byte (as JSON).
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const TTL_HOURS = 24;

    private const STALE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER, '');
        if (! Str::isUuid($key)) {
            throw DomainApiException::idempotencyKeyRequired();
        }

        $scope = $this->scope($request);
        $hash = $this->hash($request);

        $claim = $this->claim($scope, $key, $hash);
        if ($claim instanceof Response) {
            return $claim;
        }

        $response = $next($request);

        $this->finish($claim, $response);

        return $response;
    }

    /** @return array{actor_kind: string, actor_customer_id: ?string, actor_staff_id: ?string, endpoint: string} */
    private function scope(Request $request): array
    {
        /** @var RequestContext|null $ctx */
        $ctx = $request->attributes->get('context');

        if ($ctx?->customerId === null && $ctx?->staffId === null) {
            throw new AuthenticationException;
        }

        $route = $request->route();

        return [
            'actor_kind' => $ctx->customerId !== null ? 'customer' : 'staff',
            'actor_customer_id' => $ctx->customerId,
            'actor_staff_id' => $ctx->customerId !== null ? null : $ctx->staffId,
            'endpoint' => $route?->getName() ?? $request->method().' '.($route?->uri() ?? $request->path()),
        ];
    }

    /** SHA-256 of the canonical (key-sorted) body plus the route parameters. */
    private function hash(Request $request): string
    {
        $params = $request->route()?->parameters() ?? [];

        return hash('sha256', (string) json_encode([
            'params' => $this->canonical(array_map(fn ($v) => is_object($v) ? (string) ($v->getKey() ?? '') : $v, $params)),
            'body' => $this->canonical($request->all()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }

    /**
     * Claims the key for this request, or returns the response to send instead.
     *
     * @param  array{actor_kind: string, actor_customer_id: ?string, actor_staff_id: ?string, endpoint: string}  $scope
     */
    private function claim(array $scope, string $key, string $hash): IdempotencyKey|Response
    {
        $now = Carbon::now();

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            ...$scope,
            'idem_key' => $key,
            'request_hash' => $hash,
            'state' => IdempotencyKey::STATE_IN_FLIGHT,
            'created_at' => $now,
            'expires_at' => $now->copy()->addHours(self::TTL_HOURS),
        ]);

        return DB::transaction(function () use ($scope, $key, $hash, $inserted, $now) {
            $row = IdempotencyKey::query()
                ->where('actor_kind', $scope['actor_kind'])
                ->where($scope['actor_kind'] === 'customer' ? 'actor_customer_id' : 'actor_staff_id', $scope['actor_customer_id'] ?? $scope['actor_staff_id'])
                ->where('endpoint', $scope['endpoint'])
                ->where('idem_key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inserted === 1) {
                return $row;
            }

            if ($row->request_hash !== $hash) {
                throw DomainApiException::idempotencyKeyMismatch();
            }

            if ($row->state === IdempotencyKey::STATE_COMPLETED) {
                return $this->replay($row);
            }

            if ($row->state === IdempotencyKey::STATE_IN_FLIGHT && $row->created_at->greaterThan($now->copy()->subSeconds(self::STALE_SECONDS))) {
                throw DomainApiException::idempotencyInProgress();
            }

            // A failed attempt, or an abandoned claim: this request takes it over.
            $row->forceFill([
                'state' => IdempotencyKey::STATE_IN_FLIGHT,
                'response_status' => null,
                'response_body' => null,
                'created_at' => $now,
                'completed_at' => null,
                'expires_at' => $now->copy()->addHours(self::TTL_HOURS),
            ])->save();

            return $row;
        });
    }

    private function finish(IdempotencyKey $row, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 500) {
            $row->forceFill(['state' => IdempotencyKey::STATE_FAILED, 'completed_at' => Carbon::now()])->save();

            return;
        }

        // The exact bytes, so a replay is identical to the original response.
        $content = $response->getContent();

        $row->forceFill([
            'state' => IdempotencyKey::STATE_COMPLETED,
            'response_status' => $status,
            'response_body' => is_string($content) && $content !== '' ? $content : null,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    private function replay(IdempotencyKey $row): Response
    {
        return response((string) $row->response_body, $row->response_status ?? 200, [
            'Content-Type' => 'application/json',
            self::REPLAYED_HEADER => 'true',
        ]);
    }
}
