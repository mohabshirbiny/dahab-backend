<?php

namespace App\Support\Listings;

use App\Enums\CertificateKind;
use App\Exceptions\DomainApiException;
use App\Models\Lab;

/** The certificate as a seller states it: what the request may say and what the listing stores. */
final class ListingCertificate
{
    /**
     * Older clients still send `igi`: it is `lab` with the main lab.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> what to merge into the request
     */
    public static function fromLegacy(array $input): array
    {
        return ($input['certificate_kind'] ?? null) === CertificateKind::LEGACY_IGI
            ? ['certificate_kind' => CertificateKind::LAB->value, 'certificate_lab_id' => Lab::main()->lab_id]
            : [];
    }

    /**
     * The listing's columns: `lab` names an enabled lab, `other_lab` a typed name, the rest neither.
     *
     * @param  array<string, mixed>  $data
     * @return array{certificate_kind: string|null, certificate_lab_id: int|null, certificate_other_lab_name: string|null}
     */
    public static function columns(array $data): array
    {
        $kind = CertificateKind::tryFrom((string) ($data['certificate_kind'] ?? ''));
        $labId = null;
        $name = null;

        if ($kind === CertificateKind::LAB) {
            $labId = isset($data['certificate_lab_id']) ? (int) $data['certificate_lab_id'] : null;
            if ($labId === null || ! Lab::query()->whereKey($labId)->where('is_enabled', true)->exists()) {
                throw DomainApiException::certificateLabRequired();
            }
        }

        if ($kind === CertificateKind::OTHER_LAB) {
            $name = trim((string) ($data['certificate_other_lab_name'] ?? ''));
            if ($name === '') {
                throw DomainApiException::otherLabNameRequired();
            }
        }

        return ['certificate_kind' => $kind?->value, 'certificate_lab_id' => $labId, 'certificate_other_lab_name' => $name];
    }
}
