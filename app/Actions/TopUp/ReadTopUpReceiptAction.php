<?php

namespace App\Actions\TopUp;

use App\Models\TopUp;
use App\Services\IdentityDocumentStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The decrypted receipt of a notice, for staff with `topup.match` (spec 009
 * FR-011). Not separately audited: a receipt is not an identity document
 * (spec Assumptions); the match that relies on it is audited.
 */
final class ReadTopUpReceiptAction
{
    public function __construct(private readonly IdentityDocumentStorage $storage) {}

    /** @return array{bytes: string, mime: string} */
    public function handle(string $topUpId): array
    {
        $topUp = TopUp::query()->whereKey($topUpId)->firstOrFail();

        if ($topUp->receipt_ref === null || ! $this->storage->exists($topUp->receipt_ref)) {
            throw new NotFoundHttpException('This transfer has no receipt.');
        }

        return ['bytes' => $this->storage->read($topUp->receipt_ref), 'mime' => (string) $topUp->receipt_mime];
    }
}
