<?php

declare(strict_types=1);

namespace OpenScene\Engine;

use OpenScene\Engine\Admin\AdminController;
use OpenScene\Engine\Application\CommunityBootstrap;
use OpenScene\Engine\Application\PostService;
use OpenScene\Engine\Application\Scheduler;
use OpenScene\Engine\Auth\Roles;
use OpenScene\Engine\Http\AdminAssets;
use OpenScene\Engine\Http\Assets;
use OpenScene\Engine\Http\RegistrationGuard;
use OpenScene\Engine\Http\Rest\CommentController;
use OpenScene\Engine\Http\Rest\CommunityController;
use OpenScene\Engine\Http\Rest\EventController;
use OpenScene\Engine\Http\Rest\FeedController;
use OpenScene\Engine\Http\Rest\ModerationController;
use OpenScene\Engine\Http\Rest\PostController;
use OpenScene\Engine\Http\Rest\RestRegistrar;
use OpenScene\Engine\Http\Rest\UserController;
use OpenScene\Engine\Http\Rest\VoteController;
use OpenScene\Engine\Http\Shortcode;
use OpenScene\Engine\Http\TemplateLoader;
use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Database\MigrationRunner;
use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use OpenScene\Engine\Infrastructure\Repository\CommentRepository;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use OpenScene\Engine\Infrastructure\Repository\EventRepository;
use OpenScene\Engine\Infrastructure\Repository\ModerationRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\UserRepository;
use OpenScene\Engine\Infrastructure\Repository\VoteRepository;
use OpenScene\Engine\Routing\RewriteManager;

final class Plugin
{
    public function boot(): void
    {
        Roles::syncRuntimeCaps();
        add_action('init', static function (): void {
            Roles::syncRuntimeCaps();
        }, 1);
        $container = $this->container();
        add_action('set_user_role', static function (int $userId): void {
            clean_user_cache($userId);
        }, 10, 1);
        add_action('add_user_role', static function (int $userId): void {
            clean_user_cache($userId);
        }, 10, 1);
        add_action('remove_user_role', static function (int $userId): void {
            clean_user_cache($userId);
        }, 10, 1);

        /** @var CommunityBootstrap $communityBootstrap */
        $communityBootstrap = $container->get(CommunityBootstrap::class);
        $communityBootstrap->ensureDefaults();

        /** @var RewriteManager $rewrite */
        $rewrite = $container->get(RewriteManager::class);
        $rewrite->hooks();

        /** @var Shortcode $shortcode */
        $shortcode = $container->get(Shortcode::class);
        $shortcode->hooks();

        /** @var TemplateLoader $templates */
        $templates = $container->get(TemplateLoader::class);
        $templates->hooks();

        /** @var Assets $assets */
        $assets = $container->get(Assets::class);
        $assets->hooks();

        /** @var RestRegistrar $rest */
        $rest = $container->get(RestRegistrar::class);
        $rest->hooks();

        /** @var Scheduler $scheduler */
        $scheduler = $container->get(Scheduler::class);
        $scheduler->hooks();

        /** @var AdminController $admin */
        $admin = $container->get(AdminController::class);
        $admin->hooks();

        /** @var AdminAssets $adminAssets */
        $adminAssets = $container->get(AdminAssets::class);
        $adminAssets->hooks();

        /** @var RegistrationGuard $registrationGuard */
        $registrationGuard = $container->get(RegistrationGuard::class);
        $registrationGuard->hooks();
    }

    public static function activate(): void
    {
        (new MigrationRunner())->migrate();
        Roles::register();
        (new RewriteManager())->registerRules();
        self::ensureOpenScenePage();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        Roles::unregister();
        wp_clear_scheduled_hook('openscene_reconcile_aggregates');
        wp_clear_scheduled_hook('openscene_orphan_cleanup');
        flush_rewrite_rules(false);
    }

    private static function ensureOpenScenePage(): void
    {
        $existingId = (int) get_option('openscene_page_id', 0);
        if ($existingId > 0 && get_post($existingId) instanceof \WP_Post) {
            return;
        }

        $found = get_page_by_path('openscene', OBJECT, 'page');
        if ($found instanceof \WP_Post) {
            update_option('openscene_page_id', (int) $found->ID, false);
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'OpenScene',
            'post_name' => 'openscene',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '[openscene_app]',
        ], true);

