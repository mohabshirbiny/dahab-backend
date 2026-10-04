<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\BankMovement;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Open a bank movement's proof, decrypted; every view is audited (spec 015 FR-010). */
final class ViewBankMovementProofAction
{
    public function __construct(
        private readonly IdentityDocumentStorage $storage,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{bytes: string, mime: string} */
    public function handle(Staff $viewer, string $movementId): array
    {
        $movement = BankMovement::query()->whereKey($movementId)->firstOrFail();
        if ($movement->proof_ref === null) {
            throw (new ModelNotFoundException)->setModel(BankMovement::class, [$movementId]);
        }

        $bytes = $this->storage->read($movement->proof_ref);
        $this->audit->execute(AuditEvent::BANK_MOVEMENT_PROOF_VIEWED, 'success', ['number' => $movement->number()],
            'bank_movement', $movement->movement_id, actorStaffId: $viewer->staff_id);

        return ['bytes' => $bytes, 'mime' => (string) $movement->proof_mime];
    }
}
