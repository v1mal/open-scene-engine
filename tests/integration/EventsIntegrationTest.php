<?php

declare(strict_types=1);

use OpenScene\Engine\Application\PostService;
use OpenScene\Engine\Infrastructure\Database\MigrationRunner;
use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\Repository\EventRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;

final class EventsIntegrationTest extends WP_UnitTestCase
{
    private wpdb $wpdb;
    private TableNames $tables;
    private PostService $postService;
    private PostRepository $posts;
    private EventRepository $events;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->wpdb = $wpdb;

        (new MigrationRunner())->migrate();

        $this->tables = new TableNames();
        $this->posts = new PostRepository($this->wpdb, $this->tables);
        $this->events = new EventRepository($this->wpdb, $this->tables);
        $this->postService = new PostService($this->wpdb, $this->posts, $this->events);
    }

    public function test_create_event_post_persists_post_and_event(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);

        $postId = $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'Warehouse Session',
            'body' => 'Details',
            'event_date' => '2026-03-01T18:00:00Z',
            'venue_name' => 'Dock 9',
            'venue_address' => 'Peenya',
            'ticket_url' => 'https://example.com/tickets',
        ]);

        $this->assertGreaterThan(0, $postId);
        $post = $this->posts->find($postId);
        $this->assertNotNull($post);
        $this->assertSame('event', $post['type']);

        $event = $this->events->findByPostId($postId);
        $this->assertNotNull($event);
        $this->assertSame('Dock 9', $event['venue_name']);
    }

    public function test_event_validation_failure_does_not_create_post(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);

        $before = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables->posts()}");

        $this->expectException(RuntimeException::class);
        $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'Invalid Event',
            'body' => 'Missing required event_date',
            'venue_name' => 'Dock 9',
        ]);

        $after = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables->posts()}");
        $this->assertSame($before, $after);
    }

    public function test_event_insert_failure_rolls_back_post_creation(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);
        $eventsTable = $this->tables->events();
        $postsTable = $this->tables->posts();

        $before = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$postsTable}");
        $this->wpdb->query("DROP TABLE IF EXISTS {$eventsTable}");

        try {
            $this->postService->createPostWithOptionalEvent([
                'community_id' => $communityId,
                'user_id' => $userId,
                'type' => 'event',
                'title' => 'Rollback Event',
                'event_date' => '2032-01-01T10:00:00Z',
                'venue_name' => 'Rollback Venue',
            ]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('event', strtolower($e->getMessage()));
        } finally {
            update_option('openscene_db_version', '0');
            (new MigrationRunner())->migrate();
        }

        $after = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$postsTable}");
        $this->assertSame($before, $after);
    }

    public function test_upcoming_and_past_filters(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);

        $pastPost = $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'Past',
            'event_date' => '2024-01-01T10:00:00Z',
            'venue_name' => 'Old Venue',
        ]);

        $futurePost = $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'Future',
            'event_date' => '2099-01-01T10:00:00Z',
            'venue_name' => 'Future Venue',
        ]);

        $this->assertGreaterThan(0, $pastPost);
        $this->assertGreaterThan(0, $futurePost);

        $upcoming = $this->events->listByScope('upcoming', 20);
        $past = $this->events->listByScope('past', 20);

        $this->assertNotEmpty($upcoming);
        $this->assertNotEmpty($past);
    }

    public function test_feed_returns_event_summary_for_event_posts(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);

        $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'Feed Event',
            'event_date' => '2030-01-01T10:00:00Z',
            'venue_name' => 'Feed Venue',
        ]);

        $rows = $this->posts->feedByCursor('created_at', 20, null, $communityId);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('openscene_event_date', $rows[0]);
        $this->assertArrayHasKey('openscene_event_venue_name', $rows[0]);
    }

    public function test_event_cursor_pagination_is_composite_by_event_date_and_id(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);

        $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'E1',
            'event_date' => '2099-01-01T10:00:00Z',
            'venue_name' => 'V1',
        ]);

        $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'E2',
            'event_date' => '2099-01-01T11:00:00Z',
            'venue_name' => 'V2',
        ]);

        $page1 = $this->events->listByScope('upcoming', 1, null);
        $this->assertCount(1, $page1);

        $cursor = [
            'event_date' => (string) $page1[0]['event_date'],
            'id' => (int) $page1[0]['id'],
        ];

        $page2 = $this->events->listByScope('upcoming', 10, $cursor);
        $this->assertNotEmpty($page2);
        $this->assertNotSame((int) $page1[0]['id'], (int) $page2[0]['id']);
    }

    public function test_event_cleanup_when_post_type_changes_or_deleted(): void
    {
        $userId = self::factory()->user->create();
        $communityId = $this->seedCommunity($userId);

        $postId = $this->postService->createPostWithOptionalEvent([
            'community_id' => $communityId,
            'user_id' => $userId,
            'type' => 'event',
            'title' => 'Temporary Event',
            'event_date' => '2027-01-01T10:00:00Z',
            'venue_name' => 'Venue X',
        ]);

        $this->assertNotNull($this->events->findByPostId($postId));

        $this->postService->updatePostTypeAndEvent($postId, 'text');
        $this->assertNull($this->events->findByPostId($postId));

        $this->postService->deletePostAndLinkedEvent($postId);
        $post = $this->posts->find($postId);
        $this->assertSame('removed', $post['status']);
        $this->assertNull($this->events->findByPostId($postId));
    }

    private function seedCommunity(int $userId): int
    {
        $table = $this->tables->communities();
        $this->wpdb->insert($table, [
            'name' => 'Test Community',
            'slug' => 'test-community-' . wp_generate_password(6, false),
            'description' => '',
            'visibility' => 'public',
            'created_by_user_id' => $userId,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        return (int) $this->wpdb->insert_id;
    }
}
