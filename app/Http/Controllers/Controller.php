<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Dahab API',
    description: 'Dahab Gold Marketplace Backend — API documentation. Two API surfaces exist: the Customer API (`/customer/*`, customer tokens) and the Dashboard API (`/dashboard/*`, staff tokens). Their tokens are not interchangeable.',
)]
#[OA\Server(url: '/api/v1', description: 'API v1')]
#[OA\Tag(name: 'Customer Auth', description: 'Customer API — authenticated with a customer token (`auth:customer`, ability `customer:access`).')]
#[OA\Tag(name: 'Dashboard Auth', description: 'Dashboard API — authenticated with a staff token (`auth:staff`, ability `staff:access`); authorization is Spatie roles/permissions.')]
#[OA\Tag(name: 'Customer Identity', description: 'Customer API — private image upload and identity document submission.')]
#[OA\Tag(name: 'Dashboard Identity', description: 'Dashboard API — identity document review. Gated by `identity.view` / `identity.review`; every image view is logged.')]
#[OA\SecurityScheme(
    securityScheme: 'customerBearer',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Customer API. The customer **access** token (ability `customer:access`). A staff token or a customer refresh token is rejected.',
)]
#[OA\SecurityScheme(
    securityScheme: 'customerRefreshBearer',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Customer API, refresh endpoint only. The customer **refresh** token (ability `customer:refresh`). It is not accepted anywhere else.',
)]
#[OA\SecurityScheme(
    securityScheme: 'dashboardBearer',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Dashboard API. The staff **access** token (ability `staff:access`). A customer token or a staff refresh token is rejected.',
)]
#[OA\SecurityScheme(
    securityScheme: 'dashboardRefreshBearer',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Dashboard API, refresh endpoint only. The staff **refresh** token (ability `staff:refresh`). It is not accepted anywhere else.',
)]
#[OA\Schema(
    schema: 'ApiError',
    required: ['message', 'code'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
        new OA\Property(property: 'code', type: 'string', example: 'unauthenticated', description: 'Stable machine-readable code; see contracts/error-codes.md'),
        new OA\Property(property: 'errors', type: 'object', nullable: true, description: 'Field errors, present on validation_failed only', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
    ],
)]
abstract class Controller
{
    //
}
