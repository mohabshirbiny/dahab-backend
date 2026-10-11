<?php

namespace App\Support\Controls;

use App\Enums\ControlLevel;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\CategoryControl;
use Illuminate\Support\Collection;

/**
 * What is switched off right now (spec 019 US7, FR-071). The one place the effects ask:
 * listing create and submit, buy requests, requests for more time, withdrawals and the
 * market reads. A control is only ever read fresh, so a switch applies to the very next call.
 *
 * Levels:
 *  - `stop_new_listings`: new listings in the category are refused.
 *  - `pause_category`: as above, plus live pieces nobody has asked for are hidden, and
 *    requests for more time on its orders are refused (D4).
 *  - `stop_everything`: one row per category; no new listing, buy request or withdrawal, no
 *    staff release of a held withdrawal (D5). Cancelling a pending withdrawal stays allowed.
 *
 * Anything with a locked price is never touched: orders, payments and handovers finish.
 */
final class Controls
{
    /** @return Collection<int, CategoryControl> */
    public function active(): Collection
    {
        return CategoryControl::query()->where('is_active', true)->orderBy('set_at')->get();
    }

    /** The control that stops everything, if any: stopping writes one row per category. */
    public function platformStop(): ?CategoryControl
    {
        return CategoryControl::query()
            ->where('is_active', true)->where('level', ControlLevel::STOP_EVERYTHING->value)
            ->orderBy('set_at')->first();
    }

    /** The control that keeps new listings out of a category, strongest level first. */
    public function listingBlock(PieceCategory $category): ?CategoryControl
    {
        return CategoryControl::query()
            ->where('is_active', true)->where('category', $category->value)
            ->whereIn('level', [ControlLevel::STOP_EVERYTHING->value, ControlLevel::PAUSE_CATEGORY->value, ControlLevel::STOP_NEW_LISTINGS->value])
            ->orderByRaw("CASE level WHEN 'stop_everything' THEN 0 WHEN 'pause_category' THEN 1 ELSE 2 END")
            ->first();
    }

    /** The pause on a category, if any (a stop of everything is not a pause: pieces stay visible). */
    public function pause(PieceCategory $category): ?CategoryControl
    {
        return CategoryControl::query()
            ->where('is_active', true)->where('category', $category->value)
            ->where('level', ControlLevel::PAUSE_CATEGORY->value)->first();
    }

    /** @throws DomainApiException `platform_stopped` or `category_stopped` */
    public function assertCanList(PieceCategory $category): void
    {
        $block = $this->listingBlock($category);
        if ($block === null) {
            return;
        }

        throw $block->level === ControlLevel::STOP_EVERYTHING
            ? DomainApiException::platformStopped(self::message($block))
            : DomainApiException::categoryStopped(self::message($block));
    }

    /** @throws DomainApiException `platform_stopped` */
    public function assertPlatformRunning(): void
    {
        $stop = $this->platformStop();
        if ($stop !== null) {
            throw DomainApiException::platformStopped(self::message($stop));
        }
    }

    /** @throws DomainApiException `category_paused` */
    public function assertNotPaused(PieceCategory $category): void
    {
        $pause = $this->pause($category);
        if ($pause !== null) {
            throw DomainApiException::categoryPaused(self::message($pause));
        }
    }

    /** @return array{en: string, ar: string} */
    public static function message(?CategoryControl $control): array
    {
        return [
            'en' => (string) ($control?->message_en ?? ''),
            'ar' => (string) ($control?->message_ar ?? ''),
        ];
    }
}
