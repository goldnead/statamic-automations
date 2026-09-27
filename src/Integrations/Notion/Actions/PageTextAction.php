<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionClient;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionData;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionException;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Reads the text of a Notion page: its blocks as a tree, and as plain text.
 *
 * Built for pages where the content sits in callouts: a callout with a quote
 * "Hotel" at the top and the paragraphs under it ("Zimmer gebucht: 3 DZ").
 * Each callout gets a `heading` (its own text, or else its leading quote)
 * and a `body` (its children without that quote), so a template can write
 * `{{ heading }}` and the lines under it without knowing how the page was
 * typed.
 *
 * ## What is read
 *
 * Blocks with text: paragraphs, headings, list items, to-dos, toggles,
 * quotes, callouts, code. Columns and synced blocks are containers without
 * text of their own; their children take their place. Everything else
 * (images, embeds, child databases, dividers) is skipped. `depth` bounds how
 * far down children are read (1 to 3); every level is one request per block
 * with children.
 *
 * ## Empty template lines
 *
 * A page made from a template carries its labels whether filled in or not:
 * "Zimmer gebucht: " with nothing after the colon. `skip_empty_labels` drops
 * such a line (text of at most 40 characters ending in a colon), and
 * `skip_empty` drops blocks without text and without children.
 */
class PageTextAction implements AutomationAction
{
    use UsesNotionConnection;

    /** Block types whose text is read. */
    public const TEXT_TYPES = [
        'paragraph', 'heading_1', 'heading_2', 'heading_3', 'bulleted_list_item', 'numbered_list_item',
        'to_do', 'toggle', 'quote', 'callout', 'code',
    ];

    /** Containers whose children stand in for them. */
    public const CONTAINER_TYPES = ['column_list', 'column', 'synced_block'];

    public static function handle(): string
    {
        return 'notion.page_text';
    }

    public static function label(): string
    {
        return 'Get Page Text (Notion)';
    }

    public static function description(): ?string
    {
        return 'Reads the text blocks of a Notion page, callouts with their heading and lines, as a tree and as plain text.';
    }

    public static function schema(): array
    {
        return [
            self::connectionField(),
            [
                'handle' => 'page_id',
                'label' => 'Page ID',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'A page ID or Notion link, for example {{ item.id }}.',
            ],
            [
                'handle' => 'depth',
                'label' => 'Levels',
                'type' => 'select',
                'default' => '2',
                'options' => [
                    ['value' => '1', 'label' => '1: the page blocks only'],
                    ['value' => '2', 'label' => '2: with their children (callout contents)'],
                    ['value' => '3', 'label' => '3: two levels of children'],
                ],
            ],
            [
                'handle' => 'skip_empty',
                'label' => 'Skip empty blocks',
                'type' => 'toggle',
                'default' => true,
            ],
            [
                'handle' => 'skip_empty_labels',
                'label' => 'Skip empty template labels',
                'type' => 'toggle',
                'default' => false,
                'help' => 'Drops lines that are only a label with a colon, such as "Zimmer gebucht:", left over from a page template.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'blocks' => 'array',
            'plain' => 'string',
            'page_id' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $pageId = NotionData::id($config['page_id'] ?? null);

        if ($pageId === null) {
            return ActionResult::failed('A page ID is required (32 hexadecimal characters, with or without dashes, or a Notion link).');
        }

        $depth = max(1, min(3, (int) ($config['depth'] ?? 2) ?: 2));

        $client = $this->notion($config);
        if ($client instanceof ActionResult) {
            return $client;
        }

        $options = [
            'skip_empty' => filter_var($config['skip_empty'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'skip_empty_labels' => filter_var($config['skip_empty_labels'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        try {
            $blocks = $this->blocks($client, $pageId, $depth, $options);
        } catch (NotionException $e) {
            return ActionResult::failed($e->getMessage(), ['page_id' => $pageId]);
        }

        return ActionResult::success([
            'blocks' => $blocks,
            'plain' => implode("\n", $this->lines($blocks)),
            'page_id' => $pageId,
        ]);
    }

    /**
     * @param  array{skip_empty: bool, skip_empty_labels: bool}  $options
     * @return list<array<string, mixed>>
     */
    protected function blocks(NotionClient $client, string $parentId, int $depth, array $options): array
    {
        $out = [];

        foreach ($client->children($parentId) as $block) {
            $type = (string) ($block['type'] ?? '');
            $hasChildren = ! empty($block['has_children']) && isset($block['id']);

            if (in_array($type, self::CONTAINER_TYPES, true)) {
                // The container itself counts no level: its children are
                // what the page shows at this one.
                if ($hasChildren) {
                    array_push($out, ...$this->blocks($client, (string) $block['id'], $depth, $options));
                }

                continue;
            }

            if (! in_array($type, self::TEXT_TYPES, true)) {
                continue;
            }

            $text = trim(NotionData::text((array) ($block[$type]['rich_text'] ?? [])));
            $children = $hasChildren && $depth > 1
                ? $this->blocks($client, (string) $block['id'], $depth - 1, $options)
                : [];

            if ($children === [] && $this->skip($text, $options)) {
                continue;
            }

            $entry = ['type' => $type, 'text' => $text, 'children' => $children];

            if ($type === 'to_do') {
                $entry['checked'] = (bool) ($block['to_do']['checked'] ?? false);
            }

            if ($type === 'callout') {
                $entry += $this->callout($text, $children);

                // A callout that is only its heading has nothing to say.
                if ($entry['body'] === [] && $text === '' && $options['skip_empty']) {
                    continue;
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  array{skip_empty: bool, skip_empty_labels: bool}  $options
     */
    protected function skip(string $text, array $options): bool
    {
        if ($text === '') {
            return $options['skip_empty'];
        }

        return $options['skip_empty_labels'] && preg_match('/^[^:\n]{1,40}:$/u', $text) === 1;
    }

    /**
     * The heading of a callout: its own text, or else a quote before any
     * other child. The body is the children without that quote.
     *
     * @param  list<array<string, mixed>>  $children
     * @return array{heading: string, body: list<array<string, mixed>>}
     */
    protected function callout(string $text, array $children): array
    {
        if ($text === '' && ($children[0]['type'] ?? null) === 'quote' && ($children[0]['children'] ?? []) === []) {
            return ['heading' => (string) $children[0]['text'], 'body' => array_slice($children, 1)];
        }

        return ['heading' => $text, 'body' => $children];
    }

    /**
     * The tree as lines: list items with a bullet or a number, to-dos with a
     * box, a callout as its heading and then its body.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    protected function lines(array $blocks): array
    {
        $lines = [];
        $number = 0;

        foreach ($blocks as $block) {
            $type = $block['type'];
            $number = $type === 'numbered_list_item' ? $number + 1 : 0;

            if ($type === 'callout') {
                if ($block['heading'] !== '') {
                    $lines[] = $block['heading'];
                }

                array_push($lines, ...$this->lines($block['body']));

                continue;
            }

            $prefix = match ($type) {
                'bulleted_list_item' => '• ',
                'numbered_list_item' => $number.'. ',
                'to_do' => ($block['checked'] ?? false) ? '☑ ' : '☐ ',
                default => '',
            };

            if ($block['text'] !== '') {
                $lines[] = $prefix.$block['text'];
            }

            array_push($lines, ...$this->lines($block['children']));
        }

        return $lines;
    }
}
