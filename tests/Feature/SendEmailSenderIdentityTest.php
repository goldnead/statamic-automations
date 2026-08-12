<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\SenderIdentityResolver;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Sending\SaidRecently;
use Goldnead\StatamicAutomations\Sending\SenderIdentity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who a `send_email` node sends as, and over which transport.
 *
 * `Mail::fake()` is deliberately NOT used here. The fake records that something
 * was sent but never renders it, and the From is written during the render — so
 * it can prove the transport and not the sender, which is exactly one half of
 * the bug. Each brand gets its own `array` transport instead, and the
 * assertions read the real MIME message out of whichever one accepted it.
 *
 * The bug: the node called `Mail::html()`/`Mail::raw()`, so the transport was
 * always `config('mail.default')`, and the only From it ever set was the one
 * typed into the node. On a multi-brand host that pairs one brand's address
 * with another brand's relay account — a nurture sequence addressed as
 * `hallo@familystack.de` leaving through the project that verifies
 * `gldnr.studio`, where the provider refuses it or substitutes its own.
 */
beforeEach(function (): void {
    SaidRecently::forget();

    config()->set('mail.mailers.marke_a', ['transport' => 'array']);
    config()->set('mail.mailers.marke_b', ['transport' => 'array']);
    config()->set('mail.mailers.global', ['transport' => 'array']);
    config()->set('mail.default', 'global');
    config()->set('mail.from', ['address' => 'global@example.com', 'name' => 'Global']);

    $this->mails = fn (string $mailer) => collect(Mail::mailer($mailer)->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->values()
        ->all();

    $this->run = fn (array $config = []) => (new SendEmailAction)->execute(
        AutomationContext::make([]),
        array_merge(['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'plain'], $config),
    );
});

/**
 * The line that keeps this addon installable outside the host it was written
 * for. A single-brand install sends over the configured mailer with the
 * configured From, and the node's own `from` still wins where it is set —
 * because there is no brand identity for it to be competing with.
 */
it('leaves a single-brand install sending exactly as before', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    expect(($this->run)()->isSuccess())->toBeTrue();

    $mails = ($this->mails)('global');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('global@example.com');
});

it('still honours the node from when no brand declares an identity', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    ($this->run)(['from' => 'flow@example.test']);

    $mails = ($this->mails)('global');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('flow@example.test');
});

/**
 * THE test. Two brands, one process, the brand with its own mailer first —
 * because that is the only order in which the bug shows. Laravel burns
 * `mail.from` into a mailer instance on first resolution and caches the
 * instance in the `mail.manager` singleton for the life of the process.
 *
 * Against the pre-12.08.2026 code both mails land in the `global` transport.
 */
it('does not let the first brand in a process decide who the second sends as', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $a = Brand::create(['handle' => 'marke-a', 'name' => 'Marke A', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-a.test',
        'from_name' => 'Marke A',
        'mailer' => 'marke_a',
    ]]]);

    $b = Brand::create(['handle' => 'marke-b', 'name' => 'Marke B', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-b.test',
        'mailer' => 'marke_b',
    ]]]);

    foreach ([$a, $b] as $brand) {
        BrandContext::runFor($brand, fn () => ($this->run)());
    }

    $fromA = ($this->mails)('marke_a');
    $fromB = ($this->mails)('marke_b');

    expect($fromA)->toHaveCount(1)
        ->and($fromA[0]->getFrom()[0]->getAddress())->toBe('noreply@marke-a.test')
        ->and($fromA[0]->getFrom()[0]->getName())->toBe('Marke A')
        ->and($fromB)->toHaveCount(1)
        ->and($fromB[0]->getFrom()[0]->getAddress())->toBe('noreply@marke-b.test')
        // The negative half, and the one that matters: the default transport
        // never saw a thing.
        ->and(($this->mails)('global'))->toHaveCount(0);
});

/**
 * The precedence rule, stated as a test because it is the one thing about this
 * change somebody could reasonably have expected to go the other way.
 *
 * A brand that declares an identity has told the host which address its relay
 * account owns. A per-node override would hand that guarantee back to whoever
 * last edited the flow, and the pair would split exactly the way it split in
 * the incident. So the brand wins, and only where no brand declares anything
 * does the node's `from` still decide.
 */
it('does not let a node from override a brand that declared its own address', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brand = Brand::create(['handle' => 'marke-a', 'name' => 'Marke A', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-a.test',
        'mailer' => 'marke_a',
    ]]]);

    BrandContext::runFor($brand, fn () => ($this->run)(['from' => 'hallo@fremde-marke.test']));

    $mails = ($this->mails)('marke_a');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('noreply@marke-a.test');
});

it('sends nothing for a brand that declares mail settings without a from address', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Log::spy();

    $brand = Brand::create(['handle' => 'halb', 'name' => 'Halb', 'settings' => ['mail' => [
        'mailer' => 'marke_a',
    ]]]);

    $result = BrandContext::runFor($brand, fn () => ($this->run)());

    expect($result->isSuccess())->toBeFalse()
        ->and(($this->mails)('marke_a'))->toHaveCount(0)
        ->and(($this->mails)('global'))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'from_address'))
        ->once();
});

/**
 * The dedupe cache is a stamp: it records that this recipient has been served,
 * and it is kept for a year. Writing it for a mail that never left would
 * suppress the very retry that fixing the brand's settings is supposed to
 * enable — the flow would report itself done forever.
 */
it('does not stamp the dedupe key for a mail it could not send', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brand = Brand::create(['handle' => 'halb', 'name' => 'Halb', 'settings' => ['mail' => [
        'mailer' => 'marke_a',
    ]]]);

    BrandContext::runFor($brand, fn () => ($this->run)(['dedupe' => 'welcome']));

    // Now the brand is fixed. The same flow must still be able to send.
    $brand->update(['settings' => ['mail' => [
        'from_address' => 'noreply@halb.test',
        'mailer' => 'marke_a',
    ]]]);

    $result = BrandContext::runFor($brand, fn () => ($this->run)(['dedupe' => 'welcome']));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['skipped'] ?? false)->toBeFalse()
        ->and(($this->mails)('marke_a'))->toHaveCount(1);
});

it('sends nothing for a brand naming a mailer config does not define', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Log::spy();

    $brand = Brand::create(['handle' => 'tippfehler', 'name' => 'Tippfehler', 'settings' => ['mail' => [
        'from_address' => 'noreply@tippfehler.test',
        'mailer' => 'scaleway_typo',
    ]]]);

    $result = BrandContext::runFor($brand, fn () => ($this->run)());

    expect($result->isSuccess())->toBeFalse()
        ->and(($this->mails)('global'))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'scaleway_typo'))
        ->once();
});

it('lets a host swap the resolver without touching the addon', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    app()->bind(
        SenderIdentityResolver::class,
        fn () => new class implements SenderIdentityResolver
        {
            public function resolve(?int $brandId): SenderIdentity
            {
                return SenderIdentity::of('marke_b', 'host@example.test', 'Host');
            }
        },
    );

    ($this->run)();

    $mails = ($this->mails)('marke_b');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('host@example.test');
});

it('does not touch mail config while sending', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brand = Brand::create(['handle' => 'marke-a', 'name' => 'Marke A', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-a.test',
        'mailer' => 'marke_a',
    ]]]);

    BrandContext::runFor($brand, fn () => ($this->run)());

    // Not cosmetics. A `Config::set('mail.from.…')` here would survive its own
    // `finally`, because Laravel has already burned the value into the cached
    // mailer instance by the time the window closes.
    expect(config('mail.from.address'))->toBe('global@example.com')
        ->and(config('mail.default'))->toBe('global');
});
