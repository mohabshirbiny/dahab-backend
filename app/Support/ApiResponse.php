<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Uniform JSON envelope for API v1 responses.
 *
 * Kept intentionally small: business handlers may return either
 * `ApiResponse::ok(...)` or a plain Laravel Resource — both are supported.
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $message, string $code = 'server_error', int $status = 400, array $errors = []): JsonResponse
    {
        $payload = [
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
