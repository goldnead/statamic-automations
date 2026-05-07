<?php

namespace Goldnead\StatamicAutomations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Stores JSON values encrypted-at-rest when
 * `automations.runs.encrypt_context` is enabled.
 *
 * The encrypted payload is wrapped in a JSON envelope so the column
 * (`json` in MySQL) still accepts it:
 *
 *     { "_encrypted": "eyJpdiI6...==" }
 *
 * Behavior:
 *   - When the config flag is off, behaves identically to the standard
 *     `array` cast (transparent JSON encode/decode).
 *   - When on, values are encrypted with Laravel's `Crypt` facade
 *     (uses `APP_KEY`) before being stored, and decrypted on read.
 *   - Existing un-encrypted JSON in the column is read transparently —
 *     the cast detects the absence of the `_encrypted` envelope and
 *     falls back to a normal JSON decode. Migration is painless.
 *   - `null` is preserved.
 *
 * Use it in models like:
 *
 *     protected $casts = [
 *         'context' => EncryptedJson::class,
 *     ];
 */
class EncryptedJson implements CastsAttributes
{
    public const ENVELOPE_KEY = '_encrypted';

    public function get($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value)
            ? json_decode($value, true)
            : $value;

        // Encrypted envelope shape: { "_encrypted": "ciphertext" }
        if (is_array($decoded) && array_key_exists(self::ENVELOPE_KEY, $decoded)) {
            try {
                $plain = Crypt::decryptString($decoded[self::ENVELOPE_KEY]);
                $inner = json_decode($plain, true);

                return $inner === null && json_last_error() !== JSON_ERROR_NONE
                    ? null
                    : $inner;
            } catch (DecryptException) {
                return null;
            }
        }

        return $decoded;
    }

    public function set($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null) {
            return [$key => null];
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return [$key => null];
        }

        if ($this->encryptionEnabled()) {
            $envelope = json_encode([
                self::ENVELOPE_KEY => Crypt::encryptString($json),
            ], JSON_UNESCAPED_SLASHES);

            return [$key => $envelope];
        }

        return [$key => $json];
    }

    protected function encryptionEnabled(): bool
    {
        return (bool) config('automations.runs.encrypt_context', false);
    }
}
