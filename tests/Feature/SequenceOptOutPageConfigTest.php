<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Integrations\Marketing\MarketingAdapter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
use Goldnead\StatamicAutomations\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The landing page of the series opt-out can be worded per automation.
 *
 * `automations.sequence_opt_out.pages.<handle>` holds optional `title`, `body`,
 * `button`, `done`, `already_out`, `scope_note`, `link_label` and `link_url`.
 * What is missing falls back to the language file, so a site that sets nothing
 * gets the page it always had.
 */
class SequenceOptOutPageConfigTest extends TestCase
{
    private Automation $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flow = Automation::create(['name' => 'Willkommensstrecke', 'handle' => 'welcome', 'enabled' => true]);

        $brand = (int) ($this->flow->brand_id ?? 0);

        // The subscription belongs to the marketing addon, which is not a
        // dependency here: stand in for the one lookup the page makes.
        $this->app->instance(MarketingAdapter::class, new class($brand) extends MarketingAdapter
        {
            public function __construct(private int $brand) {}

            public function subscriptionForToken(string $token): ?array
            {
                return $token === 'tok' ? ['email' => 'clara@example.test', 'brand_id' => $this->brand] : null;
            }
        });

        app()->setLocale('en');
    }

    private function page(): string
    {
        return $this->get("/!/automations/serie/tok/{$this->flow->uuid}")->assertOk()->getContent();
    }

    /**
     * @param  array<string, string>  $page
     */
    private function configure(array $page, string $handle = 'welcome'): void
    {
        config()->set('automations.sequence_opt_out.pages', [$handle => $page]);
    }

    public function test_without_config_the_page_is_what_it_always_was(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<title>Unsubscribe from this series</title>', $html);
        $this->assertStringContainsString('<h1>Willkommensstrecke</h1>', $html);
        $this->assertStringContainsString('You receive this series at clara@example.test. You can stop it here.', $html);
        $this->assertStringContainsString('Stop this series', $html);
        $this->assertStringContainsString('This affects this series only.', $html);
        $this->assertStringNotContainsString('class="back"', $html);
    }

    public function test_every_key_overrides_its_text(): void
    {
        $this->configure([
            'title' => 'Dein Weg mit uns',
            'body' => 'Die Serie läuft an :email. Beenden geht hier.',
            'button' => 'Nicht mehr senden',
            'scope_note' => 'Nur diese Mails, nichts anderes.',
            'link_label' => 'Zurück zur Startseite',
            'link_url' => '/start',
        ]);

        $html = $this->page();

        $this->assertStringContainsString('<title>Dein Weg mit uns</title>', $html);
        $this->assertStringContainsString('<h1>Dein Weg mit uns</h1>', $html);
        $this->assertStringContainsString('Die Serie läuft an clara@example.test. Beenden geht hier.', $html);
        $this->assertStringContainsString('Nicht mehr senden', $html);
        $this->assertStringContainsString('Nur diese Mails, nichts anderes.', $html);
        $this->assertStringContainsString('<a href="/start">Zurück zur Startseite</a>', $html);
        $this->assertStringNotContainsString('Stop this series', $html);
        $this->assertStringNotContainsString('This affects this series only.', $html);
    }

    public function test_a_key_that_is_missing_keeps_the_old_text(): void
    {
        $this->configure(['button' => 'Nicht mehr senden']);

        $html = $this->page();

        $this->assertStringContainsString('Nicht mehr senden', $html);
        $this->assertStringContainsString('You receive this series at clara@example.test. You can stop it here.', $html);
        $this->assertStringContainsString('<title>Unsubscribe from this series</title>', $html);
    }

    public function test_an_empty_value_counts_as_missing(): void
    {
        $this->configure(['button' => '  ', 'body' => '']);

        $html = $this->page();

        $this->assertStringContainsString('Stop this series', $html);
        $this->assertStringContainsString('You receive this series at', $html);
    }

    public function test_the_config_of_another_automation_does_not_leak(): void
    {
        $this->configure(['button' => 'Falsche Serie', 'link_url' => '/falsch'], handle: 'something-else');

        $html = $this->page();

        $this->assertStringContainsString('Stop this series', $html);
        $this->assertStringNotContainsString('Falsche Serie', $html);
        $this->assertStringNotContainsString('/falsch', $html);
    }

    public function test_after_the_opt_out_the_done_text_and_the_link_are_shown(): void
    {
        $this->configure([
            'done' => 'Alles klar, du bist raus.',
            'link_label' => 'Weiter zum Blog',
            'link_url' => 'https://example.test/blog',
        ]);

        $this->post("/!/automations/serie/tok/{$this->flow->uuid}")->assertRedirect();

        $html = $this->page();

        $this->assertTrue(app(SequenceOptOut::class)->has((string) $this->flow->uuid, 'clara@example.test'));
        $this->assertStringContainsString('Alles klar, du bist raus.', $html);
        $this->assertStringContainsString('<a href="https://example.test/blog">Weiter zum Blog</a>', $html);
    }

    public function test_the_already_out_text_is_configurable_and_the_link_stays(): void
    {
        app(SequenceOptOut::class)->add((string) $this->flow->uuid, 'clara@example.test');
        $this->configure([
            'already_out' => 'Du bist schon abgemeldet (:email).',
            'link_url' => '/start',
        ]);

        $html = $this->page();

        $this->assertStringContainsString('Du bist schon abgemeldet (clara@example.test).', $html);
        $this->assertStringContainsString('href="/start"', $html);
        $this->assertStringNotContainsString('You no longer receive this series', $html);
    }

    public function test_a_link_without_a_label_gets_the_default_label(): void
    {
        $this->configure(['link_url' => '/start']);

        $this->assertStringContainsString('<a href="/start">Back to the website</a>', $this->page());
    }

    public function test_a_label_without_a_url_shows_no_link(): void
    {
        $this->configure(['link_label' => 'Zurück']);

        $this->assertStringNotContainsString('Zurück', $this->page());
    }

    #[DataProvider('unsafeUrls')]
    public function test_only_a_relative_path_or_an_https_url_becomes_a_link(string $url): void
    {
        $this->configure(['link_url' => $url, 'link_label' => 'Gefährlich']);

        $html = $this->page();

        $this->assertStringNotContainsString('Gefährlich', $html);
        $this->assertStringNotContainsString('class="back"', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'plain http' => ['http://example.test/'],
            'protocol relative' => ['//evil.test/'],
            'data' => ['data:text/html,<b>x</b>'],
            'bare word' => ['example.test/start'],
            'backslash trick' => ['/\\evil.test'],
            'with a space' => ['/a b'],
            'control character' => ["/sta\nrt"],
        ];
    }

    public function test_html_in_the_config_is_escaped_not_rendered(): void
    {
        $this->configure(['button' => '<script>alert(1)</script>', 'link_url' => '/a"onmouseover="x', 'link_label' => '<b>x</b>']);

        $html = $this->page();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('onmouseover="x"', $html);
    }

    public function test_the_handle_may_contain_dots(): void
    {
        // Dot notation would split it; the lookup must not.
        $flow = Automation::create(['name' => 'Dotted', 'handle' => 'v1.welcome', 'enabled' => true]);
        $this->flow = $flow;
        $this->configure(['button' => 'Mit Punkt'], handle: 'v1.welcome');

        $this->assertStringContainsString('Mit Punkt', $this->page());
    }
}
