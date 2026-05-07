<?php

namespace Goldnead\StatamicAutomations;

use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
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

        // Engine services. Most are stateless or use injected registries.
        $this->app->singleton(TokenResolver::class);
        $this->app->singleton(ConditionEvaluator::class);
        $this->app->singleton(FlowValidator::class);
        $this->app->singleton(RunLogger::class);
        $this->app->singleton(NodeExecutor::class);
        $this->app->singleton(WorkflowRunner::class);

        // The public API entry point used by the Automations facade.
        $this->app->singleton('automations', function ($app) {
            return new Automations(
                $app->make(TriggerRegistry::class),
                $app->make(ActionRegistry::class),
                $app->make(NodeRegistry::class),
            );
        });
    }

    /**
     * Bootstrap any addon services.
     */
    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/cp.php');

        $this->publishes([
            __DIR__ . '/../config/automations.php' => config_path('automations.php'),
        ], 'statamic-automations-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'statamic-automations-migrations');

        $this->registerBuiltInNodes();
        $this->registerEventListeners();
        $this->registerPermissions();
        $this->registerNavigation();
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
                $automations->trigger($class::handle(), $class);
            }
        }

        foreach ($logic as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->node($class::handle(), $class);
            }
        }

        foreach ($actions as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->action($class::handle(), $class);
            }
        }
    }

    /**
     * Register event listeners for the built-in Statamic triggers.
     *
     * Event class names are referenced as strings so the package keeps
     * working even when a particular Statamic version does not ship a
     * given event class.
     */
    protected function registerEventListeners(): void
    {
        $listeners = [
            'Statamic\\Events\\SubmissionCreated' => HandleFormSubmitted::class,
            'Statamic\\Events\\FormSubmitted' => HandleFormSubmitted::class,
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
                ->route('automations.index')
                ->icon('automations')
                ->can('view automations')
                ->children([
                    $nav->item(__('Automations'))->route('automations.index'),
                    $nav->item(__('Runs'))->route('automations.runs.index'),
                    $nav->item(__('Templates'))->route('automations.templates.index'),
                    $nav->item(__('Settings'))->route('automations.settings'),
                ]);
        });
    }
}
