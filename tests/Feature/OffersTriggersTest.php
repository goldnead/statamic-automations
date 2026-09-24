<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\Invoices\Triggers\InvoiceDeliveredTrigger;
use Goldnead\StatamicAutomations\Integrations\Offers\Triggers as OT;
use Goldnead\StatamicAutomations\Listeners\HandleCommerceEvent;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Goldnead\StatamicOffers\Events as OE;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/OffersEventStubs.php';

/**
 * W7: the offers addon's events (seats, sold out, coupon, link switch) and
 * `InvoiceDelivered` as triggers, under the same handles as their webhooks.
 *
 * Same stance as the other suite trigger tests: the map, the registration,
 * context = output schema, the filters, the brand, and the whole path from a
 * dispatched event to a started run.
 */
class OffersTriggersTest extends TestCase
{
    private const FORCED = ['offers', 'invoices', 'payments'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        IntegrationDetector::flush();

        foreach (self::FORCED as $integration) {
            $app['config']->set("automations.integrations.{$integration}.detect", [self::class]);
        }
    }

    protected function tearDown(): void
    {
        app('brand-context')->forget();
        IntegrationDetector::flush();

        parent::tearDown();
    }

    public function test_every_offers_event_maps_to_the_webhook_handle(): void
    {
        $this->assertSame([
            'Goldnead\\StatamicOffers\\Events\\SeatPoolOpened' => 'offers.seat_pool_opened',
            'Goldnead\\StatamicOffers\\Events\\SeatInvited' => 'offers.seat_invited',
            'Goldnead\\StatamicOffers\\Events\\SeatAccepted' => 'offers.seat_accepted',
            'Goldnead\\StatamicOffers\\Events\\SeatRevoked' => 'offers.seat_revoked',
            'Goldnead\\StatamicOffers\\Events\\SeatPoolClosed' => 'offers.seat_pool_closed',
            'Goldnead\\StatamicOffers\\Events\\OfferSoldOut' => 'offers.sold_out',
            'Goldnead\\StatamicOffers\\Events\\CouponRedeemed' => 'offers.coupon_redeemed',
            'Goldnead\\StatamicOffers\\Events\\ShortLinkSwitched' => 'offers.link_switched',
        ], HandleCommerceEvent::OFFER_TRIGGERS);

        $this->assertSame('invoices.delivered', HandleCommerceEvent::INVOICE_TRIGGERS['Goldnead\\Invoices\\Events\\InvoiceDelivered'] ?? null);
    }

    public function test_the_triggers_are_registered_and_flatten_to_their_schema(): void
    {
        $nodes = app(NodeRegistry::class);

        foreach ($this->triggers() as $class => $event) {
            $this->assertTrue($nodes->has($class::handle()), $class::handle());

            $context = (new $class)->buildContext($event, [])->all();
            $schema = $class::outputSchema();

            $this->assertSame(array_keys($schema), array_keys($context), $class::handle());

            foreach ($schema as $key => $fragment) {
                if (is_array($fragment) && is_array($context[$key]) && $context[$key] !== []) {
                    $this->assertSame(array_keys($fragment), array_keys($context[$key]), $class::handle().' '.$key);
                }
            }

            $this->assertTrue((new $class)->matches(new \stdClass, []), $class::handle());
        }
    }

    public function test_no_token_reaches_a_run_context(): void
    {
        foreach ($this->triggers() as $class => $event) {
            $json = json_encode((new $class)->buildContext($event, [])->all());

            $this->assertStringNotContainsString('token', $json, $class::handle());
            $this->assertStringNotContainsString('geheim', $json, $class::handle());
        }
    }

    public function test_the_offer_filter_reads_the_offer_or_the_pool(): void
    {
        $pool = $this->pool();

        $this->assertTrue((new OT\SeatInvitedTrigger)->matches(new OE\SeatInvited($this->seat(), $pool), ['offer' => 'team-kurs']));
        $this->assertFalse((new OT\SeatInvitedTrigger)->matches(new OE\SeatInvited($this->seat(), $pool), ['offer' => 'anderes']));
        $this->assertTrue((new OT\OfferSoldOutTrigger)->matches(new OE\OfferSoldOut($this->offer(), 20), ['offer' => 'team-kurs']));
        $this->assertFalse((new OT\OfferSoldOutTrigger)->matches(new OE\OfferSoldOut($this->offer(), 20), ['offer' => 'x']));
    }

    public function test_the_coupon_filter_takes_the_code_in_any_case(): void
    {
        $trigger = new OT\CouponRedeemedTrigger;
        $event = new OE\CouponRedeemed($this->coupon(), $this->payment());

        $this->assertTrue($trigger->matches($event, ['code' => 'fruehling25']));
        $this->assertTrue($trigger->matches($event, ['code' => ' FRUEHLING25 ']));
        $this->assertFalse($trigger->matches($event, ['code' => 'SOMMER']));
    }

