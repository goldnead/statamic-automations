<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\Insights\RunMetric;
use Goldnead\StatamicAutomations\Integrations\Insights\Runs;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The automations as objects a funnels map can point at.
 *
 * The map keeps `type` and `id` and asks here for everything else. The id is the
 * handle, the same key the automation's sub-automation references and the
 * Control Panel use, so a map reference and a flow never disagree about which
 * automation is meant.
 *
 * Definitions are read through the repository, not Eloquent, so both storage
 * drivers work: a `flat_file` install has no rows to query.
 *
 * The metric is the figure the insights addon calls "Runs" ({@see Runs}),
 * narrowed to one automation. The rule is the same one: a run counts on
 * `started_at`, only inside the period, never past now, and never a test run.
 * Runs are linked by `automation_uuid`, the one reference a flat-file definition
 * has. The bounds are formatted with the column's millisecond format, for the
 * reason {@see RunMetric::untilNow()}
 * gives.
 */
class AutomationMapObjects implements ProvidesMapObjects
{
    public const TYPE = 'automation';

    public function __construct(protected AutomationRepository $automations) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function typeLabel(): string
    {
        return __('statamic-automations::automations.map_type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $needle = Str::lower(trim((string) $query));

        return $this->automations->all()
            ->filter(fn (Automation $automation) => $needle === ''
                || Str::contains(Str::lower((string) $automation->name), $needle)
                || Str::contains(Str::lower((string) $automation->handle), $needle))
            ->sortBy(fn (Automation $automation) => Str::lower((string) $automation->name))
            ->take(max(1, $limit))
            ->map(fn (Automation $automation) => $this->toObject($automation))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $automation = $this->resolve($id);

        return $automation ? $this->toObject($automation) : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $automation = $this->resolve($id);

        if (! $automation) {
            return null;
        }

        $zone = date_default_timezone_get();
        $format = MillisecondDateTime::FORMAT;

        $count = AutomationRun::query()
            ->where('automation_uuid', $automation->uuid)
            ->where('is_test', false)
            ->where('started_at', '>=', $period->from->setTimezone($zone)->format($format))
            ->where('started_at', '<', $period->to->setTimezone($zone)->format($format))
            ->where('started_at', '<=', Carbon::now($zone)->format($format))
            ->count();

        return new MapMetric((int) $count, __('statamic-automations::insights.runs'), $period);
    }

    /**
     * The handle, or the uuid for a definition that somehow has none.
     */
    protected function idOf(Automation $automation): string
    {
        return $automation->handle !== null && $automation->handle !== ''
            ? (string) $automation->handle
            : (string) $automation->uuid;
    }

    protected function toObject(Automation $automation): MapObject
    {
        return new MapObject(
            type: self::TYPE,
            id: $this->idOf($automation),
            label: $automation->name !== '' ? (string) $automation->name : $this->idOf($automation),
            cpUrl: cp_route('statamic-automations.automations.edit', $automation->id),
            enabled: (bool) $automation->enabled,
        );
    }

    /** The automation behind a map id, or null. findByRef also takes the uuid and the numeric id. */
    protected function resolve(string $id): ?Automation
    {
        return $this->automations->findByRef($id);
    }
}
