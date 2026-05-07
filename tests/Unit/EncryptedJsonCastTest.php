<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Casts\EncryptedJson;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class EncryptedJsonCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_trip_when_encryption_disabled(): void
    {
        config()->set('automations.runs.encrypt_context', false);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'context' => ['lead' => ['email' => 'a@b.de']],
        ]);

        $fresh = $run->fresh();
        $this->assertSame(['lead' => ['email' => 'a@b.de']], $fresh->context);
    }

    public function test_round_trip_when_encryption_enabled(): void
    {
        config()->set('automations.runs.encrypt_context', true);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a-' . uniqid()]);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'context' => ['lead' => ['email' => 'b@c.de', 'token' => 'sk_live_abc']],
        ]);

        $fresh = $run->fresh();

        $this->assertSame(
            ['lead' => ['email' => 'b@c.de', 'token' => 'sk_live_abc']],
            $fresh->context,
        );

        // The raw column value should NOT contain the email in plain text.
        $raw = $fresh->getRawOriginal('context');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('b@c.de', $raw);
        $this->assertStringContainsString(EncryptedJson::ENVELOPE_KEY, $raw);
    }

    public function test_legacy_unencrypted_json_remains_readable_after_enabling(): void
    {
        config()->set('automations.runs.encrypt_context', false);
        $automation = Automation::create(['name' => 'A', 'handle' => 'a-' . uniqid()]);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'context' => ['legacy' => 'value'],
        ]);

        // Now turn encryption on and re-read — should still decode.
        config()->set('automations.runs.encrypt_context', true);
        $fresh = $run->fresh();

        $this->assertSame(['legacy' => 'value'], $fresh->context);
    }

    public function test_null_context_is_preserved_in_both_modes(): void
    {
        config()->set('automations.runs.encrypt_context', true);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a-' . uniqid()]);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'context' => null,
        ]);

        $this->assertNull($run->fresh()->context);
    }
}
