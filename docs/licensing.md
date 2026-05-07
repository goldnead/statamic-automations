# Licensing

The addon ships in two tiers:

- **Core** — everything in the built-in node library, every integration,
  the full execution engine, file sync, templates, the API.
- **Pro** — adds the ability to register **custom** triggers, actions
  and logic nodes from your own packages.

Pro gating is **off by default**. Set `automations.features.custom_actions_requires_pro = true`
to turn it on. With gating enabled, calls to `Automations::action()` /
`::trigger()` for non-built-in handles are silently skipped if the
license is invalid — they don't throw, so a lapsed license never
crashes a deploy.

## License modes

### Local config (default)

```php
// config/automations.php
'license' => [
    'key' => env('STATAMIC_AUTOMATIONS_LICENSE_KEY', ''),
    'mode' => 'config',
    'allowed_keys' => [
        env('STATAMIC_AUTOMATIONS_LICENSE_KEY'),
    ],
    'features' => ['custom_actions', 'custom_triggers'],
],
```

`mode = config` is best for self-hosted setups, agency-internal forks
and local development. The addon trusts whatever keys are listed in
`allowed_keys`.

### Remote verification

```php
'license' => [
    'key' => env('STATAMIC_AUTOMATIONS_LICENSE_KEY'),
    'mode' => 'remote',
    'endpoint' => 'https://your-license-server.example.com/verify',
    'cache_ttl_minutes' => 360,
],
```

`mode = remote` POSTs `{ "key": "..." }` to the configured endpoint
and expects a JSON response shaped like:

```json
{
  "valid": true,
  "expired": false,
  "expires_at": "2027-01-01T00:00:00Z",
  "features": ["custom_actions", "custom_triggers"]
}
```

The result is cached for `cache_ttl_minutes` (default 6 hours). On
network errors, the cached result is reused — your license server
going down does not lock customers out.

## Status endpoint

```
GET /cp/automations/api/license/status
```

Returns the current license status with shape:

```json
{
  "data": {
    "status": "valid|invalid|expired|network_error|no_key",
    "mode": "config|remote",
    "expires_at": "2027-01-01T00:00:00Z",
    "features": ["custom_actions", "custom_triggers"],
    "message": "..."
  }
}
```

Use it to render a license banner in the CP, or to drive feature flags
in your own custom integrations.

## Programmatic checks

```php
use Goldnead\StatamicAutomations\Facades\Automations;

if (Automations::license()->isPro()) {
    // …
}

// Check a specific feature gate (returns true when the feature is
// either not gated or the license unlocks it).
if (Automations::license()->gates('custom_actions')) {
    Automations::action('mycompany.custom', MyAction::class);
}
```

## Built-in nodes are never gated

Every node that ships with the addon — including the LeadHub and
Webhook Manager integrations — is registered as **built-in** and
ignores the Pro gate. Customers without a license still get the full
out-of-the-box automation library. The gate only applies to handles
your own packages register on top.