        if (! is_wp_error($pageId)) {
            update_option('openscene_page_id', (int) $pageId, false);
        }
    }

    private function container(): Container
    {
        global $wpdb;

        $container = new Container();

        $container->set(TableNames::class, fn(): TableNames => new TableNames());
        $container->set(MigrationRunner::class, fn(): MigrationRunner => new MigrationRunner());
        $container->set(CacheManager::class, fn(): CacheManager => new CacheManager());
        $container->set(RateLimiter::class, fn(): RateLimiter => new RateLimiter());

        $container->set(CommunityRepository::class, fn(Container $c): CommunityRepository => new CommunityRepository($wpdb, $c->get(TableNames::class)));
        $container->set(PostRepository::class, fn(Container $c): PostRepository => new PostRepository($wpdb, $c->get(TableNames::class)));
        $container->set(EventRepository::class, fn(Container $c): EventRepository => new EventRepository($wpdb, $c->get(TableNames::class)));
        $container->set(CommentRepository::class, fn(Container $c): CommentRepository => new CommentRepository($wpdb, $c->get(TableNames::class)));
        $container->set(UserRepository::class, fn(Container $c): UserRepository => new UserRepository($wpdb, $c->get(TableNames::class)));
        $container->set(ModerationRepository::class, fn(Container $c): ModerationRepository => new ModerationRepository($wpdb, $c->get(TableNames::class)));
        $container->set(VoteRepository::class, fn(Container $c): VoteRepository => new VoteRepository($wpdb, $c->get(TableNames::class), $c->get(PostRepository::class), $c->get(CommentRepository::class)));

        $container->set(RewriteManager::class, fn(): RewriteManager => new RewriteManager());
        $container->set(Shortcode::class, fn(): Shortcode => new Shortcode());
        $container->set(TemplateLoader::class, fn(): TemplateLoader => new TemplateLoader());
        $container->set(Assets::class, fn(Container $c): Assets => new Assets($c->get(TemplateLoader::class)));
        $container->set(Scheduler::class, fn(): Scheduler => new Scheduler());
        $container->set(PostService::class, fn(Container $c): PostService => new PostService($wpdb, $c->get(PostRepository::class), $c->get(EventRepository::class)));
        $container->set(AdminController::class, fn(Container $c): AdminController => new AdminController(
            $c->get(CommunityRepository::class),
            $c->get(TableNames::class),
            $c->get(CacheManager::class),
            $wpdb
        ));
        $container->set(AdminAssets::class, fn(): AdminAssets => new AdminAssets());
        $container->set(RegistrationGuard::class, fn(): RegistrationGuard => new RegistrationGuard());
        $container->set(CommunityBootstrap::class, fn(Container $c): CommunityBootstrap => new CommunityBootstrap(
            $c->get(CommunityRepository::class),
            $c->get(CacheManager::class)
        ));

        $container->set(FeedController::class, fn(Container $c): FeedController => new FeedController($c->get(RateLimiter::class), $c->get(PostRepository::class), $c->get(VoteRepository::class), $c->get(CacheManager::class)));
        $container->set(CommunityController::class, fn(Container $c): CommunityController => new CommunityController($c->get(RateLimiter::class), $c->get(CommunityRepository::class), $c->get(PostRepository::class), $c->get(CacheManager::class)));
        $container->set(PostController::class, fn(Container $c): PostController => new PostController($c->get(RateLimiter::class), $c->get(PostService::class), $c->get(PostRepository::class), $c->get(VoteRepository::class), $c->get(ModerationRepository::class), $c->get(CacheManager::class)));
        $container->set(EventController::class, fn(Container $c): EventController => new EventController($c->get(RateLimiter::class), $c->get(EventRepository::class), $c->get(CacheManager::class)));
        $container->set(CommentController::class, fn(Container $c): CommentController => new CommentController($c->get(RateLimiter::class), $c->get(CommentRepository::class), $c->get(PostRepository::class), $c->get(ModerationRepository::class), $c->get(CacheManager::class)));
        $container->set(VoteController::class, fn(Container $c): VoteController => new VoteController($c->get(RateLimiter::class), $c->get(VoteRepository::class), $c->get(CacheManager::class)));
        $container->set(UserController::class, fn(Container $c): UserController => new UserController($c->get(RateLimiter::class), $c->get(UserRepository::class)));
        $container->set(ModerationController::class, fn(Container $c): ModerationController => new ModerationController($c->get(RateLimiter::class), $c->get(ModerationRepository::class), $c->get(PostRepository::class)));

        $container->set(RestRegistrar::class, fn(Container $c): RestRegistrar => new RestRegistrar(
            $c->get(FeedController::class),
            $c->get(CommunityController::class),
            $c->get(PostController::class),
            $c->get(EventController::class),
            $c->get(CommentController::class),
            $c->get(VoteController::class),
            $c->get(UserController::class),
            $c->get(ModerationController::class),
        ));

        return $container;
    }
}
