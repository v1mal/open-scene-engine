<?php

declare(strict_types=1);

use OpenScene\Engine\Application\PostService;
use OpenScene\Engine\Auth\Roles;
use OpenScene\Engine\Http\Rest\ModerationController;
use OpenScene\Engine\Http\Rest\PostController;
use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Database\MigrationRunner;
use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use OpenScene\Engine\Infrastructure\Repository\CommentRepository;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use OpenScene\Engine\Infrastructure\Repository\EventRepository;
use OpenScene\Engine\Infrastructure\Repository\ModerationRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\VoteRepository;

final class ReportingModerationIntegrationTest extends WP_UnitTestCase
{
    private wpdb $wpdb;
    private TableNames $tables;
    private PostRepository $posts;
    private PostController $postController;
    private ModerationController $moderationController;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->wpdb = $wpdb;

        (new MigrationRunner())->migrate();
        Roles::register();

        $this->tables = new TableNames();
        $this->posts = new PostRepository($this->wpdb, $this->tables);
        $events = new EventRepository($this->wpdb, $this->tables);
        $comments = new CommentRepository($this->wpdb, $this->tables);
        $moderation = new ModerationRepository($this->wpdb, $this->tables);
        $votes = new VoteRepository($this->wpdb, $this->tables, $this->posts, $comments);
        $postService = new PostService($this->wpdb, $this->posts, $events);

        $rateLimiter = new RateLimiter();
        $this->postController = new PostController($rateLimiter, $postService, $this->posts, $votes, $moderation, new CacheManager());
        $this->moderationController = new ModerationController($rateLimiter, $moderation, $this->posts);
    }

    public function tearDown(): void
    {
        unset($_SERVER['HTTP_X_WP_NONCE']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_user_can_report_post(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($userId);
        $reporterId = self::factory()->user->create(['role' => 'subscriber']);

        $res = $this->reportAs($reporterId, $postId);
        $this->assertSame(200, $res->get_status());
        $this->assertTrue((bool) ($res->get_data()['data']['reported'] ?? false));
        $this->assertSame(1, (int) ($res->get_data()['data']['reports_count'] ?? 0));
    }

    public function test_duplicate_report_prevented(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $reporterId = self::factory()->user->create(['role' => 'subscriber']);

        $this->reportAs($reporterId, $postId);
        $second = $this->reportAs($reporterId, $postId);

        $reportsTable = $this->tables->postReports();
        $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$reportsTable} WHERE post_id = %d AND user_id = %d",
            $postId,
            $reporterId
        ));
        $this->assertSame(1, $count);
        $this->assertSame(1, (int) ($second->get_data()['data']['reports_count'] ?? 0));
    }

    public function test_reports_count_increments_correctly(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $r1 = self::factory()->user->create(['role' => 'subscriber']);
        $r2 = self::factory()->user->create(['role' => 'subscriber']);

        $this->reportAs($r1, $postId);
        $second = $this->reportAs($r2, $postId);
        $this->assertSame(2, (int) ($second->get_data()['data']['reports_count'] ?? 0));
    }

    public function test_moderator_can_clear_reports(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $reporter = self::factory()->user->create(['role' => 'subscriber']);
        $this->reportAs($reporter, $postId);

        $modId = self::factory()->user->create(['role' => 'editor']);
        $res = $this->clearReportsAs($modId, $postId);
        $this->assertSame(200, $res->get_status());
        $this->assertTrue((bool) ($res->get_data()['data']['cleared'] ?? false));
        $this->assertSame(0, (int) ($res->get_data()['data']['reports_count'] ?? 1));
    }

    public function test_non_moderator_cannot_access_moderation_endpoint(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        $req = new WP_REST_Request('GET', '/openscene/v1/moderation');
        $req->set_param('view', 'all');
        $res = $this->moderationController->list($req);
        $this->assertSame(403, $res->get_status());
    }

    public function test_removed_post_resets_reports_count(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $reporter = self::factory()->user->create(['role' => 'subscriber']);
        $this->reportAs($reporter, $postId);

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $this->deletePostAs($adminId, $postId);

        $post = $this->posts->find($postId);
        $this->assertSame('removed', (string) ($post['status'] ?? ''));
        $this->assertSame(0, (int) ($post['reports_count'] ?? 99));
    }

    public function test_banned_user_cannot_report(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $reporter = self::factory()->user->create(['role' => 'subscriber']);
        update_user_meta($reporter, 'openscene_banned', 1);

        $res = $this->reportAs($reporter, $postId);
        $this->assertSame(403, $res->get_status());
    }

    /** @return array{0:int,1:int} */
    private function seedPost(int $ownerId): array
    {
        $communityId = $this->seedCommunity($ownerId);
        $postId = $this->posts->create([
            'community_id' => $communityId,
            'user_id' => $ownerId,
            'title' => 'Report target',
            'body' => 'content',
            'type' => 'text',
        ]);
        return [$postId, $communityId];
    }

    private function seedCommunity(int $ownerId): int
    {
        $repo = new CommunityRepository($this->wpdb, $this->tables);
        return $repo->create([
            'name' => 'Report Tests',
            'slug' => 'report-tests-' . wp_generate_password(6, false),
            'description' => 'tmp',
            'visibility' => 'public',
            'created_by' => $ownerId,
        ]);
    }

    private function reportAs(int $userId, int $postId): WP_REST_Response
    {
        wp_set_current_user($userId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        $request = new WP_REST_Request('POST', '/openscene/v1/posts/' . $postId . '/report');
        $request->set_param('id', $postId);
        return $this->postController->report($request);
    }

    private function clearReportsAs(int $userId, int $postId): WP_REST_Response
    {
        wp_set_current_user($userId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        $request = new WP_REST_Request('POST', '/openscene/v1/posts/' . $postId . '/clear-reports');
        $request->set_param('id', $postId);
        return $this->postController->clearReports($request);
    }

    private function deletePostAs(int $userId, int $postId): WP_REST_Response
    {
        wp_set_current_user($userId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        $request = new WP_REST_Request('DELETE', '/openscene/v1/posts/' . $postId);
        $request->set_param('id', $postId);
        return $this->postController->delete($request);
    }
}

