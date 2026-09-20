<?php

namespace App\Support;

use DateTimeInterface;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Session',
    description: 'A token family: a short-lived access token plus a rotating refresh token. Each carries its own ability (`<principal>:access` / `<principal>:refresh`).',
    required: ['token_type', 'access_token', 'access_token_expires_at', 'refresh_token', 'refresh_token_expires_at', 'family_id'],
    properties: [
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'access_token', type: 'string', description: 'Use on access endpoints only'),
        new OA\Property(property: 'access_token_expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'refresh_token', type: 'string', description: 'Use on the refresh endpoint only'),
        new OA\Property(property: 'refresh_token_expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'family_id', type: 'string', format: 'uuid'),
    ],
)]
final class SessionDto
{
    public function __construct(
        public readonly string $accessToken,
        public readonly DateTimeInterface $accessTokenExpiresAt,
        public readonly string $refreshToken,
        public readonly DateTimeInterface $refreshTokenExpiresAt,
        public readonly string $familyId,
    ) {}

    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt->format(DATE_ATOM),
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt->format(DATE_ATOM),
            'family_id' => $this->familyId,
        ];
    }
}
