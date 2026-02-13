<?php

declare(strict_types=1);

use OpenScene\Engine\Application\PostService;
use OpenScene\Engine\Auth\Roles;
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

final class PostSoftDeleteIntegrationTest extends WP_UnitTestCase
{
    private wpdb $wpdb;
    private TableNames $tables;
    private PostRepository $posts;
    private PostController $controller;
    private ModerationRepository $moderation;

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
        $this->moderation = new ModerationRepository($this->wpdb, $this->tables);
        $votes = new VoteRepository($this->wpdb, $this->tables, $this->posts, $comments);
        $postService = new PostService($this->wpdb, $this->posts, $events);

        $this->controller = new PostController(
            new RateLimiter(),
            $postService,
            $this->posts,
            $votes,
            $this->moderation,
            new CacheManager()
        );
    }

    public function tearDown(): void
    {
        unset($_SERVER['HTTP_X_WP_NONCE']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_admin_can_soft_delete_post(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        [$postId] = $this->seedPost($adminId);

        $response = $this->deleteAs($adminId, $postId);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('removed', (string) ($response->get_data()['data']['status'] ?? ''));

        $post = $this->posts->find($postId);
        $this->assertSame('removed', (string) ($post['status'] ?? ''));
        $this->assertSame('[removed]', (string) ($post['title'] ?? ''));
        $this->assertSame('', (string) ($post['body'] ?? ''));
    }

    public function test_editor_can_soft_delete_post(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $editorId = self::factory()->user->create(['role' => 'editor']);

        $response = $this->deleteAs($editorId, $postId);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('removed', (string) ($response->get_data()['data']['status'] ?? ''));
    }

    public function test_subscriber_cannot_soft_delete_post(): void
    {
        $ownerId = self::factory()->user->create(['role' => 'subscriber']);
        [$postId] = $this->seedPost($ownerId);
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);

        $response = $this->deleteAs($subscriberId, $postId);
        $this->assertSame(403, $response->get_status());
    }

    public function test_double_delete_is_idempotent_without_duplicate_log(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        [$postId] = $this->seedPost($adminId);

        $first = $this->deleteAs($adminId, $postId);
        $second = $this->deleteAs($adminId, $postId);
        $this->assertSame(200, $first->get_status());
        $this->assertSame(200, $second->get_status());
        $this->assertTrue((bool) ($second->get_data()['data']['already_removed'] ?? false));

        $logsTable = $this->tables->moderationLogs();
        $logCount = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$logsTable} WHERE target_type = %s AND target_id = %d AND action = %s",
            'post',
            $postId,
            'delete'
        ));
        $this->assertSame(1, $logCount);
    }

    public function test_removed_post_is_not_editable(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        [$postId] = $this->seedPost($adminId);

        $this->deleteAs($adminId, $postId);
        wp_set_current_user($adminId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');

        $request = new WP_REST_Request('PATCH', '/openscene/v1/posts/' . $postId);
        $request->set_param('id', $postId);
        $request->set_param('type', 'text');
        $response = $this->controller->update($request);

        $this->assertSame(403, $response->get_status());
    }

    /** @return array{0:int,1:int} */
    private function seedPost(int $ownerId): array
    {
        $communityId = $this->seedCommunity($ownerId);
        $postId = $this->posts->create([
            'community_id' => $communityId,
            'user_id' => $ownerId,
            'title' => 'Soft delete target',
            'body' => 'content',
            'type' => 'text',
        ]);
        return [$postId, $communityId];
    }

    private function seedCommunity(int $ownerId): int
    {
        $repo = new CommunityRepository($this->wpdb, $this->tables);
        return $repo->create([
            'name' => 'Soft Delete',
            'slug' => 'soft-delete-' . wp_generate_password(6, false),
            'description' => 'tmp',
            'visibility' => 'public',
            'created_by' => $ownerId,
        ]);
    }

    private function deleteAs(int $userId, int $postId): WP_REST_Response
    {
        wp_set_current_user($userId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        $request = new WP_REST_Request('DELETE', '/openscene/v1/posts/' . $postId);
        $request->set_param('id', $postId);
        return $this->controller->delete($request);
    }
}

