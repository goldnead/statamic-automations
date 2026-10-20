<?php

namespace Goldnead\StatamicAutomations\Integrations\Booking\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Gate in a flow: does this person hold a booking *now*, and if the answer is
 * not the one the flow was built on, end the run.
 *
 * Only registered when the booking addon is detected.
 *
 * ## Why it reads the table
 *
 * The booking addon exposes no public read method, and this addon must not
 * depend on it. So the node reads the table that the booking addon owns, by
 * the name in `statamic-booking.table` (default `bookings`). The addon does not
 * have to be installed in the test app for the node to be valid, and it never
 * writes anything.
 *
 * ## Three modes
 *
 *  - `require`: continue only if a matching booking exists.
 *  - `forbid`: continue only if no matching booking exists.
 *  - `report`: never stop; output `found` and `count` for a branch node.
 *
 * In `require` and `forbid`, when the condition does not hold the run is
 * **stopped**, not failed. A stop is an ordinary outcome; a failure means the
 * check could not be made.
 *
 * ## It never passes by default
 *
 * If the table is missing, the query throws, or no identifier is given, the
 * node **fails**. A `forbid` that passed because the check broke would send a
 * mail to somebody who must not get it.
 *
 * ## What counts as a match
 *
 *  - `email`: compared without regard to case and surrounding spaces.
 *  - `external_id`: the provider's own id for the booking.
 *  - `endpoint`: the booking endpoint it came through, when set.
 *  - `statuses`: the status list, comma separated. The status is what counts.
 *    `cancelled_at` is not checked a second time, because the addon writes it
 *    together with the status, and a rejected booking carries it too.
 *  - `created_after`: only bookings created after this time.
 *
 * The output describes the newest matching booking (by `scheduled_at`, then
 * `id`).
 *
 * ## Test mode
 *
 * Reads for real, like the entitlement check. Only when no identifier is
 * given at all (a token that resolved to nothing in a test run) does it return
 * a preview.
 */
class CheckBookingAction implements AutomationAction
{
    public const MODE_REQUIRE = 'require';

    public const MODE_FORBID = 'forbid';

    /** Never stops: only reports `found` and `count`, for a branch node to split on. */
    public const MODE_REPORT = 'report';

    /** The statuses the booking addon counts as an active booking. */
    public const DEFAULT_STATUSES = 'booked,rescheduled';

    public static function handle(): string
    {
        return 'booking.check_booking';
    }

    public static function label(): string
    {
        return 'Check Booking';
    }

    public static function description(): ?string
    {
        return 'Looks up now whether a person holds a booking and ends the run when the answer is not the one required.';
    }