    public function test_the_link_switch_filters_by_reason(): void
    {
        $trigger = new OT\ShortLinkSwitchedTrigger;
        $event = new OE\ShortLinkSwitched($this->offer(), 'sold_out');

        $this->assertTrue($trigger->matches($event, ['reason' => 'sold_out']));
        $this->assertFalse($trigger->matches($event, ['reason' => 'date']));
        $this->assertSame('/ausverkauft', $trigger->buildContext($event, [])->get('link.fallback'));
    }

    public function test_the_person_of_each_run_is_the_one_it_is_about(): void
    {
        // A seat mail goes to the seat, a pool mail to the buyer who owns it,
        // a coupon mail to the buyer.
        $this->assertSame('sopran@example.com', (new OT\SeatInvitedTrigger)->buildContext(new OE\SeatInvited($this->seat(), $this->pool()), [])->get('email'));
        $this->assertSame('chorleitung@example.com', (new OT\SeatPoolOpenedTrigger)->buildContext(new OE\SeatPoolOpened($this->pool()), [])->get('email'));
        $this->assertSame('kaeuferin@example.com', (new OT\CouponRedeemedTrigger)->buildContext(new OE\CouponRedeemed($this->coupon(), $this->payment()), [])->get('email'));
        $this->assertSame('kaeuferin@example.com', (new InvoiceDeliveredTrigger)->buildContext(new InvoiceDelivered((object) ['id' => 1, 'number' => 'RE-1', 'brand_id' => 0], 'kaeuferin@example.com'), [])->get('email'));
    }

    public function test_a_seat_event_starts_a_run_in_the_brand_of_the_pool(): void
    {
        Queue::fake();
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $brandA = $this->brand('a');
        $brandB = $this->brand('b');

        $mine = $this->automationIn($brandB, 'offers.seat_accepted', ['offer' => 'team-kurs']);
        $wrong = $this->automationIn($brandA, 'offers.seat_accepted', []);

        event(new OE\SeatAccepted($this->seat(), $this->pool(brand: $brandB)));

        $this->assertCount(1, $this->runsOf($mine));
        $this->assertCount(0, $this->runsOf($wrong));
    }

    public function test_an_invoice_delivery_and_a_coupon_start_runs(): void
    {
        Queue::fake();

        $delivered = $this->automationIn(null, 'invoices.delivered', []);
        $coupon = $this->automationIn(null, 'offers.coupon_redeemed', ['code' => 'FRUEHLING25']);

        event(new InvoiceDelivered((object) ['id' => 1, 'number' => 'RE-1'], 'kaeuferin@example.com'));
        event(new OE\CouponRedeemed($this->coupon(), $this->payment()));

        $this->assertCount(1, $this->runsOf($delivered));
        $this->assertCount(1, $this->runsOf($coupon));
    }

    public function test_a_redelivered_coupon_redemption_starts_one_run_per_payment(): void
    {
        Queue::fake();

        // No re-entry setting on the node: the trigger's own default applies.
        $flow = $this->automationIn(null, 'offers.coupon_redeemed', []);

        event(new OE\CouponRedeemed($this->coupon(), $this->payment()));
        event(new OE\CouponRedeemed($this->coupon(), $this->payment()));

        $runs = $this->runsOf($flow);
        $this->assertCount(1, $runs);
        $this->assertSame('30', $runs->first()->subject_key);

        // Another payment by the same buyer is another redemption.
        $second = $this->payment();
        $second->id = 31;
        event(new OE\CouponRedeemed($this->coupon(), $second));

        $this->assertCount(2, $this->runsOf($flow));
    }

    public function test_an_explicit_always_on_the_node_still_wins(): void
    {
        Queue::fake();

        $flow = $this->automationIn(null, 'offers.coupon_redeemed', ['_restart_policy' => 'always']);

        event(new OE\CouponRedeemed($this->coupon(), $this->payment()));
        event(new OE\CouponRedeemed($this->coupon(), $this->payment()));

        $this->assertCount(2, $this->runsOf($flow));
    }

    public function test_the_editor_shows_the_coupon_defaults(): void
    {
        $fields = collect(app(NodeRegistry::class)->describe('offers.coupon_redeemed')['schema'])->keyBy('handle');

        $this->assertSame('ignore', $fields['_restart_policy']['default']);
        $this->assertSame('{{ payment.id }}', $fields['_subject_key']['default']);
    }

