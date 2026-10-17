<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;

/**
 * Question in a flow: has this person just unlocked something?
 *
 * Where {@see CheckAccessAction} asks "does she hold product X", this asks the
 * other way round: "which product, if any, did she get in the last hour". A flow
 * that is about to mail somebody uses it to learn that a different mail already
 * told her about a fresh unlock.
 *
 * Only registered when the entitlements addon is detected.
 *
 * ## "Not found" is an answer
 *
 * The node succeeds with `found` false. It neither stops nor fails the run: the
 * branch behind it decides what no recent grant means. A failure is reserved for
 * "the check could not be made" (no addon, no address, a database error), because
 * `found` false for a lookup that never happened would be a wrong answer that
 * looks like a right one.
 *
 * ## What counts
 *
 * The newest grant of the address that gives access now (the addon's `state()`:
 * active or in grace) and whose activation lies at most `within_minutes` back.
 * Revoked, expired, scheduled and pending grants are not an unlock.
 *
 * ## The title
 *
 * `product_title` is a name a person can read, or empty. Never the slug: a mail
 * that says "Schön, dass du toolkit-2026 hast" is worse than one that leaves the
 * name out. Sources, first hit wins:
 *
 *  1. the entitlements addon's own product catalogue, when anything registered one;
 *  2. `automations.entitlements.title_sources`, a list of
 *     `['collection' => 'resources', 'match' => 'resource_slug', 'field' => 'title']`:
 *     the Statamic entry of that collection whose `match` field equals the slug;
 *  3. the lead-magnets addon: the title of the resource behind the grant, when
 *     that addon is installed and the grant belongs to one.
 *
 * Nothing here knows a site's collections; the config names them.
 *
 * ## Test mode
 *
 * Answers `found` false without looking. A preview has no real person, and a
 * `true` invented for it would send the preview down a path that never runs.
 */
class RecentGrantAction implements AutomationAction
{
    public const DEFAULT_WITHIN_MINUTES = 60;

    /** Default FQCN of the lead-magnets grant model (overridable via config). */
    public const LEAD_MAGNET_GRANT = 'Goldnead\\LeadMagnets\\Models\\Grant';

    public function __construct(protected EntitlementsAdapter $adapter) {}

    public static function handle(): string
    {
        return 'entitlements.recent_grant';
    }

    public static function label(): string
    {
        return 'Recent Grant';
    }

    public static function description(): ?string
    {
        return 'Asks whether a person has just unlocked something: the newest active grant of an address inside a time window.';
    }

    public static function group(): string
    {
        return 'Entitlements';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The address to look up, for example {{ payment.email }}. Found as the account that owns it and as a subject of type email.',
            ],
            [
                'handle' => 'within_minutes',
                'label' => 'Within the last (minutes)',
                'type' => 'number',
                'required' => false,
                'default' => self::DEFAULT_WITHIN_MINUTES,
                'help' => 'How far back an activation may lie to count as recent.',
            ],
            [
                'handle' => 'sources',
                'label' => 'Only these sources',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional, comma separated grant sources, for example lead_magnet, thrivecart. Empty means every source.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'found' => 'boolean',
            'product_slug' => 'string',
            'product_title' => 'string',
            'source' => 'string',
            'granted_at' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        if ($context->isTestMode()) {
            return ActionResult::success(self::answer(null) + ['note' => 'Test mode — nothing looked up.']);
        }

        $email = mb_strtolower(trim((string) ($config['email'] ?? '')));

        if ($email === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ payment.email }}');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failed('A valid "email" is required.');
        }

        $rawWindow = $config['within_minutes'] ?? '';
        $window = $rawWindow === '' ? self::DEFAULT_WITHIN_MINUTES : $rawWindow;

        if (! is_numeric($window) || (int) $window < 1) {
            return ActionResult::failed('"within_minutes" must be a whole number of at least 1.');
        }

        $sources = array_values(array_filter(array_map('trim', explode(',', (string) ($config['sources'] ?? '')))));

        $result = $this->adapter->recentGrant($email, (int) $window, $sources);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Looking up recent grants failed.');
        }

        $grant = $result['grant'] ?? null;

        if ($grant === null) {
            return ActionResult::success(self::answer(null));
        }

        return ActionResult::success(self::answer([
            'product_slug' => $grant['product_slug'],
            'product_title' => $this->title($grant['product_slug'], $grant['id']),
            'source' => $grant['source'],
            'granted_at' => Carbon::instance($grant['granted_at'])->toIso8601String(),
        ]));
    }

    /**
     * Every field, always: a branch that reads `product_title` of a "not found"
     * must get an empty string, not a missing key.
     *
     * @param  array{product_slug: string, product_title: string, source: string, granted_at: string}|null  $grant
     * @return array<string, mixed>
     */
    protected static function answer(?array $grant): array
    {
        return [
            'found' => $grant !== null,
            'product_slug' => $grant['product_slug'] ?? '',
            'product_title' => $grant['product_title'] ?? '',
            'source' => $grant['source'] ?? '',
            'granted_at' => $grant['granted_at'] ?? '',
        ];
    }

    protected function title(string $slug, mixed $entitlementId): string
    {
        return $this->adapter->productLabel($slug)
            ?? $this->titleFromEntries($slug)
            ?? $this->titleFromLeadMagnet($entitlementId)
            ?? '';
    }

    /**
     * The first configured collection with an entry whose `match` field equals
     * the slug and whose `field` holds text.
     */
    protected function titleFromEntries(string $slug): ?string
    {
        foreach ((array) config('automations.entitlements.title_sources', []) as $source) {
            $collection = trim((string) ($source['collection'] ?? ''));
            $match = trim((string) ($source['match'] ?? ''));
            $field = trim((string) ($source['field'] ?? ''));

            if ($collection === '' || $match === '' || $field === '') {
                continue;
            }

            try {
                // The collection's entries compared in PHP: a site's resource
                // list is short, and `slug` has to be read from the entry, not
                // from its data.
                $entry = Entry::whereCollection($collection)->first(
                    fn ($candidate) => (string) ($match === 'slug' ? $candidate->slug() : $candidate->get($match)) === $slug,
                );
                $title = $entry?->get($field);
            } catch (\Throwable) {
                // A misnamed collection costs this source, not the run.
                continue;
            }

            if (is_string($title) && trim($title) !== '') {
                return trim($title);
            }
        }

        return null;
    }

    /**
     * The title of the lead-magnet resource behind the entitlement, if that
     * addon is installed and the grant came from it. Reached by class-string,
     * like every sibling.
     */
    protected function titleFromLeadMagnet(mixed $entitlementId): ?string
    {
        $class = (string) config('automations.integrations.lead_magnets.grant_model', self::LEAD_MAGNET_GRANT);

        if ($entitlementId === null || $class === '' || ! class_exists($class)) {
            return null;
        }

        try {
            $grant = $class::query()->where('entitlement_id', $entitlementId)->with('resource')->first();
            $title = $grant?->resource?->title;
        } catch (\Throwable) {
            return null;
        }

        return (is_string($title) && trim($title) !== '') ? trim($title) : null;
    }
}
