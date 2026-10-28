<?php

namespace App\Support\Controls;

use App\Enums\ControlLevel;
use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Enums\SettingKey;
use App\Models\CategoryControl;
use App\Models\Listing;
use App\Support\Pricing\Settings;

/**
 * What the apps and the Dashboard read about the switches (spec 019 US7).
 *
 * `customer()` is the public shape (`GET /reference/controls`): whether everything is stopped
 * and the banner text, the paused categories, and the visibility flags. `staff()` adds, per
 * category, the strongest control, who set it and why, and the figures staff weigh before
 * pausing: pieces live, pieces nobody has asked for, pieces with a locked price.
 */
final class ControlsSnapshot
{
    public function __construct(private readonly Controls $controls, private readonly Settings $settings) {}

    /**
     * @return array{
     *     stop_everything: array{active: bool, message: array{en: string, ar: string}},
     *     categories: list<array{category: string, level: string, message: array{en: string, ar: string}}>,
     *     visibility: array<string, bool>
     * }
     */
    public function customer(): array
    {
        $active = $this->controls->active();
        $stop = $active->firstWhere('level', ControlLevel::STOP_EVERYTHING);

        return [
            'stop_everything' => [
                'active' => $stop !== null,
                'message' => Controls::message($stop),
            ],
            'categories' => $active
                ->filter(fn (CategoryControl $c) => $c->level !== ControlLevel::STOP_EVERYTHING)
                ->map(fn (CategoryControl $c) => [
                    'category' => $c->category->value,
                    'level' => $c->level->value,
                    'message' => Controls::message($c),
                ])->values()->all(),
            'visibility' => $this->visibility(),
        ];
    }

    /** @return array<string, bool> keyed by the name on the API (`rapaport_reference`, …) */
    public function visibility(): array
    {
        $keys = array_values(array_filter(SettingKey::cases(), fn (SettingKey $k) => $k->visibilityName() !== null));
        $values = $this->settings->bools($keys);

        $out = [];
        foreach ($keys as $key) {
            $out[$key->visibilityName()] = $values[$key->value];
        }

        return $out;
    }

    /**
     * @return array{
     *     stop_everything: array{active: bool, set_by: ?array{id: string, full_name: string}, set_at: ?string, reason: ?string, message: array{en: string, ar: string}},
     *     categories: list<array<string, mixed>>,
     *     visibility: array<string, bool>
     * }
     */
    public function staff(): array
    {
        $active = $this->controls->active()->load('setter');
        $stop = $active->firstWhere('level', ControlLevel::STOP_EVERYTHING);

        $counts = Listing::query()
            ->whereIn('state', [ListingState::LIVE->value, ListingState::RESERVED->value])
            ->selectRaw('category, state, count(*) as n')->groupBy('category', 'state')->get();

        $categories = [];
        foreach (PieceCategory::cases() as $category) {
            $own = $active->filter(fn (CategoryControl $c) => $c->category === $category)->sortByDesc(fn (CategoryControl $c) => $c->level->severity());
            $top = $own->first();
            $live = (int) $counts->filter(fn ($r) => $r->category === $category && $r->state === ListingState::LIVE)->sum('n');
            $reserved = (int) $counts->filter(fn ($r) => $r->category === $category && $r->state === ListingState::RESERVED)->sum('n');

            $categories[] = [
                'category' => $category->value,
                'state' => $top?->level->value ?? 'open',
                'controls' => $own->map(fn (CategoryControl $c) => self::row($c))->values()->all(),
                // What a pause would touch, and what it would leave alone (the design's three counts).
                'live_count' => $live + $reserved,
                'no_request_count' => $live,
                'price_locked_count' => $reserved,
            ];
        }

        return [
            'stop_everything' => [
                'active' => $stop !== null,
                'set_by' => $stop?->setter === null ? null : ['id' => $stop->setter->staff_id, 'full_name' => $stop->setter->full_name],
                'set_at' => $stop?->set_at->toIso8601String(),
                'reason' => $stop?->reason,
                'message' => Controls::message($stop),
            ],
            'categories' => $categories,
            'visibility' => $this->visibility(),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(CategoryControl $c): array
    {
        return [
            'level' => $c->level->value,
            'message' => Controls::message($c),
            'reason' => $c->reason,
            'set_by' => $c->setter === null ? null : ['id' => $c->setter->staff_id, 'full_name' => $c->setter->full_name],
            'set_at' => $c->set_at->toIso8601String(),
        ];
    }
}
