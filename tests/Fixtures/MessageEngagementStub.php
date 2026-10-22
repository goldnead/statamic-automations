<?php

/**
 * Test stand-in for the OPTIONAL `goldnead/statamic-marketing` addon.
 *
 * The step table of the map asks `MessageEngagement::forMessages(list<uuid>)` for people
 * counted by what they did (the meaning of a machine open lives in marketing). The package
 * is not in this repo's vendor/, so this declaration mirrors the contract: a test says what
 * became of each message uuid in `$byUuid`, and the stub counts people once, by address.
 * Declared only when the real class is absent.
 */

namespace Goldnead\Marketing\Services;

if (! class_exists(MessageEngagement::class)) {
    class MessageEngagement
    {
        /** @var array<string, array{email: string, opened?: bool, proxy?: bool, clicked?: bool, unsub?: bool, sent?: bool}> */
        public static array $byUuid = [];

        /** @var list<list<string>> every call, to count the lookups */
        public static array $calls = [];

        /** @param  list<string>  $messageUuids */
        public function forMessages(array $messageUuids): array
        {
            self::$calls[] = $messageUuids;

            $sent = $opened = $proxy = $clicked = $unsub = [];

            foreach ($messageUuids as $uuid) {
                $m = self::$byUuid[$uuid] ?? null;

                if ($m === null || ($m['sent'] ?? true) === false) {
                    continue;
                }

                $who = strtolower($m['email']);
                $sent[$who] = true;
                ! empty($m['opened']) || ! empty($m['clicked']) ? $opened[$who] = true : null;
                ! empty($m['proxy']) && empty($m['opened']) && empty($m['clicked']) ? $proxy[$who] = true : null;
                ! empty($m['clicked']) ? $clicked[$who] = true : null;
                ! empty($m['unsub']) ? $unsub[$who] = true : null;
            }

            return [
                'sent' => count($sent),
                'opened' => count($opened),
                'proxy_only' => count($proxy),
                'clicked' => count($clicked),
                'unsubscribed' => count($unsub),
            ];
        }
    }
}
