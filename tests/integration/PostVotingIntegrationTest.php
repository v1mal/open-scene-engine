<?php

declare(strict_types=1);

use OpenScene\Engine\Application\PostService;
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

final class PostVotingIntegrationTest extends WP_UnitTestCase
{
    private wpdb $wpdb;
    private TableNames $tables;
    private PostRepository $posts;
    private VoteRepository $votes;
    private PostController $controller;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->wpdb = $wpdb;

        (new MigrationRunner())->migrate();

        $this->tables = new TableNames();
        $this->posts = new PostRepository($this->wpdb, $this->tables);
        $events = new EventRepository($this->wpdb, $this->tables);
        $comments = new CommentRepository($this->wpdb, $this->tables);
        $moderation = new ModerationRepository($this->wpdb, $this->tables);
        $this->votes = new VoteRepository($this->wpdb, $this->tables, $this->posts, $comments);
        $postService = new PostService($this->wpdb, $this->posts, $events);

        $this->controller = new PostController(
            new RateLimiter(),
            $postService,
            $this->posts,
            $this->votes,
            $moderation,
            new CacheManager()
        );
    }

    public function tearDown(): void
    {
        unset($_SERVER['HTTP_X_WP_NONCE']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_upvote_new_post_increments_score(): void
    {
        [$userId, $postId] = $this->seedVoteContext();

        $response = $this->voteAs($userId, $postId, 1);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, (int) $response->get_data()['data']['score']);
        $this->assertSame(1, (int) $response->get_data()['data']['user_vote']);
    }

    public function test_downvote_new_post_decrements_score(): void
    {
        [$userId, $postId] = $this->seedVoteContext();

        $response = $this->voteAs($userId, $postId, -1);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(-1, (int) $response->get_data()['data']['score']);
        $this->assertSame(-1, (int) $response->get_data()['data']['user_vote']);
    }

    public function test_switching_upvote_to_downvote_applies_minus_two_delta(): void
    {
        [$userId, $postId] = $this->seedVoteContext();

        $this->voteAs($userId, $postId, 1);
        $response = $this->voteAs($userId, $postId, -1);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(-1, (int) $response->get_data()['data']['score']);
        $this->assertSame(-1, (int) $response->get_data()['data']['user_vote']);
    }

    public function test_clicking_same_vote_twice_removes_vote(): void
    {
        [$userId, $postId] = $this->seedVoteContext();

        $this->voteAs($userId, $postId, 1);
        $response = $this->voteAs($userId, $postId, 1);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, (int) $response->get_data()['data']['score']);
        $this->assertSame(0, (int) $response->get_data()['data']['user_vote']);
    }

    public function test_remove_vote_resets_score_correctly(): void
    {
        [$userId, $postId] = $this->seedVoteContext();

        $this->voteAs($userId, $postId, -1);
        $response = $this->voteAs($userId, $postId, 0);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, (int) $response->get_data()['data']['score']);
        $this->assertSame(0, (int) $response->get_data()['data']['user_vote']);
    }

    public function test_banned_user_vote_returns_403(): void
    {
        [$userId, $postId] = $this->seedVoteContext();
        update_user_meta($userId, 'openscene_banned', 1);

        $response = $this->voteAs($userId, $postId, 1);
        $this->assertSame(403, $response->get_status());
    }

    public function test_anonymous_vote_returns_403(): void
    {
        [, $postId] = $this->seedVoteContext();
        wp_set_current_user(0);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');

        $request = new WP_REST_Request('POST', '/openscene/v1/posts/' . $postId . '/vote');
        $request->set_param('id', $postId);
        $request->set_param('value', 1);
        $response = $this->controller->vote($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_repeated_vote_actions_do_not_create_duplicate_rows(): void
    {
        [$userId, $postId] = $this->seedVoteContext();

        $this->voteAs($userId, $postId, 1);
        $this->voteAs($userId, $postId, -1);
        $this->voteAs($userId, $postId, 1);

        $votesTable = $this->tables->votes();
        $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$votesTable} WHERE user_id = %d AND target_type = 'post' AND target_id = %d",
            $userId,
            $postId
        ));
        $this->assertLessThanOrEqual(1, $count);
    }

    public function test_two_users_voting_yields_consistent_score(): void
    {
        [$userA, $postId] = $this->seedVoteContext();
        $userB = self::factory()->user->create(['role' => 'subscriber']);
        $this->grantVoteCap($userB);

        $this->voteAs($userA, $postId, 1);
        $response = $this->voteAs($userB, $postId, 1);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, (int) $response->get_data()['data']['score']);
    }

    /** @return array{0:int,1:int} */
    private function seedVoteContext(): array
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $this->grantVoteCap($userId);
        $communityId = $this->seedCommunity($userId);
        $postId = $this->posts->create([
            'community_id' => $communityId,
            'user_id' => $userId,
            'title' => 'Vote test post',
            'body' => 'Body',
            'type' => 'text',
        ]);
        $this->assertGreaterThan(0, $postId);

        return [$userId, $postId];
    }

    private function seedCommunity(int $ownerId): int
    {
        $repo = new CommunityRepository($this->wpdb, $this->tables);
        return $repo->create([
            'name' => 'Votes',
            'slug' => 'votes-' . wp_generate_password(6, false),
            'description' => 'Votes community',
            'visibility' => 'public',
            'created_by' => $ownerId,
        ]);
    }

    private function grantVoteCap(int $userId): void
    {
        $user = get_user_by('id', $userId);
        if ($user instanceof WP_User) {
            $user->add_cap('openscene_vote');
        }
    }

    private function voteAs(int $userId, int $postId, int $value): WP_REST_Response
    {
        wp_set_current_user($userId);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');

        $request = new WP_REST_Request('POST', '/openscene/v1/posts/' . $postId . '/vote');
        $request->set_param('id', $postId);
        $request->set_param('value', $value);

        return $this->controller->vote($request);
    }
}
