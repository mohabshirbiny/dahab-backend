<?php

namespace App\Support\Invoices\Documents;

use App\Services\IdentityDocumentStorage;

/**
 * Where tax documents live (spec 016 research R9): the private disk,
 * encrypted application-side like every customer file, one object per number.
 */
final class TaxDocumentStore
{
    public function __construct(private readonly IdentityDocumentStorage $storage) {}

    public function put(string $customerId, string $number, string $pdf): string
    {
        $ref = 'tax-documents/'.$customerId.'/'.$number.'.pdf.enc';
        $this->storage->putAt($ref, $pdf);

        return $ref;
    }

    public function read(string $ref): string
    {
        return $this->storage->read($ref);
    }
}
