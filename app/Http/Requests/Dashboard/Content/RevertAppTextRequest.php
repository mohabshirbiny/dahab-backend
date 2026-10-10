<?php

namespace App\Http\Requests\Dashboard\Content;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardRevertAppText',
    required: ['to'],
    properties: [
        new OA\Property(property: 'to', description: '"default" ends the override (the app shows its own text); a version number restores that version\'s wording', oneOf: [
            new OA\Schema(type: 'string', enum: ['default']),
            new OA\Schema(type: 'integer', minimum: 1),
        ]),
    ],
)]
class RevertAppTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'to' => ['required', function (string $attribute, mixed $value, \Closure $fail) {
                $ok = $value === 'default' || (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0);
                if (! $ok) {
                    $fail('The to field must be "default" or a version number.');
                }
            }],
        ];
    }

    public function target(): string|int
    {
        $to = $this->validated('to');

        return $to === 'default' ? 'default' : (int) $to;
    }
}
