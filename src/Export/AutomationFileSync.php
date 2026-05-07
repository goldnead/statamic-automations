<?php

namespace Goldnead\StatamicAutomations\Export;

use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Filesystem\Filesystem;

/**
 * Optional file-based automation sync.
 *
 * Allows automations to be exported to `resources/automations/{handle}.json`
 * so they can be committed to Git, included in starter kits or shared
 * between environments. The database remains the runtime source of
 * truth; files are a portable representation.
 */
class AutomationFileSync
{
    public function __construct(
        protected Filesystem $files,
        protected AutomationExporter $exporter,
        protected AutomationImporter $importer,
    ) {
    }

    public function path(?string $handle = null): string
    {
        $base = $this->basePath();

        return $handle ? "{$base}/{$handle}.json" : $base;
    }

    public function exportToFile(Automation $automation): string
    {
        $base = $this->basePath();
        $this->files->ensureDirectoryExists($base);

        $path = "{$base}/{$automation->handle}.json";
        $this->files->put($path, $this->exporter->toJson($automation));

        return $path;
    }

    /**
     * @return array{automation: Automation, warnings: array<int, string>, missing_integrations: array<int, string>, missing_node_types: array<int, string>}|null
     */
    public function importFromFile(string $path): ?array
    {
        if (! $this->files->exists($path)) {
            return null;
        }

        $payload = json_decode($this->files->get($path), true);
        if (! is_array($payload)) {
            return null;
        }

        return $this->importer->import($payload);
    }

    /**
     * List automations currently stored as files.
     *
     * @return array<int, array{handle: string, path: string, size: int}>
     */
    public function listFiles(): array
    {
        $base = $this->basePath();
        if (! $this->files->isDirectory($base)) {
            return [];
        }

        $out = [];
        foreach ($this->files->files($base) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $out[] = [
                'handle' => $file->getFilenameWithoutExtension(),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
            ];
        }

        return $out;
    }

    /**
     * Detect whether the database automation is in sync with its
     * file representation.
     *
     * @return array{file_exists: bool, in_sync: bool, file_path: string}
     */
    public function syncStatus(Automation $automation): array
    {
        $path = $this->path($automation->handle);
        $exists = $this->files->exists($path);

        if (! $exists) {
            return ['file_exists' => false, 'in_sync' => false, 'file_path' => $path];
        }

        $fileContent = $this->files->get($path);
        $dbContent = $this->exporter->toJson($automation);

        // Strip exported_at since it is mutating per call.
        $stripExportedAt = fn (string $json) => preg_replace('/"exported_at"\s*:\s*"[^"]+",?\s*/', '', $json);

        return [
            'file_exists' => true,
            'in_sync' => $stripExportedAt($fileContent) === $stripExportedAt($dbContent),
            'file_path' => $path,
        ];
    }

    protected function basePath(): string
    {
        return rtrim((string) config(
            'automations.file_storage.path',
            $this->defaultBasePath(),
        ), '/');
    }

    protected function defaultBasePath(): string
    {
        if (function_exists('resource_path')) {
            return resource_path('automations');
        }

        return getcwd() . '/resources/automations';
    }
}
