<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\BrandContext\Models\Brand;
use Statamic\Facades\Entry;

/**
 * Which brand a sibling addon's event belongs to.
 *
 * The listeners used to look for automations only in the brand that happened
 * to be current, and for these events that is usually the wrong question. A
 * scheduler (`payments:reminders`) runs with no brand at all, so its fifteen
 * reminders started nothing; a provider webhook falls back to the default
 * brand, so another brand's flows started and wrote under the wrong sender.
 * The event knows better: the models it carries are stamped with a brand.
 *
 * In order:
 *
 * 1. An explicit `brandId` (or `brand_id`) on the event. The courses addon's
 *    events carry only ids, and this is where it can say which brand it means.
 * 2. `brand_id` on any model the event carries: the subscription, payment,
 *    partner, commission, entitlement. The first one that has it wins; the
 *    models of one event share a brand.
 * 3. For a course event: the site of the course entry, mapped to a brand by
 *    `brand-context.sites`, the same map the site middleware reads.
 *
 * Null when none of that answers. The caller decides what null means.
 */
class EventBrand
{
    public function of(object $event): ?int
    {
        foreach (['brandId', 'brand_id'] as $key) {
            $explicit = $this->idOf($event->{$key} ?? null);

            if ($explicit !== null) {
                return $explicit;
            }
        }

        foreach (get_object_vars($event) as $value) {
            if (! is_object($value)) {
                continue;
            }

            $stamped = $this->idOf($this->brandColumnOf($value));

            if ($stamped !== null) {
                return $stamped;
            }
        }

        return $this->fromCourseEntry($event);
    }

    protected function brandColumnOf(object $model): mixed
    {
        return $model->brand_id ?? null;
    }

    /**
     * A course event names its course by entry id. The entry's site is the
     * brand, where the site knows one.
     */
    protected function fromCourseEntry(object $event): ?int
    {
        $state = $event->state ?? null;
        $courseId = $event->courseId ?? (is_object($state) ? ($state->course_entry_id ?? null) : null);

        if (! is_string($courseId) || $courseId === '') {
            return null;
        }

        $sites = config('brand-context.sites', []);

        if (! is_array($sites) || $sites === []) {
            return null;
        }

        try {
            $entry = Entry::find($courseId);
            $site = $entry !== null && method_exists($entry, 'locale') ? $entry->locale() : null;
            $handle = is_string($site) ? ($sites[$site] ?? null) : null;

            if (! is_string($handle) || $handle === '') {
                return null;
            }

            $brand = Brand::query()->where('handle', $handle)->value('id');

            return $this->idOf($brand);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Zero and below are "no brand", the way the sibling addons write it
     * (`brand_id > 0 ? … : null`), so the next source is asked.
     */
    protected function idOf(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
