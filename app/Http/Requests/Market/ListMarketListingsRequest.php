<?php

namespace App\Http\Requests\Market;

use App\Enums\PieceCategory;
use App\Http\Requests\Customer\Listing\ListingRules;
use App\Support\Listings\ListingCursor;
use App\Support\Listings\MarketQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters, sort and page of the public market (spec 010 FR-021, Part 2 §2). */
class ListMarketListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::enum(PieceCategory::class)],
            'karat' => ['sometimes', 'integer', 'between:1,24'],
            'piece_type' => ['sometimes', 'integer', 'min:1'],
            'branch' => ['sometimes', 'integer', 'min:1'],
            'min_g' => ['sometimes', ...ListingRules::WEIGHT],
            'max_g' => ['sometimes', ...ListingRules::WEIGHT],
            'sort' => ['sometimes', Rule::in(MarketQuery::SORTS)],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** @return array{category: string|null, karat: int|null, piece_type: int|null, branch: int|null, min_g: string|null, max_g: string|null} */
    public function filters(): array
    {
        $int = fn (string $key): ?int => $this->validated($key) === null ? null : (int) $this->validated($key);

        return [
            'category' => $this->validated('category'),
            'karat' => $int('karat'),
            'piece_type' => $int('piece_type'),
            'branch' => $int('branch'),
            'min_g' => $this->validated('min_g') === null ? null : (string) $this->validated('min_g'),
            'max_g' => $this->validated('max_g') === null ? null : (string) $this->validated('max_g'),
        ];
    }

    public function sort(): string
    {
        return (string) $this->validated('sort', 'newest');
    }

    public function cursor(): ?ListingCursor
    {
        return ListingCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