    public static function group(): string
    {
        return 'Booking';
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
                'required' => false,
                'tokenable' => true,
                'help' => 'Matches the booker\'s address, for example {{ booking.email }}. Either this or the booking id is needed.',
            ],
            [
                'handle' => 'external_id',
                'label' => 'Booking ID',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'The provider\'s id for the booking. Either this or the email is needed.',
            ],
            [
                'handle' => 'endpoint',
                'label' => 'Endpoint',
                'type' => 'text',
                'required' => false,
                'help' => 'Only bookings that came through this endpoint, for example coaching. Empty means every endpoint.',
            ],
            [
                'handle' => 'statuses',
                'label' => 'Statuses',
                'type' => 'text',
                'required' => false,
                'default' => self::DEFAULT_STATUSES,
                'help' => 'Comma-separated statuses that count. Active bookings are booked and rescheduled. requested, cancelled and rejected do not count by default.',
            ],
            [
                'handle' => 'created_after',
                'label' => 'Created after',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Only bookings created after this time, any text Carbon reads. Empty means no limit.',
            ],
            [
                'handle' => 'mode',
                'label' => 'Continue only if the person',
                'type' => 'select',
                'required' => true,
                'default' => self::MODE_REPORT,
                'options' => [
                    self::MODE_REQUIRE => 'has a matching booking',
                    self::MODE_FORBID => 'has no matching booking',
                    self::MODE_REPORT => 'any (just report, never stop)',
                ],
                'help' => 'When the condition does not hold, the run ends here. That is a stop, not a failure. "Just report" never stops: the node outputs found and count, and a branch node splits on them.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'found' => 'boolean',
            'count' => 'integer',
            'status' => 'string',
            'scheduled_at' => 'string',
            'external_id' => 'string',
            'endpoint' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $mode = trim((string) ($config['mode'] ?? self::MODE_REPORT)) ?: self::MODE_REPORT;

        if (! in_array($mode, [self::MODE_REQUIRE, self::MODE_FORBID, self::MODE_REPORT], true)) {
            return ActionResult::failed(sprintf('Unknown mode "%s": use "require", "forbid" or "report".', $mode));
        }

        $email = mb_strtolower(trim((string) ($config['email'] ?? '')));
        $externalId = trim((string) ($config['external_id'] ?? ''));
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $statuses = $this->statusesOf($config['statuses'] ?? null);
        $createdAfterText = trim((string) ($config['created_after'] ?? ''));

        // A test run starts from an empty context, so a token such as
        // `{{ booking.email }}` resolves to nothing there. With no identifier
        // at all there is nothing to read, and a preview says so.
        if ($email === '' && $externalId === '' && $context->isTestMode()) {
            return ActionResult::success([
                'preview' => ['mode' => $mode],
                'note' => 'Test mode — no email or booking id to look up.',
            ]);
        }

        if ($email === '' && $externalId === '') {
            return ActionResult::failed(
                'Either "email" or "external_id" is required, and the token did not resolve to a value in this run.',
                ['mode' => $mode],
            );
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failed('A valid "email" is required.', ['mode' => $mode]);
        }

        $createdAfter = null;

        if ($createdAfterText !== '') {
            try {
                $createdAfter = Carbon::parse($createdAfterText, config('app.timezone'));
            } catch (Throwable) {
                return ActionResult::failed(sprintf('"created_after" is not a readable time: "%s".', $createdAfterText), ['mode' => $mode]);
            }
        }

        $table = $this->tableName();

        try {
            if (! Schema::hasTable($table)) {
                return ActionResult::failed(
                    'statamic-booking is not installed or not migrated (table "'.$table.'" does not exist).',
                    ['mode' => $mode],
                );
            }

            $matching = function () use ($table, $email, $externalId, $endpoint, $statuses, $createdAfter) {
                $query = DB::table($table)->whereIn('status', $statuses);

                if ($email !== '') {
                    $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                }

                if ($externalId !== '') {
                    $query->where('external_id', $externalId);
                }

                if ($endpoint !== '') {
                    $query->where('endpoint', $endpoint);
                }

                if ($createdAfter !== null) {
                    $query->where('created_at', '>', $createdAfter->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
                }

                return $query;
            };

            $count = (int) $matching()->count();
            $newest = $count > 0
                ? $matching()->orderByDesc('scheduled_at')->orderByDesc('id')->first()
                : null;
        } catch (Throwable $e) {
            return ActionResult::failed('Reading the bookings failed: '.$e->getMessage(), ['mode' => $mode]);
        }

        $found = $count > 0;
        $who = $email !== '' ? $email : 'booking '.$externalId;

        $output = [
            'found' => $found,
            'count' => $count,
            'status' => $newest->status ?? null,
            'scheduled_at' => $this->isoOf($newest->scheduled_at ?? null),
            'external_id' => $newest->external_id ?? null,
            'endpoint' => $newest->endpoint ?? null,
        ];

        if ($mode === self::MODE_REQUIRE && ! $found) {
            return ActionResult::stopped(sprintf(
                '%s has no matching booking (statuses: %s), so the run ends here.',
                $who,
                implode(', ', $statuses),
            ));
        }

        if ($mode === self::MODE_FORBID && $found) {
            return ActionResult::stopped(sprintf(
                '%s already has %d matching booking(s), so the run ends here.',
                $who,
                $count,
            ));
        }

        return ActionResult::success($output);
    }

    /**
     * The table the booking addon owns, read the way its model reads it.
     */
    protected function tableName(): string
    {
        $table = config('statamic-booking.table');

        return is_string($table) && $table !== '' ? $table : 'bookings';
    }

    /**
     * @return list<string>
     */
    protected function statusesOf(mixed $value): array
    {
        $text = is_string($value) && trim($value) !== '' ? $value : self::DEFAULT_STATUSES;

        $statuses = array_values(array_unique(array_filter(array_map(
            fn (string $status) => strtolower(trim($status)),
            explode(',', $text),
        ))));

        return $statuses === [] ? explode(',', self::DEFAULT_STATUSES) : $statuses;
    }

    protected function isoOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'))->toIso8601String();
    }
}
