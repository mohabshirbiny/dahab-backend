<?php

namespace App\Http\Requests\Dashboard\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\PartyRole;
use App\Support\Finance\Period;
use App\Support\Invoices\InvoiceListQuery;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The staff Invoices list and its export (spec 016 FR-010, FR-013). */
class ListInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return Period::rules() + [
            'party' => ['sometimes', 'string', Rule::enum(PartyRole::class)],
            'status' => ['sometimes', 'string', Rule::enum(InvoiceStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function after(): array
    {
        return [Period::after($this)];
    }

    public function listQuery(): InvoiceListQuery
    {
        [$from, $to] = Period::of($this, 30);

        return new InvoiceListQuery($from, $to, PartyRole::tryFrom((string) $this->validated('party')),
            InvoiceStatus::tryFrom((string) $this->validated('status')), $this->validated('q'));
    }

    public function cursor(): ?ListingCursor
    {
        return ListingCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