    public function test_the_german_labels_exist(): void
    {
        $german = require __DIR__.'/../../resources/lang/de/triggers.php';

        foreach (array_merge(array_keys($this->triggers())) as $class) {
            $this->assertIsString(data_get($german, $class::handle().'.label'), $class::handle());
            $this->assertIsString(data_get($german, $class::handle().'.description'), $class::handle());
            $this->assertArrayHasKey($class::group(), $german['groups'], $class::handle());
        }
    }

    // --- fixtures -----------------------------------------------------------

    /** @return array<class-string, object> */
    private function triggers(): array
    {
        return [
            OT\SeatPoolOpenedTrigger::class => new OE\SeatPoolOpened($this->pool()),
            OT\SeatInvitedTrigger::class => new OE\SeatInvited($this->seat(), $this->pool()),
            OT\SeatAcceptedTrigger::class => new OE\SeatAccepted($this->seat(), $this->pool()),
            OT\SeatRevokedTrigger::class => new OE\SeatRevoked($this->seat(), $this->pool(), 'claimed', 'owner'),
            OT\SeatPoolClosedTrigger::class => new OE\SeatPoolClosed($this->pool(), 'refunded'),
            OT\OfferSoldOutTrigger::class => new OE\OfferSoldOut($this->offer(), 20),
            OT\CouponRedeemedTrigger::class => new OE\CouponRedeemed($this->coupon(), $this->payment()),
            OT\ShortLinkSwitchedTrigger::class => new OE\ShortLinkSwitched($this->offer(), 'date'),
            InvoiceDeliveredTrigger::class => new InvoiceDelivered((object) ['id' => 1, 'number' => 'RE-1', 'kind' => 'invoice'], 'kaeuferin@example.com'),
        ];
    }

    private function offer(): object
    {
        return (object) [
            'id' => 4, 'handle' => 'team-kurs', 'name' => 'Team-Kurs', 'brand_id' => 0, 'quantity_limit' => 20,
            'link_slug' => 'team', 'link_target' => '/kasse/team', 'link_fallback' => '/ausverkauft',
            'link_switch_at' => Carbon::parse('2026-10-01 00:00:00'),
        ];
    }

    private function pool(int $brand = 0): object
    {
        $offer = $this->offer();

        return new class($offer, $brand)
        {
            public int $id = 7;

            public string $offer = 'team-kurs';

            public string $product = 'offer:team-kurs';

            public int $seats = 5;

            public string $owner_email = 'chorleitung@example.com';

            public string $owner_name = 'Chorleitung';

            public int $payment_id = 30;

            public ?Carbon $closed_at = null;

            public string $manage_token = 'geheim-token';

            public function __construct(private object $model, public int $brand_id) {}

            public function offerModel(): object
            {
                return $this->model;
            }

            public function takenCount(): int
            {
                return 2;
            }
        };
    }

    private function seat(): object
    {
        return (object) [
            'id' => 11, 'email' => 'sopran@example.com', 'name' => 'Sophie', 'status' => 'claimed',
            'invited_at' => Carbon::parse('2026-09-20'), 'claimed_at' => Carbon::parse('2026-09-21'), 'revoked_at' => null,
            'token' => 'geheim-token',
        ];
    }

    private function coupon(): object
    {
        return (object) ['id' => 3, 'code' => 'FRUEHLING25', 'name' => 'Frühling', 'percent' => 25, 'amount_cent' => null, 'currency' => null];
    }

    private function payment(): object
    {
        return (object) [
            'id' => 30, 'product' => 'offer:team-kurs', 'amount_cent' => 7500, 'currency' => 'EUR', 'discount_code' => 'FRUEHLING25',
            'discount_cent' => 2500, 'status' => 'paid', 'email' => 'kaeuferin@example.com', 'name' => 'Käuferin', 'provider' => 'mollie', 'brand_id' => 0,
        ];
    }

    private function brand(string $handle): int
    {
        return DB::table('brands')->insertGetId(['handle' => $handle, 'name' => $handle, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $config */
    private function automationIn(?int $brand, string $handle, array $config): Automation
    {
        $make = function () use ($handle, $config) {
            $automation = Automation::create(['name' => "On {$handle}", 'handle' => 'on-'.str_replace('.', '-', $handle).'-'.bin2hex(random_bytes(4)), 'enabled' => true]);
            AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => $handle, 'config' => $config]);
            AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'fired']]);
            AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'log']);

            return $automation;
        };

        return $brand === null ? $make() : app('brand-context')->runFor($brand, $make);
    }

    /** @return Collection<int, AutomationRun> */
    private function runsOf(Automation $automation): Collection
    {
        return app('brand-context')->withoutBrandScope(fn () => AutomationRun::query()->where('automation_id', $automation->id)->get());
    }
}
