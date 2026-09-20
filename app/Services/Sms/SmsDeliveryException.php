<?php

namespace App\Services\Sms;

use RuntimeException;

/**
 * The provider refused the message or could not be reached. Thrown so the
 * queued notification fails and Horizon retries it — a failed SMS never
 * unwinds an already-committed registration.
 */
class SmsDeliveryException extends RuntimeException {}
