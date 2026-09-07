<?php

/**
 * Test stand-in for the snapshot layer of the OPTIONAL
 * `goldnead/statamic-email-templates` addon.
 *
 * The real class is `Goldnead\EmailTemplates\Snapshots\Snapshots` and the name
 * is frozen on both sides — this addon holds it as a string and checks it with
 * `class_exists`, so a rename would turn snapshots off everywhere without an
 * error. Declaration guarded: with the real package installed alongside, this
 * is skipped and the real class answers.
 *
 * It records the calls instead of writing a row. The point of the tests using
 * it is not that a row appears, it is **what is handed over**: the template
 * with its `{{ … }}` intact and no recipient in it. So the stub deliberately
 * refuses nothing — a permissive stub would be wrong if the tests asked it to
 * judge, and these tests judge the payload themselves.
 */

namespace Goldnead\EmailTemplates\Snapshots;

if (! class_exists(Snapshots::class)) {
    class Snapshots
    {
        /**
         * Every `record()` call, in order.
         *
         * @var list<array{owner_type: string|null, owner_id: int|string|null, template: mixed, meta: array<string,mixed>}>
         */
        public static array $recorded = [];

        public static function reset(): void
        {
            self::$recorded = [];
        }

        public static function available(): bool
        {
            return true;
        }

        /**
         * @param  array<string,mixed>  $meta
         */
        public static function record(?string $ownerType, int|string|null $ownerId, mixed $template, array $meta = []): ?object
        {
            self::$recorded[] = [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'template' => $template,
                'meta' => $meta,
            ];

            return null;
        }
    }
}
