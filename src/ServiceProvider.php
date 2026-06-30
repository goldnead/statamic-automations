<?php

namespace Goldnead\StatamicAutomations;

use Goldnead\StatamicAutomations\Console\Commands\PruneRuns;
use Goldnead\StatamicAutomations\Console\Commands\SyncAutomations;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Export\AutomationExporter;
use Goldnead\StatamicAutomations\Export\AutomationFileSync;
use Goldnead\StatamicAutomations\Export\AutomationImporter;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Licensing\LicenseManager;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions as LH;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers as LHT;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerSendAction;
use Goldnead\StatamicAutomations\Listeners\HandleEntryPublished;
use Goldnead\StatamicAutomations\Listeners\HandleFormSubmitted;
use Goldnead\StatamicAutomations\Nodes\Actions\AddLogEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SimpleWebhookAction;
use Goldnead\StatamicAutomations\Nodes\Logic\BranchNode;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Goldnead\StatamicAutomations\Nodes\Logic\FilterNode;
use Goldnead\StatamicAutomations\Nodes\Logic\StopNode;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntryPublishedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\FormSubmittedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\ManualTrigger;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Support\Facades\Event;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * Statamic 6 Vite entry points. The compiled assets ship with the package
     * under resources/dist/build/ (built via `npm run build` in this package
     * directory). On install Statamic publishes them from there to the host's
     * public/vendor/<package>/build/ and serves them in the CP via the Vite
     * tag — so there is no end-user build step.
     *
     * @var array<string, mixed>
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    /**
     * CP routes. Registering them through Statamic's `$routes` property (rather
     * than a manual loadRoutesFrom) mounts them under the Control Panel route
     * group: the `/cp` URL prefix, the `statamic.cp.` route-name prefix and the
     * CP authentication middleware are all applied by Statamic. This is what
     * makes `cp_route('statamic-automations.*')` resolve in the controllers and
     * navigation — a manual loadRoutesFrom registers bare names and 500s every
     * CP page.
     *
     * @var array<string, string>
     */
    protected $routes = [
        'cp' => __DIR__ . '/../routes/cp.php',
    ];

    /**
     * Register the addon's service container bindings.
     */
    public function register(): void
    {
        // Merge default config so it is available even if not published.
        $this->mergeConfigFrom(__DIR__ . '/../config/automations.php', 'automations');

        // Singleton registries — they hold runtime registration state.
        $this->app->singleton(TriggerRegistry::class);
        $this->app->singleton(ActionRegistry::class);
        $this->app->singleton(NodeRegistry::class);

        // Integration helpers (cheap singletons; the underlying sister
        // addons are detected lazily).
        $this->app->singleton(IntegrationDetector::class);
        $this->app->singleton(WebhookManagerAdapter::class);
        $this->app->singleton(LeadHubAdapter::class);

        // Engine services. Most are stateless or use injected registries.
        $this->app->singleton(TokenResolver::class);
        $this->app->singleton(ConditionEvaluator::class);
        $this->app->singleton(FlowValidator::class);
        $this->app->singleton(RunLogger::class);
        $this->app->singleton(NodeExecutor::class);
        $this->app->singleton(WorkflowRunner::class);

        // Licensing.
        $this->app->singleton(LicenseManager::class);

        // Export / import services.
        $this->app->singleton(AutomationExporter::class);
        $this->app->singleton(AutomationImporter::class);
        $this->app->singleton(AutomationFileSync::class);

        // The public API entry point used by the Automations facade.
        $this->app->singleton('automations', function ($app) {
            return new Automations(
                $app->make(TriggerRegistry::class),
                $app->make(ActionRegistry::class),
                $app->make(NodeRegistry::class),
                $app->make(LicenseManager::class),
            );
        });
    }

    /**
     * Bootstrap any addon services.
     */
    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // CP routes are registered via the $routes property above, which mounts
        // them under Statamic's Control Panel route group automatically.

        $this->publishes([
            __DIR__ . '/../config/automations.php' => config_path('automations.php'),
        ], 'statamic-automations-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'statamic-automations-migrations');

        // The compiled CP bundle (resources/dist/build/) is published
        // automatically by Statamic's AddonServiceProvider from the $vite
        // property above, under the package's publish tag — no manual
        // publishes() registration needed.

        $this->registerBuiltInNodes();
        $this->registerOptionalIntegrations();
        $this->registerEventListeners();
        $this->registerPermissions();
        $this->registerNavigation();
        $this->registerCommands();
    }

    /**
     * Register Artisan commands. Only loaded inside the console kernel.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAutomations::class,
                PruneRuns::class,
            ]);
        }
    }

    /**
     * Register the built-in trigger / logic / action nodes.
     */
    protected function registerBuiltInNodes(): void
    {
        $enabled = config('automations.builtin_nodes', []);

        $triggers = [
            'manual' => ManualTrigger::class,
            'form_submitted' => FormSubmittedTrigger::class,
            'entry_published' => EntryPublishedTrigger::class,
        ];

        $logic = [
            'filter' => FilterNode::class,
            'branch' => BranchNode::class,
            'stop' => StopNode::class,
            'delay' => DelayNode::class,
        ];

        $actions = [
            'send_email' => SendEmailAction::class,
            'send_webhook' => SimpleWebhookAction::class,
            'add_log_entry' => AddLogEntryAction::class,
        ];

        $automations = $this->app->make('automations');

        foreach ($triggers as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                // Mark as built-in BEFORE registering so Pro-gating skips it.
                $automations->registerBuiltIn($class::handle());
                $automations->trigger($class::handle(), $class);
            }
        }

        foreach ($logic as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->registerBuiltIn($class::handle());
                $automations->node($class::handle(), $class);
            }
        }

        foreach ($actions as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->registerBuiltIn($class::handle());
                $automations->action($class::handle(), $class);
            }
        }
    }

    /**
     * Conditionally register Webhook Manager + LeadHub triggers / actions
     * when the sister addons are installed.
     */
    protected function registerOptionalIntegrations(): void
    {
        /** @var IntegrationDetector $detector */
        $detector = $this->app->make(IntegrationDetector::class);
        /** @var \Goldnead\StatamicAutomations\Automations $automations */
        $automations = $this->app->make('automations');

        if ($detector->hasWebhookManager()) {
            $automations->registerBuiltIn(WebhookManagerSendAction::handle());
            $automations->action(WebhookManagerSendAction::handle(), WebhookManagerSendAction::class);
        }

        if ($detector->hasLeadHub()) {
            // Triggers
            foreach ([
                LHT\LeadCreatedTrigger::class,
                LHT\LeadStatusChangedTrigger::class,
                LHT\LeadTagAddedTrigger::class,
                LHT\LeadNoteAddedTrigger::class,
                LHT\LeadFollowUpDueTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            // Actions
            foreach ([
                LH\CreateOrUpdateLeadAction::class,
                LH\ChangeLeadStatusAction::class,
                LH\AddLeadTagAction::class,
                LH\RemoveLeadTagAction::class,
                LH\AddLeadNoteAction::class,
                LH\CreateFollowUpAction::class,
                LH\CompleteFollowUpAction::class,
                LH\CreateTaskAction::class,
                LH\MoveStageAction::class,
                LH\UpsertOpportunityAction::class,
            ] as $actionClass) {
                $automations->registerBuiltIn($actionClass::handle());
                $automations->action($actionClass::handle(), $actionClass);
            }
        }
    }

    /**
     * Register event listeners for the built-in Statamic triggers.
     *
     * Event class names are referenced as strings so the package keeps
     * working even when a particular Statamic version does not ship a
     * given event class. The list below is verified against Statamic
     * v5/v6 (https://statamic.dev/extending/events).
     */
    protected function registerEventListeners(): void
    {
        $listeners = [
            // Form submissions — Statamic v5 emits SubmissionCreated;
            // older versions used FormSubmitted. We listen to both.
            'Statamic\\Events\\SubmissionCreated' => HandleFormSubmitted::class,
            'Statamic\\Events\\FormSubmitted' => HandleFormSubmitted::class,
            // Entry publish events. EntryPublished fires only on actual
            // publish; EntrySaved fires on every save and is filtered
            // inside the trigger when the user wants "only if published".
            'Statamic\\Events\\EntryPublished' => HandleEntryPublished::class,
            'Statamic\\Events\\EntrySaved' => HandleEntryPublished::class,
        ];

        foreach ($listeners as $event => $listener) {
            if (class_exists($event)) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Register Statamic permissions.
     */
    protected function registerPermissions(): void
    {
        if (! class_exists(\Statamic\Facades\Permission::class)) {
            return;
        }

        \Statamic\Facades\Permission::group('automations', 'Automations', function () {
            \Statamic\Facades\Permission::register('view automations')->label('View automations');
            \Statamic\Facades\Permission::register('create automations')->label('Create automations');
            \Statamic\Facades\Permission::register('edit automations')->label('Edit automations');
            \Statamic\Facades\Permission::register('delete automations')->label('Delete automations');
            \Statamic\Facades\Permission::register('enable automations')->label('Enable / disable automations');
            \Statamic\Facades\Permission::register('run automation tests')->label('Run automation tests');
            \Statamic\Facades\Permission::register('view automation runs')->label('View automation runs');
            \Statamic\Facades\Permission::register('retry automation runs')->label('Retry automation runs');
            \Statamic\Facades\Permission::register('manage automation settings')->label('Manage automation settings');
        });
    }

    /**
     * Register the CP navigation entry.
     */
    protected function registerNavigation(): void
    {
        if (! class_exists(\Statamic\Facades\CP\Nav::class)) {
            return;
        }

        \Statamic\Facades\CP\Nav::extend(function ($nav) {
            $nav->create(__('Automations'))
                ->section(__('Tools'))
                ->route('statamic-automations.automations.index')
                ->icon('hammer')
                ->can('view automations')
                ->children([
                    $nav->item(__('Automations'))->route('statamic-automations.automations.index'),
                    $nav->item(__('Runs'))->route('statamic-automations.runs.index'),
                    $nav->item(__('Templates'))->route('statamic-automations.templates.index'),
                    $nav->item(__('Import'))->route('statamic-automations.import'),
                    $nav->item(__('Settings'))->route('statamic-automations.settings'),
                ]);
        });
    }
}
