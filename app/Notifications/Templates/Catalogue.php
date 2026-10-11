<?php

namespace App\Notifications\Templates;

/**
 * The customer message catalogue (spec 019 FR-040, R6): what each notification says by default,
 * the names it may use, and which texts carry a code or a link.
 *
 * The data lives in `CatalogueData`. This class adds what is derived from it: the email body of
 * a message that has none of its own is its SMS sentence, so staff can edit the two separately
 * (`<area>.<event>.mail_body`) while an untouched message keeps sending the same words.
 */
final class Catalogue
{
    public const AREA = 'Notifications';

    public const KIND = 'notification';

    /** @return array<string, array{en: string, ar: string, vars: list<string>, read_only: bool, inbox: bool, desc: string}> */
    public static function entries(): array
    {
        return CatalogueData::ENTRIES;
    }

    /** @return array{en: string, ar: string, vars: list<string>, read_only: bool, inbox: bool, desc: string}|null */
    public static function entry(string $key): ?array
    {
        return CatalogueData::ENTRIES[$key] ?? null;
    }

    public static function default(string $key, string $lang): ?string
    {
        $entry = self::entry($key);

        return $entry === null ? null : ($lang === 'ar' ? $entry['ar'] : $entry['en']);
    }

    /**
     * Every key staff see in the registry: the catalogue entries plus the `mail_body` of each SMS
     * sentence that has no email text of its own (its default is the SMS sentence).
     *
     * @return array<string, array{en: string, ar: string, vars: list<string>, read_only: bool, inbox: bool, desc: string, required: list<string>}>
     */
    public static function registryEntries(): array
    {
        $all = [];
        foreach (CatalogueData::ENTRIES as $key => $entry) {
            $all[$key] = $entry + ['required' => self::required($entry)];
        }

        foreach (CatalogueData::ENTRIES as $key => $entry) {
            if (! str_ends_with($key, '.sms')) {
                continue;
            }
            $mail = substr($key, 0, -4).'.mail_body';
            if (isset($all[$mail])) {
                continue;
            }
            $all[$mail] = [
                'desc' => str_replace('the SMS sentence (also the email and the inbox)', 'the email body (follows the SMS sentence unless changed)', $entry['desc']),
            ] + $entry + ['required' => self::required($entry)];
        }

        return $all;
    }

    /**
     * The names a text must keep: the code or the link of a read-only message (FR-042).
     *
     * @param  array{vars: list<string>, read_only: bool}  $entry
     * @return list<string>
     */
    private static function required(array $entry): array
    {
        return array_values(array_intersect($entry['vars'], ['code', 'link']));
    }
}
