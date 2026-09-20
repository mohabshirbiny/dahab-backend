<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused business operation with a stable machine-readable `code`
 * (docs Part 2 §12), rendered centrally in bootstrap/app.php as
 * `{ message, code }`. Auth failures use AuthApiException instead.
 */
class DomainApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function documentAlreadyPending(): self
    {
        return new self('document_already_pending', 409, 'An identity document is already waiting for review.');
    }

    public static function unsupportedDocKind(): self
    {
        return new self('unsupported_doc_kind', 422, 'Unsupported identity document kind.');
    }

    /** Unknown, expired, already used, someone else's, or for another purpose — deliberately indistinguishable. */
    public static function uploadTokenInvalid(): self
    {
        return new self('upload_token_invalid', 422, 'The upload token is invalid or has expired.');
    }

    public static function illegalDocumentTransition(string $from, string $to): self
    {
        return new self('illegal_document_transition', 409, "An identity document cannot move from {$from} to {$to}.");
    }

    public static function documentImageDeleted(): self
    {
        return new self('document_image_deleted', 410, 'The image of this document has been deleted.');
    }
}
