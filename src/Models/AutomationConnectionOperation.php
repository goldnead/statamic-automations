<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\StatamicAutomations\Nodes\Actions\ConnectionOperationAction;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call a connection offers — in the builder its own action node,
 * `connection.<connection handle>.<operation handle>`.
 *
 * No brand of its own: it has the brand of its connection, and every lookup
 * goes through the connection so the brand scope applies.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $handle
 * @property string $name
 * @property string|null $description
 * @property string $method
 * @property string $path
 * @property array<mixed>|null $query
 * @property array<mixed>|null $body
 * @property array<int, array<string, mixed>>|null $inputs
 * @property array<mixed>|null $response_map
 * @property bool $fail_on_error_status
 * @property-read AutomationConnection $automationConnection
 */
class AutomationConnectionOperation extends Model
{
    public const NODE_PREFIX = 'connection.';

    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public const INPUT_TYPES = ['text', 'textarea', 'number', 'toggle', 'select'];

    protected $table = 'automation_connection_operations';

    protected $fillable = [
        'handle',
        'name',
        'description',
        'method',
        'path',
        'query',
        'body',
        'inputs',
        'response_map',
        'fail_on_error_status',
    ];

    protected $attributes = [
        'method' => 'GET',
        'path' => '/',
        'fail_on_error_status' => true,
    ];

    protected $casts = [
        'query' => 'array',
        'body' => 'array',
        'inputs' => 'array',
        'response_map' => 'array',
        'fail_on_error_status' => 'boolean',
    ];

    /**
     * Not `connection()`: Eloquent's own `$connection` property (the database
     * connection name) would shadow it inside the model.
     *
     * @return BelongsTo<AutomationConnection, $this>
     */
    public function automationConnection(): BelongsTo
    {
        return $this->belongsTo(AutomationConnection::class, 'connection_id');
    }

    /**
     * The operation behind a node type, in the current brand — or null when
     * the handle is not one of ours or the operation is gone.
     */
    public static function findByNodeType(string $type): ?self
    {
        if (! str_starts_with($type, self::NODE_PREFIX)) {
            return null;
        }

        $parts = explode('.', substr($type, strlen(self::NODE_PREFIX)), 2);

        if (count($parts) !== 2 || ! AutomationConnection::schemaReady()) {
            return null;
        }

        [$connection, $operation] = $parts;

        return static::query()
            ->where('handle', $operation)
            ->whereHas('automationConnection', fn ($query) => $query->where('handle', $connection))
            ->with('automationConnection')
            ->first();
    }

    /**
     * Every operation of the current brand, for the node library.
     *
     * @return iterable<int, self>
     */
    public static function allForLibrary(): iterable
    {
        if (! AutomationConnection::schemaReady()) {
            return [];
        }

        return static::query()
            ->whereHas('automationConnection')
            ->with('automationConnection')
            ->orderBy('connection_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The registry entry for a `connection.<conn>.<op>` handle.
     *
     * A handle whose operation is gone still resolves, to a bare entry: the
     * validator would otherwise refuse the whole automation as "unknown node
     * type" and nothing would ever say which operation went missing. This way
     * the node runs and fails with that message itself.
     *
     * @return array<string, mixed>|null
     */
    public static function nodeEntryFor(string $type): ?array
    {
        if (! preg_match('/^'.preg_quote(self::NODE_PREFIX, '/').'[a-z0-9_]+\.[a-z0-9_]+$/', $type)) {
            return null;
        }

        $operation = static::findByNodeType($type);

        if ($operation !== null) {
            return $operation->nodeEntry();
        }

        if (! AutomationConnection::schemaReady()) {
            return null;
        }

        return [
            'handle' => $type,
            'class' => ConnectionOperationAction::class,
            'kind' => 'action',
            'meta' => [
                'label' => $type,
                'description' => 'This connection operation no longer exists.',
                'group' => ConnectionOperationAction::group(),
                'schema' => [],
                'supports_test_mode' => true,
                'output_schema' => ConnectionOperationAction::outputSchema(),
            ],
        ];
    }

    public function nodeType(): string
    {
        return self::NODE_PREFIX.$this->automationConnection->handle.'.'.$this->handle;
    }

    /**
     * The registry entry for this operation's node — what
     * {@see NodeRegistry::describe()}
     * reads instead of the class's static methods.
     *
     * @return array{handle: string, class: class-string, kind: string, meta: array<string, mixed>}
     */
    public function nodeEntry(): array
    {
        $outputSchema = ['status' => 'integer'];

        foreach (array_keys(static::keyValue($this->response_map)) as $name) {
            $outputSchema[$name] = 'mixed';
        }

        $outputSchema['body'] = 'mixed';

        return [
            'handle' => $this->nodeType(),
            'class' => ConnectionOperationAction::class,
            'kind' => 'action',
            'meta' => [
                'label' => $this->name,
                'description' => $this->description,
                'group' => $this->automationConnection->name,
                'schema' => $this->inputSchema(),
                'supports_test_mode' => true,
                'output_schema' => $outputSchema,
            ],
        ];
    }

    /**
     * The operation's inputs as node config fields. Every one takes tokens:
     * mapping data from earlier nodes into the call is the point.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inputSchema(): array
    {
        $fields = [];

        foreach ($this->inputs ?? [] as $input) {
            if (! is_array($input) || blank($input['handle'] ?? null)) {
                continue;
            }

            $fields[] = array_filter([
                'handle' => (string) $input['handle'],
                'label' => (string) ($input['label'] ?? $input['handle']),
                'type' => in_array($input['type'] ?? null, self::INPUT_TYPES, true) ? $input['type'] : 'text',
                'required' => (bool) ($input['required'] ?? false),
                'default' => $input['default'] ?? null,
                'options' => $input['options'] ?? null,
                'tokenable' => true,
            ], fn ($value) => $value !== null);
        }

        return $fields;
    }

    /**
     * A key_value field as a flat map, whether it was stored as a map or as
     * the list of `{key, value}` rows the CP field produces.
     *
     * @return array<string, mixed>
     */
    public static function keyValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $row) {
            if (is_array($row) && filled($row['key'] ?? null)) {
                $out[(string) $row['key']] = $row['value'] ?? '';
            }
        }

        return $out;
    }
}
