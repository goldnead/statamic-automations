<?php

namespace Goldnead\StatamicAutomations\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * How far back the activity screen is looking.
 *
 * A handful of named spans rather than a pair of dates, because the question
 * this screen answers is "is this flow working *now*", and the addon ships no
 * date picker: `@statamic/cms/ui` has none, and half a date picker — two text
 * inputs and a format nobody agrees on — reads worse than four options that are
 * always valid and always comparable between two automations.
 *
 * Only a lower bound. There is no "until", because every span ends at now; an
 * upper bound would only ever be used to look at a window in the past, which is
 * a different screen (the run listing, which already takes `from`/`to`).
 *
 * An unrecognised value is not an error — it is `all`. A range arrives in a URL,
 * and a URL is edited by hand and pasted between installs; answering a typo with
 * a 422 in the middle of a builder page would be worse than showing everything.
 */
final class ActivityWindow
{
    /** The spans on offer, in days. `null` is "everything there is". */
    private const SPANS = [
        '7' => 7,
        '30' => 30,
        '90' => 90,
        'all' => null,
    ];

    public const DEFAULT = '30';

    private function __construct(
        public readonly string $range,
        public readonly ?Carbon $from,
    ) {}

    public static function make(?string $range): self
    {
        $key = $range !== null && array_key_exists($range, self::SPANS) ? $range : 'all';
        $days = self::SPANS[$key];

        return new self($key, $days === null ? null : Carbon::now()->subDays($days)->startOfDay());
    }

    public static function fromRequest(Request $request, string $key = 'range'): self
    {
        $value = $request->query($key);

        return self::make(is_string($value) ? $value : self::DEFAULT);
    }

    public static function default(): self
    {
        return self::make(self::DEFAULT);
    }

    /** Narrow a query to this window. A window of `all` narrows nothing. */
    public function apply(BuilderContract $query, string $column = 'created_at'): BuilderContract
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from);
        }

        return $query;
    }

    /**
     * The options the Select renders, labelled in full sentences rather than
     * "7d" — the screen has the room and the abbreviation saves nothing.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['value' => '7', 'label' => __('Last 7 days')],
            ['value' => '30', 'label' => __('Last 30 days')],
            ['value' => '90', 'label' => __('Last 90 days')],
            ['value' => 'all', 'label' => __('All time')],
        ];
    }
}
