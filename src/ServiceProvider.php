<?php

namespace Goldnead\StatamicAutomations;

use Goldnead\StatamicAutomations\Console\Commands\PruneRuns;
use Goldnead\StatamicAutomations\Console\Commands\RunDueScheduledJobs;
use Goldnead\StatamicAutomations\Console\Commands\RunScheduledAutomations;
use Goldnead\StatamicAutomations\Console\Commands\SyncAutomations;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Contracts\SenderIdentityResolver;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Export\AutomationExporter;
use Goldnead\StatamicAutomations\Export\AutomationFileSync;
use Goldnead\StatamicAutomations\Export\AutomationImporter;
use Goldnead\StatamicAutomations\Integrations\Booking\Triggers as BT;
use Goldnead\StatamicAutomations\Integrations\CalCom\Triggers as CalT;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions as EA;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers as ET;
use Goldnead\StatamicAutomations\Integrations\Funnels\Triggers as FT;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\Invoices\Actions as IA;
use Goldnead\StatamicAutomations\Integrations\Invoices\InvoicesAdapter;
use Goldnead\StatamicAutomations\Integrations\Invoices\Triggers as IT;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions as LH;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubEventTriggers;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Triggers as LHT;
use Goldnead\StatamicAutomations\Integrations\Marketing\Actions\SendCampaignAction;
use Goldnead\StatamicAutomations\Integrations\Marketing\Actions\SubscribeToListAction;
use Goldnead\StatamicAutomations\Integrations\Marketing\Actions\UnsubscribeFromListAction;
use Goldnead\StatamicAutomations\Integrations\Marketing\Triggers\CampaignSentTrigger;
use Goldnead\StatamicAutomations\Integrations\Marketing\Triggers\SubscriberConfirmedTrigger;
use Goldnead\StatamicAutomations\Integrations\Marketing\Triggers\SubscriberUnsubscribedTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Triggers as PT;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Actions as VfA;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers as VfT;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerSendAction;
use Goldnead\StatamicAutomations\Listeners\HandleCommerceEvent;
use Goldnead\StatamicAutomations\Listeners\HandleEntryPublished;
use Goldnead\StatamicAutomations\Listeners\HandleFormSubmitted;
use Goldnead\StatamicAutomations\Listeners\HandleFunnelOrPaymentEvent;
use Goldnead\StatamicAutomations\Listeners\HandleLeadHubEvent;
use Goldnead\StatamicAutomations\Listeners\HandleMarketingEvent;
use Goldnead\StatamicAutomations\Nodes\Actions\AddLogEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\AddUserToGroupAction;
use Goldnead\StatamicAutomations\Nodes\Actions\AiGenerateAction;
use Goldnead\StatamicAutomations\Nodes\Actions\AssignUserRoleAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateTermAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateUserAction;
use Goldnead\StatamicAutomations\Nodes\Actions\DeleteEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\PublishEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SetGlobalValueAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SetVariableAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SimpleWebhookAction;
use Goldnead\StatamicAutomations\Nodes\Actions\UnpublishEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\UpdateEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\UpdateUserAction;
use Goldnead\StatamicAutomations\Nodes\Logic\BranchNode;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Goldnead\StatamicAutomations\Nodes\Logic\FilterNode;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode;
use Goldnead\StatamicAutomations\Nodes\Logic\StopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ThrottleNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode;
use Goldnead\StatamicAutomations\Nodes\Triggers\AssetDeletedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\AssetSavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\AssetUploadedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntryCreatedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntryDeletedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntryPublishedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntrySavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntrySavingTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\FormSubmittedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\GlobalSetSavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\ManualTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\NavSavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\ScheduledTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\TermDeletedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\TermSavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\UserDeletedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\UserRegisteredTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\UserSavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\WebhookDeliveryFailedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\WebhookReceivedTrigger;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\OptionSourceRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Goldnead\StatamicAutomations\Repositories\DatabaseAutomationRepository;
use Goldnead\StatamicAutomations\Repositories\FlatFileAutomationRepository;
use Goldnead\StatamicAutomations\Sending\BrandMailer;
use Goldnead\StatamicAutomations\Sending\BrandSenderIdentity;
use Goldnead\StatamicAutomations\Sequence\MailRules;
use Goldnead\StatamicAutomations\Support\OptionSources\NativeOptionSources;
use Goldnead\StatamicAutomations\Support\Settings;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
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
        'cp' => __DIR__.'/../routes/cp.php',

        // Oeffentliche Routen: der Serien-Ausstieg aus dem Fuss einer Mail.
        // Statamic haengt sie in die `web`-Gruppe; das Praefix setzt die
        // Datei selbst, damit es konfigurierbar bleibt.
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * Register the addon's service container bindings.
     */
    public function register(): void
    {
        // Merge default config so it is available even if not published.
        $this->mergeConfigFrom(__DIR__.'/../config/automations.php', 'automations');

        // Singleton registries — they hold runtime registration state.
        // Who a `send_email` node sends as, and over which transport. Bound to
        // an interface so a host that keeps sender identities somewhere other
        // than `brands.settings.mail` rebinds it instead of patching the
        // addon; the shipped implementation leaves a single-brand install
        // sending exactly as before.
        $this->app->singleton(SenderIdentityResolver::class, BrandSenderIdentity::class);
        $this->app->singleton(BrandMailer::class);

        $this->app->singleton(TriggerRegistry::class);
        $this->app->singleton(ActionRegistry::class);
        $this->app->singleton(NodeRegistry::class);
        $this->app->singleton(OptionSourceRegistry::class);
        $this->app->singleton(NativeOptionSources::class);

        // Integration helpers (cheap singletons; the underlying sister
        // addons are detected lazily).
        $this->app->singleton(IntegrationDetector::class);

        // Singleton because it holds the pre-override snapshot of the config
        // files. A second instance created after `apply()` would take the
        // already-overridden config for the baseline, and then read every
        // stored value as "equal to the default" and delete it on the next save.
        $this->app->singleton(Settings::class);
        $this->app->singleton(WebhookManagerAdapter::class);
        $this->app->singleton(LeadHubAdapter::class);
        $this->app->singleton(EntitlementsAdapter::class);
        $this->app->singleton(InvoicesAdapter::class);

        // Engine services. Most are stateless or use injected registries.
        $this->app->singleton(TokenResolver::class);
        $this->app->singleton(ConditionEvaluator::class);
        $this->app->singleton(FlowValidator::class);
        $this->app->singleton(RunLogger::class);
        $this->app->singleton(NodeExecutor::class);
        $this->app->singleton(WorkflowRunner::class);

        // Storage driver for automation definitions (database | flat_file).
        $this->app->singleton(
            AutomationRepository::class,
            function ($app) {
                $driver = (string) config('automations.storage.driver', 'database');

                return $driver === 'flat_file'
                    ? $app->make(FlatFileAutomationRepository::class)
                    : $app->make(DatabaseAutomationRepository::class);
            },
        );

        // Export / import services.
        $this->app->singleton(AutomationExporter::class);
        // Singleton so templates registered by other addons (e.g.
        // goldnead/statamic-marketing) survive until the CP reads the catalog.
        $this->app->singleton(TemplateRegistry::class);
        $this->app->singleton(AutomationImporter::class);
        $this->app->singleton(AutomationFileSync::class);

        // The public API entry point used by the Automations facade.
        $this->app->singleton('automations', function ($app) {
            return new Automations(
                $app->make(TriggerRegistry::class),
                $app->make(ActionRegistry::class),
                $app->make(NodeRegistry::class),
                $app->make(OptionSourceRegistry::class),
            );
        });
    }

    /**
     * Bootstrap any addon services.
     */
    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Settings changed in the Control Panel are pushed onto the live config
        // before anything reads it. First thing in boot, and in every process
        // — a queue worker resolves `automations.runs.*` too, and a setting
        // that held only for web requests would be a setting that appears to
        // work and silently does not where the work happens.
        $this->app->make(Settings::class)->apply();

        // Resolve the {automationFlow} route parameter through the active
        // storage driver so flat-file definitions (which have no DB row) bind
        // too.
        //
        // The name is `automationFlow` and not `automation` because a
        // Route::bind() is registered on the router, not on this package: it
        // resolves that parameter name in every addon installed alongside, and
        // the losing route answers 404 without an error anywhere. So an addon
        // may only bind names that unambiguously belong to it — this addon's
        // prefix plus a capital. See tests/Feature/RouteParameterCollisionTest.
        Route::bind('automationFlow', function ($value) {
            return $this->app
                ->make(AutomationRepository::class)
                ->find($value) ?? abort(404);
        });

        // Translations: PHP keys (backend) under the "statamic-automations"
        // namespace, plus JSON strings consumed by the Vue CP via __().
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'statamic-automations');

        // Die oeffentliche Seite des Serien-Ausstiegs. Bisher brachte das Addon
        // keine eigenen Blade-Ansichten mit.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-automations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/statamic-automations'),
        ], 'statamic-automations-translations');

        // CP routes are registered via the $routes property above, which mounts
        // them under Statamic's Control Panel route group automatically.

        $this->publishes([
            __DIR__.'/../config/automations.php' => config_path('automations.php'),
        ], 'statamic-automations-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-automations-migrations');

        // The compiled CP bundle (resources/dist/build/) is published
        // automatically by Statamic's AddonServiceProvider from the $vite
        // property above, under the package's publish tag — no manual
        // publishes() registration needed.

        $this->registerWebhookRoutes();

        $this->registerBuiltInOptionSources();
        $this->registerBuiltInNodes();
        $this->registerOptionalIntegrations();
        $this->registerEventListeners();
        $this->registerEventTriggers();
        $this->registerPermissions();
        $this->registerNavigation();
        $this->registerCommands();
    }

    /**
     * Eingehende Webhooks von Diensten ausserhalb von Statamic.
     *
     * Bewusst nicht ueber Statamics `$routes['web']`-Eigenschaft, die alles in
     * die `statamic.web`-Gruppe haengt: die bringt CSRF-Schutz und Sitzung mit,
     * und ein fremder Server hat weder das eine noch das andere. Die Begruendung
     * in voller Laenge steht in routes/webhooks.php.
     *
     * Unter demselben Praefix wie die uebrigen oeffentlichen Routen des Addons,
     * damit ein Betrieb nur einen Pfad kennen muss.
     */
    protected function registerWebhookRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        // Ohne Middleware auf der Gruppe: die haengt je Dienst an der einzelnen
        // Route, damit ein zweiter Dienst nicht die Drosselung des ersten erbt.
        // Begruendung in voller Laenge in routes/webhooks.php.
        Route::prefix(config('automations.routes.prefix', '!/automations'))
            ->group(__DIR__.'/../routes/webhooks.php');
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
                RunScheduledAutomations::class,
                RunDueScheduledJobs::class,
            ]);
        }

        // Run due scheduled automations every minute via Laravel's scheduler.
        $this->callAfterResolving(Schedule::class, function ($schedule) {
            $schedule->command('automations:run-scheduled')->everyMinute()->withoutOverlapping();
            // Resume runs whose delay/wait window has elapsed.
            $schedule->command('automations:run-due')->everyMinute()->withoutOverlapping();
        });
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
            'entry_saved' => EntrySavedTrigger::class,
            'entry_created' => EntryCreatedTrigger::class,
            'entry_saving' => EntrySavingTrigger::class,
            'entry_deleted' => EntryDeletedTrigger::class,
            'term_saved' => TermSavedTrigger::class,
            'term_deleted' => TermDeletedTrigger::class,
            'user_registered' => UserRegisteredTrigger::class,
            'user_saved' => UserSavedTrigger::class,
            'user_deleted' => UserDeletedTrigger::class,
            'asset_uploaded' => AssetUploadedTrigger::class,
            'asset_saved' => AssetSavedTrigger::class,
            'asset_deleted' => AssetDeletedTrigger::class,
            'global_set_saved' => GlobalSetSavedTrigger::class,
            'nav_saved' => NavSavedTrigger::class,
            'scheduled' => ScheduledTrigger::class,

            // cal.com. Anders als die Geschwister-Addons ohne Erkennung: cal.com
            // ist ein Dienst und keine Klasse, es gibt nichts, wonach ein
            // `class_exists` suchen koennte. Der Anschluss bringt seine eigene
            // Route mit (siehe routes/webhooks.php) und haengt an nichts weiter,
            // also stehen die Auslöser im Editor wie jeder andere eingebaute
            // Knoten. Wer sie nicht will, schaltet sie ueber
            // `automations.builtin_nodes` einzeln ab.
            'cal_com.booking_created' => CalT\BookingCreatedTrigger::class,
            'cal_com.booking_requested' => CalT\BookingRequestedTrigger::class,
            'cal_com.booking_cancelled' => CalT\BookingCancelledTrigger::class,
            'cal_com.booking_rejected' => CalT\BookingRejectedTrigger::class,
            'cal_com.booking_rescheduled' => CalT\BookingRescheduledTrigger::class,

            // VocalFlow. Dieselbe Ueberlegung wie bei cal.com: ein Dienst und
            // keine Klasse, es gibt nichts, wonach ein `class_exists` suchen
            // koennte. Der Anschluss bringt seine eigenen Routen mit (siehe
            // routes/webhooks.php) und haengt an nichts weiter, also stehen die
            // Auslöser im Editor wie jeder andere eingebaute Knoten. Wer sie
            // nicht will, schaltet sie ueber `automations.builtin_nodes`
            // einzeln ab.
            'vocalflow.session_created' => VfT\SessionCreatedTrigger::class,
            'vocalflow.session_completed' => VfT\SessionCompletedTrigger::class,
            'vocalflow.session_published' => VfT\SessionPublishedTrigger::class,
            'vocalflow.task_created' => VfT\TaskCreatedTrigger::class,
            'vocalflow.task_updated' => VfT\TaskUpdatedTrigger::class,
            'vocalflow.task_assigned' => VfT\TaskAssignedTrigger::class,
            'vocalflow.task_deleted' => VfT\TaskDeletedTrigger::class,
        ];

        $logic = [
            'filter' => FilterNode::class,
            'branch' => BranchNode::class,
            'switch' => SwitchNode::class,
            'stop' => StopNode::class,
            'delay' => DelayNode::class,
            'wait_until' => WaitUntilNode::class,
            'loop' => LoopNode::class,
            'parallel' => ParallelNode::class,
            'throttle' => ThrottleNode::class,
            'set_variable' => SetVariableAction::class,
            'call_automation' => CallAutomationAction::class,
        ];

        $actions = [
            'send_email' => SendEmailAction::class,
            'send_webhook' => SimpleWebhookAction::class,
            'add_log_entry' => AddLogEntryAction::class,
            'create_entry' => CreateEntryAction::class,
            'update_entry' => UpdateEntryAction::class,
            'create_user' => CreateUserAction::class,
            'ai_generate' => AiGenerateAction::class,
            // Native Statamic operations (A6). Each is shipped through the same
            // public register API a third party would use — proving it works.
            'publish_entry' => PublishEntryAction::class,
            'unpublish_entry' => UnpublishEntryAction::class,
            'delete_entry' => DeleteEntryAction::class,
            'create_term' => CreateTermAction::class,
            'update_user' => UpdateUserAction::class,
            'assign_user_role' => AssignUserRoleAction::class,
            'add_user_to_group' => AddUserToGroupAction::class,
            'set_global_value' => SetGlobalValueAction::class,

            // VocalFlow, die Gegenrichtung zu den Auslösern oben. Genau zwei,
            // die beiden Schritte des Onboardings; alles Weitere, was die
            // Partner-API kann, ist bewusst nicht gebaut (siehe
            // VocalFlowClient). Ohne hinterlegte Zugangsdaten tun beide nichts,
            // statt ins Leere zu rufen.
            'vocalflow.create_student' => VfA\CreateStudentAction::class,
            'vocalflow.grant_package' => VfA\GrantPackageAction::class,
        ];

        $automations = $this->app->make('automations');

        // Dogfood the public API: every built-in registers through the exact
        // same surface a third-party addon uses (registerTrigger / register
        // LogicNode / registerAction), with the handle-less overload. They are
        // marked built-in first so Pro-gating never skips them.
        foreach ($triggers as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->registerBuiltIn($class::handle())->registerTrigger($class);
            }
        }

        foreach ($logic as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->registerBuiltIn($class::handle())->registerLogicNode($class);
            }
        }

        foreach ($actions as $key => $class) {
            if (($enabled[$key] ?? true) && class_exists($class)) {
                $automations->registerBuiltIn($class::handle())->registerAction($class);
            }
        }
    }

    /**
     * Register the built-in `options_source` resolvers into the
     * OptionSourceRegistry — through the same public surface a third party
     * uses (`Automations::registerOptionSource`). Statamic-native sources are
     * registered under both the bare and the `statamic.`-prefixed spelling.
     */
    protected function registerBuiltInOptionSources(): void
    {
        /** @var Automations $automations */
        $automations = $this->app->make('automations');
        $native = NativeOptionSources::class;

        $map = [
            'forms' => 'forms',
            'collections' => 'collections',
            'sites' => 'sites',
            'entries' => 'entries',
            'taxonomies' => 'taxonomies',
            'terms' => 'terms',
            'users' => 'users',
            'roles' => 'roles',
            'groups' => 'userGroups',
            'blueprints' => 'blueprints',
            'assets' => 'assets',
            'asset_containers' => 'assetContainers',
            'globals' => 'globals',
        ];

        foreach ($map as $source => $method) {
            $resolver = fn ($request) => $this->app->make($native)->{$method}($request);
            // Both the bare handle and the historical statamic.-prefixed spelling.
            $automations->registerOptionSource($source, $resolver);
            $automations->registerOptionSource("statamic.{$source}", $resolver);
        }

        // Non-statamic / integration + addon sources (single spelling each).
        $automations->registerOptionSource('automations', fn ($request) => $this->app->make($native)->automations($request));
        $automations->registerOptionSource('email_templates.templates', fn ($request) => $this->app->make($native)->emailTemplates($request));
        $automations->registerOptionSource('leadhub.statuses', fn ($request) => $this->app->make($native)->leadHubStatuses($request));
        $automations->registerOptionSource('leadhub.tags', fn ($request) => $this->app->make($native)->leadHubTags($request));
        $webhookDestinations = fn ($request) => $this->app->make($native)->webhookDestinations($request);
        $automations->registerOptionSource('webhook_manager.destinations', $webhookDestinations);
        $automations->registerOptionSource('webhooks', $webhookDestinations);
    }

    /**
     * Register custom event triggers declared in config
     * (`automations.event_triggers`). The programmatic path
     * (`Automations::registerEventTrigger()`) is available to any service
     * provider's boot(); this covers the zero-PHP, config-only path.
     */
    protected function registerEventTriggers(): void
    {
        $this->app->make('automations')->bootEventTriggersFromConfig();
    }

    /**
     * Conditionally register Webhook Manager + LeadHub triggers / actions
     * when the sister addons are installed.
     */
    protected function registerOptionalIntegrations(): void
    {
        /** @var IntegrationDetector $detector */
        $detector = $this->app->make(IntegrationDetector::class);
        /** @var Automations $automations */
        $automations = $this->app->make('automations');

        if ($detector->hasWebhookManager()) {
            $automations->registerBuiltIn(WebhookManagerSendAction::handle());
            $automations->action(WebhookManagerSendAction::handle(), WebhookManagerSendAction::class);

            foreach ([
                WebhookReceivedTrigger::class,
                WebhookDeliveryFailedTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }
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
                LH\ChangeScoreAction::class,
            ] as $actionClass) {
                $automations->registerBuiltIn($actionClass::handle());
                $automations->action($actionClass::handle(), $actionClass);
            }

            // Score-changed event trigger (registered through the public
            // registerEventTrigger API). Guarded on the LeadHub event class.
            LeadHubEventTriggers::register($automations);

            // Fire LeadHub triggers from LeadHub's domain events. Without this,
            // the LeadHub trigger nodes are selectable but never actually run.
            $this->listenForSisterEvents(HandleLeadHubEvent::EVENT_TRIGGERS, HandleLeadHubEvent::class);
        }

        if ($detector->hasFunnels()) {
            foreach ([
                FT\FunnelCompletedTrigger::class,
                FT\FunnelFormSubmittedTrigger::class,
                FT\FunnelStepEnteredTrigger::class,
                FT\FunnelOfferAcceptedTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            $this->listenForSisterEvents(
                HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS,
                HandleFunnelOrPaymentEvent::class,
            );
        }

        if ($detector->hasPayments()) {
            foreach ([
                PT\PaymentPaidTrigger::class,
                PT\PaymentFailedTrigger::class,
                PT\CheckoutAbandonedTrigger::class,
                PT\PaymentRefundedTrigger::class,
                PT\SubscriptionStartedTrigger::class,
                PT\SubscriptionRenewedTrigger::class,
                PT\SubscriptionCancelledTrigger::class,
                PT\SubscriptionEndedTrigger::class,
                PT\SubscriptionStartFailedTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            $this->listenForSisterEvents(
                HandleFunnelOrPaymentEvent::PAYMENT_TRIGGERS,
                HandleFunnelOrPaymentEvent::class,
            );
        }

        if ($detector->hasMarketing()) {
            foreach ([
                SubscriberConfirmedTrigger::class,
                SubscriberUnsubscribedTrigger::class,
                CampaignSentTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            foreach ([
                SubscribeToListAction::class,
                UnsubscribeFromListAction::class,
                SendCampaignAction::class,
            ] as $actionClass) {
                $automations->registerBuiltIn($actionClass::handle());
                $automations->action($actionClass::handle(), $actionClass);
            }

            // Fire marketing triggers from the addon's domain events.
            $this->listenForSisterEvents(HandleMarketingEvent::EVENT_TRIGGERS, HandleMarketingEvent::class);
        }

        if ($detector->hasEntitlements()) {
            foreach ([
                ET\EntitlementGrantedTrigger::class,
                ET\EntitlementRevokedTrigger::class,
                ET\EntitlementExpiredTrigger::class,
                ET\EntitlementRenewedTrigger::class,
                ET\EntitlementPendingTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            foreach ([
                EA\GrantEntitlementAction::class,
                EA\RevokeEntitlementAction::class,
            ] as $actionClass) {
                $automations->registerBuiltIn($actionClass::handle());
                $automations->action($actionClass::handle(), $actionClass);
            }

            $this->listenForSisterEvents(HandleCommerceEvent::ENTITLEMENT_TRIGGERS, HandleCommerceEvent::class);
        }

        if ($detector->hasBooking()) {
            foreach ([
                BT\BookingMadeTrigger::class,
                BT\BookingCancelledTrigger::class,
                BT\BookingRescheduledTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            // No booking actions. The booking addon exposes no public way to
            // create, move or cancel a booking — its one public method takes a
            // provider webhook payload — and writing to the model directly
            // would skip its idempotency key and fire none of its events. An
            // action that quietly does the wrong thing is worse than none.
            $this->listenForSisterEvents(HandleCommerceEvent::BOOKING_TRIGGERS, HandleCommerceEvent::class);
        }

        if ($detector->hasInvoices()) {
            foreach ([
                IT\InvoiceIssuedTrigger::class,
                IT\CreditNoteIssuedTrigger::class,
            ] as $triggerClass) {
                $automations->registerBuiltIn($triggerClass::handle());
                $automations->trigger($triggerClass::handle(), $triggerClass);
            }

            foreach ([
                IA\IssueInvoiceAction::class,
                IA\IssueCreditNoteAction::class,
            ] as $actionClass) {
                $automations->registerBuiltIn($actionClass::handle());
                $automations->action($actionClass::handle(), $actionClass);
            }

            $this->listenForSisterEvents(HandleCommerceEvent::INVOICE_TRIGGERS, HandleCommerceEvent::class);
        }
    }

    /**
     * Subscribe a listener to the sister-addon event classes that actually exist.
     *
     * The `class_exists` check is the whole safety net for an optional
     * integration: a site without the sibling addon registers no listener at
     * all and behaves exactly as it did before this code existed. It used to be
     * written out at every call site, which is five copies of the one check
     * that must not be forgotten.
     *
     * @param  array<string, string>  $map  event class-string => trigger handle
     * @param  class-string  $listener
     */
    protected function listenForSisterEvents(array $map, string $listener): void
    {
        foreach (array_keys($map) as $eventClass) {
            if (class_exists($eventClass)) {
                Event::listen($eventClass, $listener);
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
     *
     * Deliberately NOT wired (Task 3, broader event coverage):
     * - entry_unpublished: Statamic ships no EntryUnpublished event (verified
     *   against vendor/statamic/cms/src/Events/ — only EntryCreated/Saving/
     *   Saved/Deleting/Deleted exist). Unpublishing an entry fires EntrySaved
     *   like any other save, same as the existing entry_published semantics.
     * - submission_created: SubmissionCreated exists, but it wraps the same
     *   `submission` payload as FormSubmitted and both already map to the
     *   same HandleFormSubmitted listener / form_submitted trigger handle
     *   above — a separate trigger would just be a duplicate.
     */
    protected function registerEventListeners(): void
    {
        $listeners = [
            // Form submissions — Statamic v5 emits SubmissionCreated;
            // older versions used FormSubmitted. We listen to both.
            'Statamic\\Events\\SubmissionCreated' => HandleFormSubmitted::class,
            'Statamic\\Events\\FormSubmitted' => HandleFormSubmitted::class,
            // Entry publish trigger. Statamic ships no EntryPublished
            // event — publishing an entry fires EntrySaved. The listener
            // gates on $entry->published(), so entry_published fires only
            // for saves of published entries (same semantics as Webhook
            // Manager's entry.published trigger). Plain draft saves only
            // fire the generic entry_saved trigger below.
            'Statamic\\Events\\EntrySaved' => HandleEntryPublished::class,
        ];

        foreach ($listeners as $event => $listener) {
            if (class_exists($event)) {
                Event::listen($event, $listener);
            }
        }

        // Generic, registry-driven triggers — one closure per Statamic event
        // mapped to a trigger handle. The TriggerDispatcher finds matching
        // enabled automations, checks matches() and dispatches the run.
        $dispatched = [
            'Statamic\\Events\\EntrySaved' => 'entry_saved',
            'Statamic\\Events\\EntryCreated' => 'entry_created',
            'Statamic\\Events\\EntrySaving' => 'entry_saving',
            'Statamic\\Events\\EntryDeleted' => 'entry_deleted',
            'Statamic\\Events\\TermSaved' => 'term_saved',
            'Statamic\\Events\\TermDeleted' => 'term_deleted',
            'Statamic\\Events\\UserRegistered' => 'user_registered',
            'Statamic\\Events\\UserSaved' => 'user_saved',
            'Statamic\\Events\\UserDeleted' => 'user_deleted',
            'Statamic\\Events\\AssetUploaded' => 'asset_uploaded',
            'Statamic\\Events\\AssetSaved' => 'asset_saved',
            'Statamic\\Events\\AssetDeleted' => 'asset_deleted',
            'Statamic\\Events\\GlobalSetSaved' => 'global_set_saved',
            'Statamic\\Events\\NavSaved' => 'nav_saved',
        ];

        foreach ($dispatched as $event => $triggerHandle) {
            if (class_exists($event)) {
                Event::listen($event, function ($e) use ($triggerHandle) {
                    $this->app->make(TriggerDispatcher::class)
                        ->dispatch($triggerHandle, $e);
                });
            }
        }

        // Webhook Manager inbound bridge — listen to the configured inbound
        // event class and fan it into the webhook_received trigger.
        $inboundEvent = config('automations.integrations.webhook_manager.inbound_event');
        if (is_string($inboundEvent) && $inboundEvent !== '' && class_exists($inboundEvent)) {
            Event::listen($inboundEvent, function ($e) {
                $this->app->make(TriggerDispatcher::class)
                    ->dispatch('webhook_received', $e);
            });
        }

        // Webhook Manager outbound-failure bridge — same shape as the inbound
        // one. Without it the `webhook_manager.outbound_failed` trigger (and
        // the "Webhook Failure Alert" template built on it) is selectable in
        // the CP but never fires.
        $failedEvent = config('automations.integrations.webhook_manager.outbound_failed_event');
        if (is_string($failedEvent) && $failedEvent !== '' && class_exists($failedEvent)) {
            Event::listen($failedEvent, function ($e) {
                $this->app->make(TriggerDispatcher::class)
                    ->dispatch(WebhookDeliveryFailedTrigger::handle(), $e);
            });
        }
    }

    /**
     * Register Statamic permissions.
     */
    protected function registerPermissions(): void
    {
        if (! class_exists(Permission::class)) {
            return;
        }

        // Labels go through the addon's own namespaced dictionary, not raw
        // English and not the shared JSON file. The nav directly below has
        // always been translated; these nine rows were the one place a German
        // CP still showed English.
        Permission::group('automations', __('statamic-automations::automations.permissions.group'), function () {
            Permission::register('view automations')->label(__('statamic-automations::automations.permissions.view'));
            Permission::register('create automations')->label(__('statamic-automations::automations.permissions.create'));
            Permission::register('edit automations')->label(__('statamic-automations::automations.permissions.edit'));
            Permission::register('delete automations')->label(__('statamic-automations::automations.permissions.delete'));
            Permission::register('enable automations')->label(__('statamic-automations::automations.permissions.enable'));
            Permission::register('run automation tests')->label(__('statamic-automations::automations.permissions.test'));
            Permission::register('view automation runs')->label(__('statamic-automations::automations.permissions.view_runs'));
            Permission::register('retry automation runs')->label(__('statamic-automations::automations.permissions.retry_runs'));
            Permission::register('manage automation settings')->label(__('statamic-automations::automations.permissions.settings'));
        });
    }

    /**
     * Register the CP navigation entry.
     */
    protected function registerNavigation(): void
    {
        if (! class_exists(Nav::class)) {
            return;
        }

        Nav::extend(function ($nav) {
            $children = [
                $nav->item(__('Dashboard'))->route('statamic-automations.dashboard'),
                $nav->item(__('Automations'))->route('statamic-automations.automations.index'),
                $nav->item(__('Runs'))->route('statamic-automations.runs.index'),
            ];

            // `Mail rules`, not `Rules`: a bare CP word here would replace that
            // string for statamic/cms too. Same reason as `Automation
            // templates` below.
            //
            // Listed only while at least one automation actually is a rule. The
            // screen edits the automations that are one trigger and one mail;
            // where there are none, it is an empty page whose only link goes to
            // the canvas — so it read as a menu entry that does nothing but
            // redirect, and Adrian reported it as exactly that. It returns by
            // itself the moment a rule-shaped automation exists.
            if ($this->hasMailRules()) {
                $children[] = $nav->item(__('Mail rules'))->route('statamic-automations.rules.index');
            }

            $nav->create(__('Automations'))
                ->section(__('Tools'))
                ->route('statamic-automations.automations.index')
                ->icon('workflow')
                ->can('view automations')
                ->children([
                    ...$children,
                    $nav->item(__('Audit log'))->route('statamic-automations.audit'),
                    // `Automation templates`, not `Templates`: JSON string
                    // translations from every package merge into one Control
                    // Panel dictionary, so a key here replaces that string for
                    // statamic/cms too. `Templates` is Statamic's own word for
                    // Antlers views; ours is a different thing and needs a
                    // source string of its own rather than a translation that
                    // overrules the core's. See tests/Unit/TranslationKeyOwnershipTest.
                    $nav->item(__('Automation templates'))->route('statamic-automations.templates.index'),
                    $nav->item(__('Import'))->route('statamic-automations.import'),
                    $nav->item(__('Settings'))->route('statamic-automations.settings'),
                ]);
        });
    }

    /** Is any automation shaped like a rule? See {@see MailRules}. */
    protected function hasMailRules(): bool
    {
        return $this->app->make(MailRules::class)->any();
    }
}
