<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Backs the `send_email` node's email affordances in the CP builder: a rendered
 * template preview and a master-detail template picker.
 *
 * Both endpoints are guarded seams to the OPTIONAL `goldnead/statamic-email-templates`
 * addon — there's no hard composer dependency, so when the sibling is absent the
 * list degrades to an empty array and the preview 404s, never a fatal.
 *
 *   - GET  email-templates          → [{slug, title, subject, preview}]  (picker list)
 *   - GET  email-templates/preview  → {slug, title, subject, preview, html}
 *
 * The `preview` field is the template's preheader/preview text (a subtitle for
 * the list), NOT rendered HTML. The `html` field is the branded, email-layout
 * wrapped body with its {{ merge tokens }} resolved against SAMPLE data so the
 * author sees a representative render inside the sandboxed iframe.
 */
class EmailTemplatePreviewController extends Controller
{
    /**
     * All managed `et_templates` as a flat list for the picker.
     */
    public function index(): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json(['data' => $this->templates()->values()->all()]);
    }

    /**
     * The rendered branded HTML for a single template slug, with sample merge
     * tokens resolved. 404 when the addon is absent or the slug is unknown.
     */
    public function preview(Request $request, TokenResolver $resolver): JsonResponse
    {
        $this->authorizeAction('view automations');

        $slug = trim((string) $request->query('slug', ''));

        if ($slug === '' || ! $this->addonInstalled()) {
            return response()->json(['message' => __('Template not found.')], 404);
        }

        // Managed entry wins; no fallback callable, so an unknown slug resolves
        // to null (→ 404) rather than echoing back an empty template.
        $resolved = \Goldnead\EmailTemplates\Facades\EmailTemplates::resolve($slug);

        if ($resolved === null || (($resolved->body ?? '') === '' && ($resolved->subject ?? '') === '')) {
            return response()->json(['message' => __("Template ':slug' not found.", ['slug' => $slug])], 404);
        }

        $context = AutomationContext::make($this->sampleData());

        $html = (string) ($resolved->body ?? '');
        if ($html !== '') {
            // Returned raw (unescaped): the caller renders it inside a sandboxed
            // iframe with `sandbox="allow-same-origin"` (no script execution).
            $html = (string) $resolver->resolveString($html, $context);
        }

        $subject = (string) ($resolved->subject ?? '');
        if ($subject !== '') {
            $subject = (string) $resolver->resolveString($subject, $context);
        }

        // Preheader/preview text + title live on the managed entry, not the
        // resolved DTO — look them up so the preview header mirrors the list.
        $meta = $this->templates()->firstWhere('slug', $slug) ?? [];

        return response()->json(['data' => [
            'slug' => $slug,
            'title' => (string) (($resolved->title ?? '') !== '' ? $resolved->title : ($meta['title'] ?? $slug)),
            'subject' => $subject,
            'preview' => (string) ($meta['preview'] ?? ''),
            'html' => $html,
        ]]);
    }

    /**
     * The managed `et_templates` entries as `{slug, title, subject, preview}`.
     * Empty (never a fatal) when the addon or the collection is absent.
     *
     * @return Collection<int, array{slug: string, title: string, subject: string, preview: string}>
     */
    protected function templates(): Collection
    {
        if (! $this->addonInstalled() || ! class_exists(\Statamic\Facades\Entry::class)) {
            return collect();
        }

        try {
            return collect(\Statamic\Facades\Entry::query()->where('collection', 'et_templates')->get())
                ->map(fn ($entry) => [
                    'slug' => (string) $entry->slug(),
                    'title' => (string) ($entry->value('title') ?? $entry->slug()),
                    'subject' => (string) ($entry->value('subject') ?? ''),
                    'preview' => (string) ($entry->value('preview') ?? $entry->value('preheader') ?? ''),
                ])
                ->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Whether the optional email-templates addon is installed.
     */
    protected function addonInstalled(): bool
    {
        return class_exists(\Goldnead\EmailTemplates\Facades\EmailTemplates::class);
    }

    /**
     * Representative sample data for token resolution in the preview. Mirrors
     * the shapes an automation typically seeds (subscriber/contact/stimmanalyse)
     * so `{{ subscriber.first_name }}` etc. render to a plausible value.
     *
     * @return array<string, mixed>
     */
    protected function sampleData(): array
    {
        $person = [
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
        ];

        return [
            'subscriber' => $person,
            'contact' => $person,
            'stimmanalyse' => [
                'name' => 'Max',
                'variant' => 'live',
            ],
        ];
    }
}
