<?php

namespace Goldnead\StatamicAutomations\Jobs;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Thumbnails\MailThumbnails;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Photograph an automation's mails, after the save has come back.
 *
 * The id travels, not the model: an automation deleted between dispatch and
 * run is a job that finds nothing and does nothing. One try; a mail that will
 * not render will not on the third attempt either, and the warning is logged.
 */
class RenderMailThumbnails implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $automationId) {}

    public function handle(MailThumbnails $thumbnails): void
    {
        $automation = Automation::query()->with('nodes')->find($this->automationId);

        if ($automation) {
            $thumbnails->render($automation);
        }
    }
}
