<?php

/**
 * booking.check_booking: a gate that reads the booking addon's table.
 *
 * The addon is not installed in this test app, so the table is created here
 * with the columns of its migration, and every test works on real rows. The
 * node never writes; the tests only insert rows to read back.
 */

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\Booking\Actions\CheckBookingAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the node once. Defaults: report mode, active statuses, no filters.
 *
 * @param  array<string, mixed>  $config
 */
function checkBookingRun(array $config, bool $testMode = false): ActionResult
{
    return app(CheckBookingAction::class)->execute(
        AutomationContext::make([], testMode: $testMode),
        array_merge([
            'mode' => CheckBookingAction::MODE_REPORT,
            'email' => '',
            'external_id' => '',
            'endpoint' => '',
            'statuses' => 'booked,rescheduled',
            'created_after' => '',
        ], $config),
    );
}

/**
 * One row as the addon writes it. Override any column.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkBookingRow(array $overrides = []): array
{
    return array_merge([
        'endpoint' => 'coaching',
        'external_id' => 'ext-'.uniqid(),
        'status' => 'booked',
        'scheduled_at' => '2026-11-02 10:00:00',
        'timezone' => 'Europe/Berlin',
        'duration_minutes' => 60,
        'name' => 'Anna Beispiel',
        'email' => 'anna@example.com',
        'meeting_url' => null,
        'meta' => null,
        'cancelled_at' => null,
        'created_at' => '2026-10-19 09:00:00',
        'updated_at' => '2026-10-19 09:00:00',
    ], $overrides);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20 12:00:00');

    // The table of the booking addon, with the columns of its migration.
    Schema::create('bookings', function (Blueprint $table): void {
        $table->id();
        $table->string('endpoint', 64)->index();
        $table->string('external_id', 191);
        $table->string('status', 32)->default('booked');
        $table->timestamp('scheduled_at')->nullable()->index();
        $table->string('timezone', 64)->nullable();
        $table->unsignedInteger('duration_minutes')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('meeting_url')->nullable();
        $table->json('meta')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->timestamps();

        $table->unique(['endpoint', 'external_id']);
    });
});

afterEach(function (): void {
    Schema::dropIfExists('bookings');
    Carbon::setTestNow();
});

it('finds a booking by the booker\'s email and reports its details', function (): void {
    DB::table('bookings')->insert(checkBookingRow([
        'external_id' => 'cal-123',
        'endpoint' => 'coaching',
        'scheduled_at' => '2026-11-02 10:00:00',
    ]));

    $result = checkBookingRun(['email' => 'anna@example.com']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['found'])->toBeTrue();
    expect($result->output['count'])->toBe(1);
    expect($result->output['status'])->toBe('booked');
    expect($result->output['external_id'])->toBe('cal-123');
    expect($result->output['endpoint'])->toBe('coaching');
    expect($result->output['scheduled_at'])->toBe(
        Carbon::parse('2026-11-02 10:00:00', config('app.timezone'))->toIso8601String(),
    );
});

it('finds a booking by its provider id', function (): void {
    DB::table('bookings')->insert(checkBookingRow(['external_id' => 'cal-777', 'email' => 'other@example.com']));

    $result = checkBookingRun(['external_id' => 'cal-777']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['found'])->toBeTrue();
    expect($result->output['external_id'])->toBe('cal-777');
});

it('only counts bookings through the endpoint it is asked for', function (): void {
    DB::table('bookings')->insert([
        checkBookingRow(['endpoint' => 'free-call', 'external_id' => 'a']),
        checkBookingRow(['endpoint' => 'coaching', 'external_id' => 'b']),
    ]);

    $result = checkBookingRun(['email' => 'anna@example.com', 'endpoint' => 'coaching']);

    expect($result->output['count'])->toBe(1);
    expect($result->output['external_id'])->toBe('b');
    expect($result->output['endpoint'])->toBe('coaching');
});

it('does not count a cancelled booking unless cancelled is one of the statuses', function (): void {
    DB::table('bookings')->insert(checkBookingRow([
        'status' => 'cancelled',
        'cancelled_at' => '2026-10-19 18:00:00',
    ]));

    $default = checkBookingRun(['email' => 'anna@example.com']);
    expect($default->isSuccess())->toBeTrue();
    expect($default->output['found'])->toBeFalse();
    expect($default->output['count'])->toBe(0);

    $withCancelled = checkBookingRun(['email' => 'anna@example.com', 'statuses' => 'booked,cancelled']);
    expect($withCancelled->output['found'])->toBeTrue();
    expect($withCancelled->output['status'])->toBe('cancelled');
});

it('only counts bookings created after the given time', function (): void {
    DB::table('bookings')->insert([
        checkBookingRow(['external_id' => 'old', 'created_at' => '2026-10-01 09:00:00']),
        checkBookingRow(['external_id' => 'new', 'created_at' => '2026-10-19 09:00:00']),
    ]);

    $result = checkBookingRun(['email' => 'anna@example.com', 'created_after' => '2026-10-10']);

    expect($result->output['count'])->toBe(1);
    expect($result->output['external_id'])->toBe('new');
});

it('matches the email without regard to case and surrounding spaces', function (): void {
    DB::table('bookings')->insert(checkBookingRow(['email' => '  Anna@Example.COM ']));

    $result = checkBookingRun(['email' => '  anna@example.com ']);

    expect($result->output['found'])->toBeTrue();
});

it('require stops the run when there is no matching booking', function (): void {
    $result = checkBookingRun(['email' => 'nobody@example.com', 'mode' => CheckBookingAction::MODE_REQUIRE]);

    expect($result->status)->toBe('stopped');
    expect($result->output['reason'])->toContain('The person has no matching booking');
    expect($result->output['reason'])->not->toContain('nobody@example.com');
});

it('require lets the run go on when a booking matches', function (): void {
    DB::table('bookings')->insert(checkBookingRow());

    $result = checkBookingRun(['email' => 'anna@example.com', 'mode' => CheckBookingAction::MODE_REQUIRE]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['found'])->toBeTrue();
});

it('forbid stops the run when a booking matches', function (): void {
    DB::table('bookings')->insert(checkBookingRow());

    $result = checkBookingRun(['email' => 'anna@example.com', 'mode' => CheckBookingAction::MODE_FORBID]);

    expect($result->status)->toBe('stopped');
    expect($result->output['reason'])->toContain('The person already has 1 matching booking');
    expect($result->output['reason'])->not->toContain('anna@example.com');
});

it('report never stops, with or without a match', function (): void {
    $without = checkBookingRun(['email' => 'nobody@example.com', 'mode' => CheckBookingAction::MODE_REPORT]);
    expect($without->isSuccess())->toBeTrue();
    expect($without->output['found'])->toBeFalse();

    DB::table('bookings')->insert(checkBookingRow());

    $with = checkBookingRun(['email' => 'anna@example.com', 'mode' => CheckBookingAction::MODE_REPORT]);
    expect($with->isSuccess())->toBeTrue();
    expect($with->output['found'])->toBeTrue();
});

it('fails with a clear message when statamic-booking is not installed', function (): void {
    Schema::dropIfExists('bookings');

    $result = checkBookingRun(['email' => 'anna@example.com', 'mode' => CheckBookingAction::MODE_FORBID]);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('statamic-booking is not installed or not migrated');
});

it('fails when neither email nor external_id is given', function (): void {
    $result = checkBookingRun(['mode' => CheckBookingAction::MODE_FORBID]);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('"email" or "external_id"');
});

it('a test run reads for real, so a require can pass on a real row', function (): void {
    DB::table('bookings')->insert(checkBookingRow());

    $result = checkBookingRun(
        ['email' => 'anna@example.com', 'mode' => CheckBookingAction::MODE_REQUIRE],
        testMode: true,
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['found'])->toBeTrue();
});

it('a test run without any identifier previews instead of failing', function (): void {
    $result = checkBookingRun(['mode' => CheckBookingAction::MODE_FORBID], testMode: true);

    expect($result->isSuccess())->toBeTrue();
});

it('reports the newest matching booking', function (): void {
    DB::table('bookings')->insert([
        checkBookingRow(['external_id' => 'early', 'scheduled_at' => '2026-11-01 10:00:00']),
        checkBookingRow(['external_id' => 'late', 'scheduled_at' => '2026-11-09 10:00:00']),
    ]);

    $result = checkBookingRun(['email' => 'anna@example.com']);

    expect($result->output['count'])->toBe(2);
    expect($result->output['external_id'])->toBe('late');
});

it('needs the email and the booking id to match together', function (): void {
    DB::table('bookings')->insert(checkBookingRow(['external_id' => 'cal-1', 'email' => 'anna@example.com']));

    $bothMatch = checkBookingRun(['email' => 'anna@example.com', 'external_id' => 'cal-1']);
    $idOnly = checkBookingRun(['email' => 'other@example.com', 'external_id' => 'cal-1']);
    $emailOnly = checkBookingRun(['email' => 'anna@example.com', 'external_id' => 'cal-2']);

    expect($bothMatch->output['found'])->toBeTrue();
    expect($idOnly->output['found'])->toBeFalse();
    expect($emailOnly->output['found'])->toBeFalse();
});

it('fails on an invalid email instead of looking it up', function (): void {
    DB::table('bookings')->insert(checkBookingRow());

    $result = checkBookingRun(['email' => 'anna@', 'mode' => CheckBookingAction::MODE_REQUIRE]);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toBe('A valid "email" is required.');
});

it('accepts an address with umlauts', function (): void {
    DB::table('bookings')->insert(checkBookingRow(['email' => 'jörg@example.com']));

    $result = checkBookingRun(['email' => 'jörg@example.com']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['found'])->toBeTrue();
});

it('reads created_after in app.timezone, and an explicit zone in the argument is honoured', function (): void {
    config(['app.timezone' => 'UTC']);

    // 12:00 UTC is the boundary. 14:00 in Berlin on 10 October (summer time, UTC+2)
    // is the same instant.
    DB::table('bookings')->insert([
        checkBookingRow(['external_id' => 'just-before', 'created_at' => '2026-10-10 11:59:00']),
        checkBookingRow(['external_id' => 'just-after', 'created_at' => '2026-10-10 12:01:00']),
    ]);

    $utc = checkBookingRun(['email' => 'anna@example.com', 'created_after' => '2026-10-10 12:00:00']);
    $berlin = checkBookingRun(['email' => 'anna@example.com', 'created_after' => '2026-10-10 14:00:00 Europe/Berlin']);

    expect($utc->output['count'])->toBe(1);
    expect($utc->output['external_id'])->toBe('just-after');
    expect($berlin->output['count'])->toBe(1);
    expect($berlin->output['external_id'])->toBe('just-after');
});

it('a read error names its class and SQLSTATE, never the address it was reading for', function (): void {
    // A table without the status column: the query fails and carries the bound email.
    Schema::dropIfExists('bookings');
    Schema::create('bookings', function (Blueprint $table): void {
        $table->id();
    });

    $result = checkBookingRun(['email' => 'anna@example.com', 'mode' => CheckBookingAction::MODE_FORBID]);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toStartWith('Reading the bookings failed (Illuminate\Database\QueryException, SQLSTATE ');
    expect($result->error)->not->toContain('anna@example.com');
});
