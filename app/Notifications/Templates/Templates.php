<?php

namespace App\Notifications\Templates;

use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Support\Content\SettingPlaceholders;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Renders a customer message from its template (spec 019 FR-043, R6).
 *
 * The text is the latest *published* override of the key, else the catalogue's default. It is
 * filled with what the message knows plus the setting placeholders. A message is never lost to
 * a template: no override, a missing language, a name that cannot be filled (no branch, no
 * amount) or any failure at all sends the app's own default instead, and this class never throws.
 *
 * Overrides are read from the shared cache; `PublishTextsAction` forgets the entry after commit,
 * so a long-running queue worker picks a publish up on its next message.
 */
final class Templates
{
    public const CACHE_KEY = 'app_text.notification_overrides';

    private const NAME = '/\{([a-z][a-z0-9_]*)\}/';

    /**
     * @param  bool|string  $lang  `true`/`'ar'` for Arabic, else English
     * @param  array<string, scalar|null>  $params
     */
    public static function text(string $key, bool|string $lang, array $params = []): string
    {
        $ar = $lang === true || $lang === 'ar';

        try {
            return self::resolve([$key], $ar, $params);
        } catch (Throwable) {
            return self::defaultOf($key, $ar, $params);
        }
    }

    /**
     * The email body of a message: its own `.mail_body` override, else the override of its
     * `.sms` sentence, else the default — so editing the SMS changes the email too until
     * the email body is edited on its own.
     *
     * @param  string  $base  `<area>.<event>`
     * @param  array<string, scalar|null>  $params
     */
    public static function mailBody(string $base, bool|string $lang, array $params = []): string
    {
        $ar = $lang === true || $lang === 'ar';

        try {
            return self::resolve([$base.'.mail_body', $base.'.sms'], $ar, $params);
        } catch (Throwable) {
            return self::defaultOf($base.'.sms', $ar, $params);
        }
    }

    /** Forget what the workers cached (after a publish). */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  list<string>  $keys  override keys, tried in order; the last is also the default's key
     * @param  array<string, scalar|null>  $params
     */
    private static function resolve(array $keys, bool $ar, array $params): string
    {
        // The default is the first key the catalogue holds: a message's own email body, else its SMS sentence.
        $defaultKey = null;
        foreach ($keys as $k) {
            if (Catalogue::entry($k) !== null) {
                $defaultKey = $k;
                break;
            }
        }
        if ($defaultKey === null) {
            // A key the catalogue does not know is a programming error; say so in the log, not to the customer.
            return '';
        }

        $overrides = self::overrides();
        foreach ($keys as $k) {
            $text = $overrides[$k][$ar ? 'ar' : 'en'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $filled = self::fill($text, $params, strict: true);
                if ($filled !== null) {
                    return $filled;
                }

                break;
            }
        }

        return self::defaultOf($defaultKey, $ar, $params);
    }

    /** @param  array<string, scalar|null>  $params */
    private static function defaultOf(string $key, bool $ar, array $params): string
    {
        $text = Catalogue::default($key, $ar ? 'ar' : 'en') ?? '';

        return (string) self::fill($text, $params, strict: false);
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return string|null null when `$strict` and a name has no value
     */
    private static function fill(string $text, array $params, bool $strict): ?string
    {
        $settings = null;
        $missing = false;

        $out = preg_replace_callback(self::NAME, function (array $m) use ($params, &$settings, &$missing) {
            $name = $m[1];
            $value = $params[$name] ?? null;
            if ($value === null && in_array($name, SettingPlaceholders::names(), true)) {
                $settings ??= (new SettingPlaceholders)->values();
                $value = $settings[$name] ?? null;
            }
            if ($value === null || $value === '') {
                $missing = true;

                return '';
            }

            return (string) $value;
        }, $text);

        return $strict && $missing ? null : (string) $out;
    }

    /** @return array<string, array{en: string, ar: string}> key => the live override */
    private static function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $rows = AppTextVersion::query()
                ->join('app_text_key', 'app_text_key.text_key', '=', 'app_text_version.text_key')
                ->where('app_text_version.state', AppTextVersion::PUBLISHED)
                ->where('app_text_version.is_default_marker', false)
                ->where('app_text_key.kind', AppTextKey::KIND_NOTIFICATION)
                ->get(['app_text_version.text_key', 'app_text_version.text_en', 'app_text_version.text_ar']);

            $map = [];
            foreach ($rows as $row) {
                $map[$row->text_key] = ['en' => $row->text_en, 'ar' => $row->text_ar];
            }

            return $map;
        });
    }
}
