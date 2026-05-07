<?php

namespace Goldnead\StatamicAutomations\Contracts;

/**
 * Base contract every automation node must implement.
 *
 * Concrete contracts (AutomationTrigger, AutomationAction) extend this.
 */
interface AutomationNode
{
    /**
     * The unique handle of the node (e.g. "form_submitted", "send_email").
     */
    public static function handle(): string;

    /**
     * Human-readable label shown in the node library and on the canvas.
     */
    public static function label(): string;

    /**
     * Optional description shown in tooltips and the marketplace.
     */
    public static function description(): ?string;

    /**
     * Group label (e.g. "Statamic", "LeadHub", "Webhook Manager", "Custom").
     */
    public static function group(): string;

    /**
     * Schema for the dynamic config form. Each entry is an associative
     * array describing one form field: { handle, label, type, required, ... }.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(): array;

    /**
     * Whether the node behaves correctly when the run is a test run.
     *
     * Side-effecting nodes should return true so they can render their
     * payload without performing the real action when in test mode.
     */
    public static function supportsTestMode(): bool;
}
