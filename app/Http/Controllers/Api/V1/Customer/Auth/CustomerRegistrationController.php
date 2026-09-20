<?php

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\Actions\Auth\Customer\StartCustomerEmailVerificationAction;
use App\Actions\Auth\Customer\StartCustomerRegistrationAction;
use App\Actions\Auth\Customer\SubmitCustomerRegistrationAction;
use App\Actions\Auth\Customer\UploadCustomerRegistrationDocumentAction;
use App\Actions\Auth\Customer\VerifyCustomerEmailOtpAction;
use App\Actions\Auth\Customer\VerifyCustomerRegistrationOtpAction;
use App\Enums\AuthErrorCode;
use App\Enums\IdentityDocumentKind;
use App\Exceptions\AuthApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Customer\StartEmailVerificationRequest;
use App\Http\Requests\Auth\Customer\StartRegistrationRequest;
use App\Http\Requests\Auth\Customer\SubmitRegistrationRequest;
use App\Http\Requests\Auth\Customer\UploadRegistrationDocumentRequest;
use App\Http\Requests\Auth\Customer\VerifyEmailOtpRequest;
use App\Http\Requests\Auth\Customer\VerifyRegistrationOtpRequest;
use App\Http\Resources\Customer\CustomerResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The six-step customer registration.
 *
 *   1  start                 → phone OTP by SMS
 *   2  verify-phone-otp      → phone_verified
 *   3  email                 → email OTP by mail
 *   4  verify-email-otp      → email_verified
 *   5  documents             → encrypted upload bound to the registration_ref
 *   6  submit                → creates the customer as pending_verification
 *
 * Steps 1–5 hold state in an encrypted cache entry keyed by an opaque
 * `registration_ref`; only step 6 writes to the database. The customer is
 * `pending_verification` after submit and cannot log in until a reviewer
 * approves them. No access/refresh token pair is issued here.
 *
 * `POST /register/complete` is retained as a 410 deprecation stub so any
 * pre-existing client learns to move to `/register/submit`.
 */
class CustomerRegistrationController extends Controller
{
    public function __construct(
        private readonly StartCustomerRegistrationAction $start,
        private readonly VerifyCustomerRegistrationOtpAction $verifyPhoneOtp,
        private readonly StartCustomerEmailVerificationAction $startEmail,
        private readonly VerifyCustomerEmailOtpAction $verifyEmailOtp,
        private readonly UploadCustomerRegistrationDocumentAction $uploadDocument,
        private readonly SubmitCustomerRegistrationAction $submit,
    ) {}

