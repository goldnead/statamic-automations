<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;

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
     * @return array{ran?: list<string>}
     */
    public function personFacet(array $identity): array
    {
        $key = mb_strtolower(trim((string) ($identity['email'] ?? '')));

        if ($key === '') {
            return [];
        }

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

        $handles = $this->automations->all()
            ->filter(fn (Automation $automation) => in_array((string) $automation->uuid, $uuids, true))
            ->map(fn (Automation $automation) => (string) ($automation->handle !== null && $automation->handle !== '' ? $automation->handle : $automation->uuid))
            ->values()
            ->all();

        return ['ran' => $handles];
    }
}
