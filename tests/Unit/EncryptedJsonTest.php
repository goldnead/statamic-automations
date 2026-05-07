<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Casts\EncryptedJson;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EncryptedJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    public function test_stores_plain_json_when_encryption_is_disabled(): void
    {
        config()->set('automations.runs.encrypt_context', false);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'status' => 'queued',
            'context' => ['form' => ['email' => 'plain@example.com']],
        ]);

        $raw = $run->getRawOriginal('context');
        $this->assertJson($raw);
        $this->assertStringContainsString('plain@example.com', $raw);
        $this->assertStringNotContainsString(EncryptedJson::ENVELOPE_KEY, $raw);

        $this->assertSame('plain@example.com', $run->fresh()->context['form']['email']);
    }

    public function test_encrypts_at_rest_when_flag_enabled(): void
    {
        config()->set('automations.runs.encrypt_context', true);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'status' => 'queued',
            'context' => ['form' => ['email' => 'sensitive@example.com']],
        ]);

        $raw = $run->getRawOriginal('context');
        $this->assertJson($raw);
        $this->assertStringContainsString(EncryptedJson::ENVELOPE_KEY, $raw);
        $this->assertStringNotContainsString('sensitive@example.com', $raw);

        // Read still returns plain values transparently.
        $this->assertSame('sensitive@example.com', $run->fresh()->context['form']['email']);
    }

    public function test_reads_legacy_unencrypted_rows_after_flag_is_enabled(): void
    {
        // Write plain (flag off) …
        config()->set('automations.runs.encrypt_context', false);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'status' => 'queued',
            'context' => ['legacy' => true],
        ]);

        // …then turn the flag on.
        config()->set('automations.runs.encrypt_context', true);

        $this->assertSame(['legacy' => true], $run->fresh()->context);
    }

    public function test_null_context_round_trips(): void
    {
        config()->set('automations.runs.encrypt_context', true);

        $automation = Automation::create(['name' => 'A', 'handle' => 'a']);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'status' => 'queued',
            'context' => null,
        ]);

        $this->assertNull($run->fresh()->context);
    }
}
