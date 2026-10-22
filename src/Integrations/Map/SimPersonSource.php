<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Statamic\Facades\User;

/**
 * Which automations a real person has been through, for the dry run's "as it is for person X".
 *
 * A run names who it was about by `subject_key`, almost always the address, lower-cased and trimmed (see
 * `EnrollmentGate`). The CRM finds the person and hands over her identity; this source only reads the runs that
 * carry that key. Test runs do not count: they are the editor's, not hers.
 *
 * Read only: two selects, nothing written, nothing logged. The automation list is the definition store's, read
 * through the repository so both storage drivers work.
 */
class SimPersonSource implements ProvidesSimPersons
{
    /** Most automations one person is reported to have been through. */
    public const LIMIT = 50;

    /** How far back her newest grant is looked for: 30 days. The step's own window decides whether it counts. */
    public const GRANT_LOOKBACK_MINUTES = 43200;

    public function __construct(protected AutomationRepository $automations) {}

    public function personSourceId(): string
    {
        return 'automations';
    }

    /** The runs show which automations were sent to whom, so the same right as the automations themselves. */
    public function personsAuthorized($user): bool
    {
        return $user !== null && method_exists($user, 'can') && (bool) $user->can('view automations');
    }

    /** This source finds nobody: the CRM does. */
    public function searchPersons(string $query, int $limit): array
    {
        return [];
    }

    public function identifyPerson(string $key): ?array
    {
        return null;
    }

    /**
     * @param  array{email?: string, id?: string|int}  $identity
     * @return array{ran?: list<string>, traits?: array<string, mixed>}
     */
    public function personFacet(array $identity): array
    {
        $key = mb_strtolower(trim((string) ($identity['email'] ?? '')));

        if ($key === '') {
            return [];
        }

        $facet = [];

        $ran = $this->ran($key);

        if ($ran !== []) {
            $facet['ran'] = $ran;
        }

        // How she came: the freebie she was given last, with how long ago. The welcome series asks exactly that.
        $freebie = $this->newestGrant($key);

        if ($freebie !== null) {
            $facet['traits'] = $freebie;
        }

        return $facet;
    }

    /** @return list<string> */
    private function ran(string $key): array
    {
        $uuids = AutomationRun::query()
            ->where('subject_key', $key)
            ->where('is_test', false)
            ->whereNotNull('automation_uuid')
            ->distinct()
            ->limit(self::LIMIT)
            ->pluck('automation_uuid')
            ->all();

        if ($uuids === []) {
            return [];
        }

        return $this->automations->all()
            ->filter(fn (Automation $automation) => in_array((string) $automation->uuid, $uuids, true))
            ->map(fn (Automation $automation) => (string) ($automation->handle !== null && $automation->handle !== '' ? $automation->handle : $automation->uuid))
            ->values()
            ->all();
    }

    /**
     * Her newest grant that gives access now, as the two traits the dry run reads for "did she just unlock something"
     * (`entitlements.recent_grant`: the slug, `…_age`: minutes ago). Which grants she holds is the entitlements
     * addon's to share, so it needs the right to view them; without it nothing is read, and the copy says nothing about
     * how she came. Read only, nothing logged.
     *
     * @return array<string, mixed>|null
     */
    private function newestGrant(string $email): ?array
    {
        $user = User::current();

        if ($user === null || ! method_exists($user, 'can') || ! $user->can('view entitlements')) {
            return null;
        }

        try {
            $result = app(EntitlementsAdapter::class)->recentGrant($email, self::GRANT_LOOKBACK_MINUTES);
        } catch (\Throwable) {
            return null;
        }

        $grant = ($result['ok'] ?? false) === true ? ($result['grant'] ?? null) : null;

        if (! is_array($grant) || ($grant['product_slug'] ?? '') === '' || ! ($grant['granted_at'] ?? null) instanceof \DateTimeInterface) {
            return null;
        }

        return [
            'entitlements.recent_grant' => (string) $grant['product_slug'],
            'entitlements.recent_grant_age' => max(0, (int) floor((now()->getTimestamp() - $grant['granted_at']->getTimestamp()) / 60)),
        ];
    }
}
