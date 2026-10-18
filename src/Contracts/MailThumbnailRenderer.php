<?php

namespace Goldnead\StatamicAutomations\Contracts;

/**
 * Something that can turn a mail's HTML into a picture.
 *
 * One method. The job that wants a tile does not care whether a browser or a
 * test double produced it. Null means "this host cannot render at all" and the
 * node simply has no picture; a renderer that tried and failed throws, which is
 * a different fact and is logged differently.
 */
interface MailThumbnailRenderer
{
    /** The HTML as PNG bytes of `$width` × `$height`, or null when nothing can render here. */
    public function renderHtml(string $html, int $width, int $height): ?string;
}
