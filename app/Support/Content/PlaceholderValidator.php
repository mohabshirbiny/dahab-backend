<?php

namespace App\Support\Content;

use App\Exceptions\DomainApiException;
use App\Models\AppTextKey;

/**
 * The rules a text must meet before it can be saved as a draft or published (spec 019 FR-004,
 * R5): both languages present, within the key's length, `{snake_case}` placeholders only and
 * only from the key's own list or the setting list, braces balanced, and every placeholder the
 * key requires (`{code}`, `{link}`) kept in both languages.
 *
 * Each failure is a `DomainApiException` with its own stable code, so the Dashboard can say
 * which rule was broken and where.
 */
final class PlaceholderValidator
{
    /**
     * @throws DomainApiException
     */
    public function validate(AppTextKey $key, string $en, string $ar): void
    {
        $missing = array_keys(array_filter(['en' => trim($en) === '', 'ar' => trim($ar) === '']));
        if ($missing !== []) {
            throw DomainApiException::contentLanguageMissing($missing);
        }

        $tooLong = array_keys(array_filter([
            'en' => mb_strlen($en) > $key->max_length,
            'ar' => mb_strlen($ar) > $key->max_length,
        ]));
        if ($tooLong !== []) {
            throw DomainApiException::contentTooLong($key->max_length, $tooLong);
        }

        $allowed = [...$key->placeholders, ...SettingPlaceholders::names()];
        $unknown = [];
        foreach ([$en, $ar] as $text) {
            foreach ($this->fragmentsIn($text) as $fragment) {
                if (! in_array($fragment, $allowed, true)) {
                    $unknown[$fragment] = $fragment;
                }
            }
        }
        if ($unknown !== []) {
            throw DomainApiException::contentInvalidPlaceholder(array_values($unknown));
        }

        $dropped = [];
        foreach ($key->required_placeholders as $name) {
            foreach ([$en, $ar] as $text) {
                if (! str_contains($text, '{'.$name.'}')) {
                    $dropped[$name] = $name;
                }
            }
        }
        if ($dropped !== []) {
            throw DomainApiException::contentMissingRequiredPlaceholder(array_values($dropped));
        }
    }

    /**
     * The names used in a text, in order. Anything between braces is returned as written, so a
     * name that is not `snake_case` (`{Bad Name}`) is simply not in the allowed list; an empty
     * pair is `{}` and a brace with no partner is `unbalanced {`.
     *
     * @return list<string>
     */
    public function fragmentsIn(string $text): array
    {
        $found = [];
        if (preg_match_all('/\{([^{}]*)\}/u', $text, $m) > 0) {
            foreach ($m[1] as $inner) {
                $found[] = $inner === '' ? '{}' : $inner;
            }
        }

        $rest = preg_replace('/\{[^{}]*\}/u', '', $text) ?? '';
        foreach (['{', '}'] as $brace) {
            if (str_contains($rest, $brace)) {
                $found[] = 'unbalanced '.$brace;
            }
        }

        return $found;
    }
}