    #[OA\Post(
        path: '/customer/auth/register/start',
        operationId: 'customerAuthRegisterStart',
        summary: 'Step 1 — name/phone/password; sends phone OTP by SMS',
        description: 'Creates nothing. Hashes the password, stashes it on an encrypted registration session and sends an SMS OTP to the phone.',
        tags: ['Customer Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StartRegistrationRequest')),
        responses: [
            new OA\Response(response: 202, description: 'phone_otp_sent'),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function start(StartRegistrationRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->start->execute($request->validated(), $ctx);

        return response()->json([
            'data' => [
                'registration_ref' => $result['ref'],
                'status' => 'phone_otp_sent',
                'otp_channel' => 'sms',
                'otp_expires_at' => $result['otp_expires_at']->toIso8601String(),
                'expires_at' => $result['expires_at']->toIso8601String(),
            ],
        ], 202);
    }

    #[OA\Post(
        path: '/customer/auth/register/verify-phone-otp',
        operationId: 'customerAuthRegisterVerifyPhoneOtp',
        summary: 'Step 2 — prove control of the phone number',
        description: 'Marks the held session phone-verified; idempotent on a session that is already phone-verified.',
        tags: ['Customer Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VerifyRegistrationOtpRequest')),
        responses: [
            new OA\Response(response: 200, description: 'phone_verified'),
            new OA\Response(response: 401, description: 'otp_invalid or otp_expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 410, description: 'registration_session_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function verifyPhoneOtp(VerifyRegistrationOtpRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $ref = $request->validated('registration_ref');

        $result = $this->verifyPhoneOtp->execute($ref, $request->validated('otp'), $ctx);

        return response()->json([
            'data' => [
                'registration_ref' => $ref,
                'status' => 'phone_verified',
                'phone_verified' => true,
                'expires_at' => $result['expires_at']->toIso8601String(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/customer/auth/register/email',
        operationId: 'customerAuthRegisterEmail',
        summary: 'Step 3 — email + governorate; sends email OTP',
        tags: ['Customer Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StartEmailVerificationRequest')),
        responses: [
            new OA\Response(response: 202, description: 'email_otp_sent'),
            new OA\Response(response: 409, description: 'registration_phone_unverified', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 410, description: 'registration_session_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed — email already registered or invalid governorate', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function email(StartEmailVerificationRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $ref = $request->validated('registration_ref');

        $result = $this->startEmail->execute($ref, [
            'email' => $request->validated('email'),
            'governorate' => $request->validated('governorate'),
        ], $ctx);

        return response()->json([
            'data' => [
                'registration_ref' => $ref,
                'status' => 'email_otp_sent',
                'otp_channel' => 'email',
                'otp_expires_at' => $result['otp_expires_at']->toIso8601String(),
                'expires_at' => $result['expires_at']->toIso8601String(),
            ],
        ], 202);
    }

    #[OA\Post(
        path: '/customer/auth/register/verify-email-otp',
        operationId: 'customerAuthRegisterVerifyEmailOtp',
        summary: 'Step 4 — prove control of the email address',
        tags: ['Customer Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VerifyEmailOtpRequest')),
        responses: [
            new OA\Response(response: 200, description: 'email_verified'),
            new OA\Response(response: 401, description: 'otp_invalid or otp_expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'registration_phone_unverified or registration_email_unverified (email step never called)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 410, description: 'registration_session_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function verifyEmailOtp(VerifyEmailOtpRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $ref = $request->validated('registration_ref');

        $result = $this->verifyEmailOtp->execute($ref, $request->validated('otp'), $ctx);

        return response()->json([
            'data' => [
                'registration_ref' => $ref,
                'status' => 'email_verified',
                'phone_verified' => true,
                'email_verified' => true,
                'expires_at' => $result['expires_at']->toIso8601String(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/customer/auth/register/documents',
        operationId: 'customerAuthRegisterDocuments',
        summary: 'Step 5 — upload identity document images against the registration',
        description: 'Egyptian ID → front + back; passport → front only. Images are encrypted at rest on a private disk and referenced only by the registration session.',
        tags: ['Customer Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/UploadRegistrationDocumentRequest'))),
        responses: [
            new OA\Response(response: 200, description: 'document_uploaded'),
            new OA\Response(response: 409, description: 'registration_phone_unverified or registration_email_unverified', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 410, description: 'registration_session_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed or unsupported_doc_kind', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function documents(UploadRegistrationDocumentRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $ref = $request->validated('registration_ref');
        $kind = IdentityDocumentKind::from($request->validated('document_type'));

        $result = $this->uploadDocument->execute(
            $ref,
            $kind,
            $request->file('front'),
            $request->file('back'),
            $ctx,
        );

        return response()->json([
            'data' => [
                'registration_ref' => $ref,
                'status' => 'document_uploaded',
                'doc_kind' => $result['doc_kind'],
                'front_uploaded' => $result['front_uploaded'],
                'back_uploaded' => $result['back_uploaded'],
                'expires_at' => $result['expires_at']->toIso8601String(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/customer/auth/register/submit',
        operationId: 'customerAuthRegisterSubmit',
        summary: 'Step 6 — submit the completed registration for staff verification',
        description: 'Creates the customer as pending_verification and attaches the pending identity document. Does NOT issue a session; the customer must wait for a Dashboard reviewer to approve.',
        tags: ['Customer Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubmitRegistrationRequest')),
        responses: [
            new OA\Response(response: 202, description: 'registration_submitted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'customer', ref: '#/components/schemas/CustomerProfile'),
                    new OA\Property(property: 'status', type: 'string', example: 'pending_verification'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 409, description: 'registration_phone_unverified | registration_email_unverified | registration_document_missing | registration_already_submitted', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 410, description: 'registration_session_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed — phone or email was taken while the registration was in flight', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function submit(SubmitRegistrationRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->submit->execute($request->validated('registration_ref'), $ctx);

        return response()->json([
            'data' => [
                'customer' => (new CustomerResource($result['customer']))->toArray($request),
                'status' => 'pending_verification',
            ],
        ], 202);
    }

    #[OA\Post(
        path: '/customer/auth/register/complete',
        operationId: 'customerAuthRegisterComplete',
        summary: 'Deprecated — use /customer/auth/register/submit',
        description: 'Retained for backward compatibility. Always returns 410 registration_endpoint_deprecated; creates nothing, issues no session, sends no notifications.',
        deprecated: true,
        tags: ['Customer Auth'],
        responses: [
            new OA\Response(response: 410, description: 'registration_endpoint_deprecated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function complete(): void
    {
        throw new AuthApiException(
            AuthErrorCode::REGISTRATION_ENDPOINT_DEPRECATED,
            410,
            'This endpoint has been replaced by /customer/auth/register/submit.',
        );
    }
}
