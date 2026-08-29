<?php

namespace Goldnead\StatamicAutomations\Tests\Fixtures;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Tests\Feature\CalComTriggersTest;
use Throwable;

/**
 * Ein Mitschreiber vor dem echten Dispatcher.
 *
 * Er beantwortet genau eine Frage, die sich an Statuscodes nicht beantworten
 * laesst: **wurde ein Ablauf gestartet oder nicht.** Ein Controller, der erst
 * `dispatch()` aufruft und danach 403 zurueckgibt, besteht jede Pruefung, die
 * nur auf den Code sieht.
 *
 * Bewusst kein Doppelgaenger, der so tut, als koennte er dispatchen: er zaehlt
 * mit und laesst sich zum Scheitern bringen, mehr nicht. Dass ein Start
 * wirklich einen Lauf erzeugt, halten die Tests fest, die den echten Dispatcher
 * benutzen ({@see CalComTriggersTest}).
 * Ein Doppelgaenger, der beides beantworten soll, beantwortet am Ende keines
 * von beiden.
 */
class RecordingTriggerDispatcher extends TriggerDispatcher
{
    /** @var array<int, array{0: string, 1: object|array<string, mixed>}> */
    public array $calls = [];

    public ?Throwable $failWith = null;

    public function __construct() {}

    public function dispatch(string $triggerHandle, object|array $event): void
    {
        $this->calls[] = [$triggerHandle, $event];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
