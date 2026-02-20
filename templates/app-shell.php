<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$ssrData = get_query_var('openscene_ssr_data', []);
if (! is_array($ssrData)) {
    $ssrData = [];
}
$context = is_array($ssrData['context'] ?? null)
    ? $ssrData['context']
    : [
        'route' => (string) get_query_var('openscene_route', 'page'),
        'communitySlug' => (string) get_query_var('openscene_community_slug', ''),
        'postId' => (int) get_query_var('openscene_post_id', 0),
        'username' => strtolower(sanitize_user((string) get_query_var('openscene_username', ''), true)),
    ];
$ssrPost = is_array($ssrData['post'] ?? null) ? $ssrData['post'] : null;
$ssrPostCommunityVisible = (bool) ($ssrData['post_community_visible'] ?? true);
$ssrCommunity = is_array($ssrData['community'] ?? null) ? $ssrData['community'] : null;
$ssrPostUserVote = (int) ($ssrData['post_user_vote'] ?? 0);
$ssrPostUserReported = (bool) ($ssrData['post_user_reported'] ?? false);
$communityRows = is_array($ssrData['community_rows'] ?? null) ? $ssrData['community_rows'] : [];
$eventRows = is_array($ssrData['event_rows'] ?? null) ? $ssrData['event_rows'] : [];
$rules = is_array($ssrData['rules'] ?? null) ? $ssrData['rules'] : [];

$routeUsername = strtolower(sanitize_user((string) $context['username'], true));
$ssrUser = ($ssrData['user'] ?? null) instanceof \WP_User ? $ssrData['user'] : null;

$postStatus = is_array($ssrPost) ? (string) ($ssrPost['status'] ?? '') : '';
$isRenderablePost = is_array($ssrPost) && in_array($postStatus, ['published', 'locked', 'removed'], true);
$isPostInDisabledCommunity = $context['route'] === 'post' && $isRenderablePost && ! $ssrPostCommunityVisible;
$isDisabledCommunityRoute = $context['route'] === 'community' && is_array($ssrCommunity) && (string) ($ssrCommunity['visibility'] ?? '') !== 'public';
$isUnavailableContent = $isPostInDisabledCommunity || $isDisabledCommunityRoute;
$isRemovedPost = $isRenderablePost && $postStatus === 'removed';
$isPost404 = $context['route'] === 'post' && ! $isRenderablePost;
$isValidUser = $ssrUser instanceof \WP_User && (int) ($ssrUser->user_status ?? 0) === 0;
$isUser404 = $context['route'] === 'user' && ! $isValidUser;
$isModeratorRoute = $context['route'] === 'moderator';
$canModerate = current_user_can('openscene_moderate');
$canonicalUrl = $isRenderablePost ? home_url(user_trailingslashit('post/' . (int) $context['postId'])) : '';
if ($isValidUser) {
    $canonicalUrl = home_url(user_trailingslashit('u/' . rawurlencode($routeUsername)));
}

if ($canonicalUrl !== '') {
    remove_action('wp_head', 'rel_canonical');
}

$currentUser = wp_get_current_user();
$currentUsername = ($currentUser instanceof \WP_User && $currentUser->ID > 0) ? strtolower(sanitize_user((string) $currentUser->user_login, true)) : '';
$isLoggedIn = $currentUsername !== '';
$avatarInitials = $currentUsername !== '' ? strtoupper(substr($currentUsername, 0, 2)) : 'GU';
$profileHref = $currentUsername !== '' ? home_url(user_trailingslashit('u/' . rawurlencode($currentUsername))) : wp_login_url();
$logoutHref = wp_logout_url(home_url('/openscene/'));
$joinUrl = (string) get_option('openscene_join_url', '');
$adminSettings = get_option('openscene_admin_settings', []);
$logoAttachmentId = is_array($adminSettings) ? (int) ($adminSettings['logo_attachment_id'] ?? 0) : 0;
$logoUrl = $logoAttachmentId > 0 ? (string) wp_get_attachment_image_url($logoAttachmentId, 'full') : '';
$brandTextRaw = is_array($adminSettings) ? trim((string) ($adminSettings['brand_text'] ?? '')) : '';
$brandText = $brandTextRaw !== '' ? $brandTextRaw : 'scene.wtf';
$brandDotPos = strpos($brandText, '.');
$brandPrimary = $brandDotPos === false ? $brandText : substr($brandText, 0, $brandDotPos);
$brandSuffix = $brandDotPos === false ? '' : substr($brandText, $brandDotPos);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <?php wp_head(); ?>
    <?php if ($canonicalUrl !== '') : ?>
    <link rel="canonical" href="<?php echo esc_url($canonicalUrl); ?>" />
    <?php endif; ?>
</head>
<body <?php body_class('openscene-app-shell'); ?>>
<?php wp_body_open(); ?>
<?php if ($isUnavailableContent) : ?>
<div class="ose-unavailable">
    <header class="ose-unavailable-header">
        <a href="/openscene/" class="ose-unavailable-brand">
            <?php if ($logoUrl !== '') : ?>
                <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php esc_attr_e('OpenScene Logo', 'open-scene-engine'); ?>" class="ose-brand-logo" />
            <?php else : ?>
                <?php echo esc_html($brandPrimary); ?><?php if ($brandSuffix !== '') : ?><span><?php echo esc_html($brandSuffix); ?></span><?php endif; ?>
            <?php endif; ?>
        </a>
    </header>
    <main class="ose-unavailable-main">
        <p class="ose-unavailable-message"><?php esc_html_e('This content is currently unavailable.', 'open-scene-engine'); ?></p>
        <a href="/openscene/" class="ose-unavailable-back"><?php esc_html_e('Go Back', 'open-scene-engine'); ?></a>
    </main>
</div>
<?php elseif ($isRenderablePost) : ?>
<div id="openscene-root" data-openscene-context="<?php echo esc_attr((string) wp_json_encode($context)); ?>">
<div class="ose-scene-home ose-post-detail-page">
    <header class="ose-topbar">
        <div class="ose-topbar-left">
            <a class="ose-brand" href="/openscene/">
                <?php if ($logoUrl !== '') : ?>
                    <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php esc_attr_e('OpenScene Logo', 'open-scene-engine'); ?>" class="ose-brand-logo" />
                <?php else : ?>
                    <?php echo esc_html($brandPrimary); ?><?php if ($brandSuffix !== '') : ?><span><?php echo esc_html($brandSuffix); ?></span><?php endif; ?>
                <?php endif; ?>
            </a>
            <nav class="ose-topbar-nav" aria-label="<?php esc_attr_e('Primary', 'open-scene-engine'); ?>">
                <a href="/openscene/"><?php esc_html_e('Feed', 'open-scene-engine'); ?></a>
                <a href="/openscene/?view=communities"><?php esc_html_e('Communities', 'open-scene-engine'); ?></a>
                <a href="/openscene/?view=artists"><?php esc_html_e('Artists', 'open-scene-engine'); ?></a>
            </nav>
            <div class="ose-search">
                <i data-lucide="search" class="ose-lucide ose-search-icon"></i>
                <input type="search" placeholder="<?php esc_attr_e('Search conversations...', 'open-scene-engine'); ?>" aria-label="<?php esc_attr_e('Search conversations', 'open-scene-engine'); ?>" />
            </div>
        </div>
        <div class="ose-topbar-right">
            <?php if ($isLoggedIn) : ?>
            <button class="ose-icon-btn" type="button" aria-label="<?php esc_attr_e('Notifications', 'open-scene-engine'); ?>">
                <i data-lucide="bell" class="ose-lucide"></i>
            </button>
            <details class="ose-avatar-menu">
                <summary class="ose-avatar-summary" aria-label="<?php esc_attr_e('Open profile menu', 'open-scene-engine'); ?>">
                    <span class="ose-avatar ose-avatar-icon"><i data-lucide="circle-user-round" class="ose-lucide"></i></span>
                </summary>
                <div class="ose-avatar-dropdown">
                    <a href="<?php echo esc_url($profileHref); ?>"><?php esc_html_e('Profile', 'open-scene-engine'); ?></a>
                    <?php if ($canModerate) : ?>
                    <a href="/moderator/"><?php esc_html_e('Moderator Panel', 'open-scene-engine'); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($logoutHref); ?>"><?php esc_html_e('Log out', 'open-scene-engine'); ?></a>
                </div>
            </details>
            <?php elseif ($joinUrl !== '') : ?>
            <a class="ose-join-btn" href="<?php echo esc_url($joinUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('JOIN', 'open-scene-engine'); ?></a>
            <?php endif; ?>
        </div>
    </header>
    <div class="ose-scene-grid">
        <aside class="ose-left">
            <h3 class="ose-side-title"><?php esc_html_e('Communities', 'open-scene-engine'); ?></h3>
            <nav class="ose-community-list">
                <?php foreach ($communityRows as $communityRow) : ?>
                <?php $communityIcon = trim((string) ($communityRow['icon'] ?? '')); ?>
                <a class="ose-community-item" href="<?php echo esc_url(home_url('/c/' . rawurlencode((string) ($communityRow['slug'] ?? '')))); ?>">
                    <span class="ose-community-name"><i data-lucide="<?php echo esc_attr($communityIcon !== '' ? $communityIcon : 'music-4'); ?>" class="ose-lucide ose-community-icon"></i><?php echo esc_html((string) ($communityRow['name'] ?? '')); ?></span>
                </a>
                <?php endforeach; ?>
                <a class="ose-community-item ose-community-view-all" href="/openscene/?view=communities">
                    <span class="ose-community-name"><i data-lucide="plus" class="ose-lucide ose-community-icon"></i><?php esc_html_e('View All', 'open-scene-engine'); ?></span>
                </a>
            </nav>
        </aside>
        <main class="ose-center ose-center-scroll">
            <section class="ose-center-pane ose-post-detail-main">
                <a class="ose-pd-back" href="/openscene/">
                    <i data-lucide="arrow-left" class="ose-lucide"></i>
                    <?php esc_html_e('Back to Feed', 'open-scene-engine'); ?>
                </a>
                <header class="ose-pd-header">
                    <?php if (! $isRemovedPost) : ?>
                    <div
                        id="openscene-post-vote"
                        class="ose-pd-post-vote"
                        data-post-id="<?php echo esc_attr((string) (int) ($ssrPost['id'] ?? 0)); ?>"
                        data-score="<?php echo esc_attr((string) (int) ($ssrPost['score'] ?? 0)); ?>"
                        data-user-vote="<?php echo esc_attr((string) $ssrPostUserVote); ?>"
                    >
                        <button type="button" class="ose-pd-post-vote-up" aria-label="<?php esc_attr_e('Upvote', 'open-scene-engine'); ?>"><i data-lucide="chevron-up" class="ose-lucide"></i></button>
                        <strong class="ose-pd-post-vote-score"><?php echo esc_html((string) (int) ($ssrPost['score'] ?? 0)); ?></strong>
                        <button type="button" class="ose-pd-post-vote-down" aria-label="<?php esc_attr_e('Downvote', 'open-scene-engine'); ?>"><i data-lucide="chevron-down" class="ose-lucide"></i></button>
                    </div>
                    <?php endif; ?>
                    <div class="ose-pd-meta">
                        <span class="ose-pd-author"><?php echo esc_html('user_' . (int) ($ssrPost['user_id'] ?? 0)); ?></span>
                        <span class="ose-pd-dot">•</span>
                        <time class="ose-time-ago" datetime="<?php echo esc_attr((string) ($ssrPost['created_at'] ?? '')); ?>">
                            <?php echo esc_html(human_time_diff(strtotime((string) ($ssrPost['created_at'] ?? 'now')), current_time('timestamp', true)) . ' ago'); ?>
                        </time>
                    </div>
                    <?php if ($isRemovedPost) : ?>
                    <h1>[removed]</h1>
                    <article class="ose-pd-body"></article>
                    <?php else : ?>
                    <h1><?php echo esc_html((string) ($ssrPost['title'] ?? 'Untitled')); ?></h1>
                    <article class="ose-pd-body"><?php echo wp_kses_post((string) ($ssrPost['body'] ?? '')); ?></article>
                    <?php endif; ?>
                </header>
                <div
                    id="openscene-comments-root"
                    data-initial-comment-count="<?php echo esc_attr((string) ((int) ($ssrPost['comment_count'] ?? 0))); ?>"
                    data-post-status="<?php echo esc_attr($postStatus); ?>"
                    data-post-user-id="<?php echo esc_attr((string) ((int) ($ssrPost['user_id'] ?? 0))); ?>"
                    data-user-reported="<?php echo esc_attr($ssrPostUserReported ? '1' : '0'); ?>"
                ></div>
            </section>
        </main>
        <aside class="ose-right">
            <div class="ose-right-rail">
                <section class="ose-widget">
                    <div class="ose-widget-head">
                        <h3><?php esc_html_e('Upcoming Bangalore Events', 'open-scene-engine'); ?></h3>
                        <span class="ose-widget-icon ose-widget-icon-events"><i data-lucide="calendar-days" class="ose-lucide"></i></span>
                    </div>
                    <div class="ose-events">
                        <?php if (! empty($eventRows)) : ?>
                            <?php foreach ($eventRows as $eventRow) : ?>
                                <?php
                                $eventDateRaw = (string) ($eventRow['event_date'] ?? '');
                                $eventTimestamp = strtotime($eventDateRaw . ' UTC');
                                $eventMonth = $eventTimestamp ? strtoupper(gmdate('M', $eventTimestamp)) : 'NA';
                                $eventDay = $eventTimestamp ? gmdate('d', $eventTimestamp) : '--';
                                $eventTitle = (string) ($eventRow['title'] ?? 'Untitled event');
                                $eventVenue = (string) ($eventRow['venue_name'] ?? ($eventRow['venue_address'] ?? __('Venue TBA', 'open-scene-engine')));
                                $eventTime = $eventTimestamp ? gmdate('M j, Y H:i', $eventTimestamp) . ' UTC' : '';
                                ?>
                            <article class="ose-event">
                                <div class="ose-event-date">
                                    <span class="ose-event-month"><?php echo esc_html($eventMonth); ?></span>
                                    <span class="ose-event-day"><?php echo esc_html($eventDay); ?></span>
                                </div>
                                <div>
                                    <h4><a href="<?php echo esc_url('/openscene/?view=event&id=' . (int) ($eventRow['id'] ?? 0)); ?>"><?php echo esc_html($eventTitle); ?></a></h4>
                                    <p><?php echo esc_html(trim($eventVenue . ($eventTime !== '' ? ' · ' . $eventTime : ''))); ?></p>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p class="ose-events-note"><?php esc_html_e('No upcoming events.', 'open-scene-engine'); ?></p>
                        <?php endif; ?>
                    </div>
                    <a class="ose-widget-btn" href="/openscene/?view=events"><?php esc_html_e('View Calendar', 'open-scene-engine'); ?></a>
                </section>
                <section class="ose-widget">
                    <div class="ose-widget-head">
                        <h3><?php esc_html_e('Community Rules', 'open-scene-engine'); ?></h3>
                    </div>
                    <ol class="ose-rules">
                        <?php foreach ($rules as $index => $rule) : ?>
                        <li>
                            <span><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                            <p><?php echo esc_html($rule); ?></p>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
                <footer class="ose-footer">
                    <nav>
                        <a href="#"><?php esc_html_e('About', 'open-scene-engine'); ?></a>
                        <a href="#"><?php esc_html_e('Guidelines', 'open-scene-engine'); ?></a>
                        <a href="#"><?php esc_html_e('Privacy', 'open-scene-engine'); ?></a>
                        <a href="#"><?php esc_html_e('Manifesto', 'open-scene-engine'); ?></a>
                    </nav>
                    <p><?php esc_html_e('© 2026 scene.wtf — Bangalore Underground Collective', 'open-scene-engine'); ?></p>
                </footer>
            </div>
        </aside>
    </div>
</div>
</div>
<?php elseif ($isPost404) : ?>
<main class="ose-post-detail-main">
    <h1><?php esc_html_e('Post not found', 'open-scene-engine'); ?></h1>
    <p><?php esc_html_e('This discussion does not exist.', 'open-scene-engine'); ?></p>
</main>
<?php elseif ($isValidUser) : ?>
<?php
$currentUser = wp_get_current_user();
$currentUsername = ($currentUser instanceof \WP_User && $currentUser->ID > 0) ? strtolower(sanitize_user((string) $currentUser->user_login, true)) : '';
$canEditProfile = $currentUsername !== '' && $currentUsername === $routeUsername;
$avatarUrl = get_avatar_url((int) $ssrUser->ID);
$avatarFallback = strtoupper(substr($routeUsername, 0, 2));
$bio = (string) get_user_meta((int) $ssrUser->ID, 'description', true);
$profileName = trim((string) $ssrUser->display_name) !== '' ? (string) $ssrUser->display_name : (string) $ssrUser->user_login;
?>
<div id="openscene-root" data-openscene-context="<?php echo esc_attr((string) wp_json_encode($context)); ?>">
<div class="ose-scene-home">
    <header class="ose-topbar">
        <div class="ose-topbar-left">
            <a class="ose-brand" href="/openscene/">
                <?php if ($logoUrl !== '') : ?>
                    <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php esc_attr_e('OpenScene Logo', 'open-scene-engine'); ?>" class="ose-brand-logo" />
                <?php else : ?>
                    <?php echo esc_html($brandPrimary); ?><?php if ($brandSuffix !== '') : ?><span><?php echo esc_html($brandSuffix); ?></span><?php endif; ?>
                <?php endif; ?>
            </a>
            <nav class="ose-topbar-nav" aria-label="<?php esc_attr_e('Primary', 'open-scene-engine'); ?>">
                <a href="/openscene/"><?php esc_html_e('Feed', 'open-scene-engine'); ?></a>
                <a href="/openscene/?view=communities"><?php esc_html_e('Communities', 'open-scene-engine'); ?></a>
                <a href="/openscene/?view=artists"><?php esc_html_e('Artists', 'open-scene-engine'); ?></a>
            </nav>
            <div class="ose-search">
                <i data-lucide="search" class="ose-lucide ose-search-icon"></i>
                <input type="search" placeholder="<?php esc_attr_e('Search conversations...', 'open-scene-engine'); ?>" aria-label="<?php esc_attr_e('Search conversations', 'open-scene-engine'); ?>" />
            </div>
        </div>
        <div class="ose-topbar-right">
            <?php if ($isLoggedIn) : ?>
            <button class="ose-icon-btn" type="button" aria-label="<?php esc_attr_e('Notifications', 'open-scene-engine'); ?>">
                <i data-lucide="bell" class="ose-lucide"></i>
            </button>
            <details class="ose-avatar-menu">
                <summary class="ose-avatar-summary" aria-label="<?php esc_attr_e('Open profile menu', 'open-scene-engine'); ?>">
                    <span class="ose-avatar ose-avatar-icon"><i data-lucide="circle-user-round" class="ose-lucide"></i></span>
                </summary>
                <div class="ose-avatar-dropdown">
                    <a href="<?php echo esc_url($profileHref); ?>"><?php esc_html_e('Profile', 'open-scene-engine'); ?></a>
                    <?php if ($canModerate) : ?>
                    <a href="/moderator/"><?php esc_html_e('Moderator Panel', 'open-scene-engine'); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($logoutHref); ?>"><?php esc_html_e('Log out', 'open-scene-engine'); ?></a>
                </div>
            </details>
            <?php elseif ($joinUrl !== '') : ?>
            <a class="ose-join-btn" href="<?php echo esc_url($joinUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('JOIN', 'open-scene-engine'); ?></a>
            <?php endif; ?>
        </div>
    </header>
    <div class="ose-scene-grid">
        <aside class="ose-left">
            <h3 class="ose-side-title"><?php esc_html_e('Communities', 'open-scene-engine'); ?></h3>
            <nav class="ose-community-list">
                <?php foreach ($communityRows as $communityRow) : ?>
                <?php $communityIcon = trim((string) ($communityRow['icon'] ?? '')); ?>
                <a class="ose-community-item" href="<?php echo esc_url(home_url('/c/' . rawurlencode((string) ($communityRow['slug'] ?? '')))); ?>">
                    <span class="ose-community-name"><i data-lucide="<?php echo esc_attr($communityIcon !== '' ? $communityIcon : 'music-4'); ?>" class="ose-lucide ose-community-icon"></i><?php echo esc_html((string) ($communityRow['name'] ?? '')); ?></span>
                </a>
                <?php endforeach; ?>
                <a class="ose-community-item ose-community-view-all" href="/openscene/?view=communities">
                    <span class="ose-community-name"><i data-lucide="plus" class="ose-lucide ose-community-icon"></i><?php esc_html_e('View All', 'open-scene-engine'); ?></span>
                </a>
            </nav>
        </aside>
        <main class="ose-center ose-user-center">
            <section class="ose-user-profile-head">
                <div class="ose-user-avatar-wrap">
                    <?php if (is_string($avatarUrl) && $avatarUrl !== '') : ?>
                    <img class="ose-user-avatar" src="<?php echo esc_url($avatarUrl); ?>" alt="<?php echo esc_attr($routeUsername . ' avatar'); ?>" />
                    <?php else : ?>
                    <div class="ose-user-avatar ose-user-avatar-fallback"><?php echo esc_html($avatarFallback); ?></div>
                    <?php endif; ?>
                </div>
                <div class="ose-user-info">
                    <div class="ose-user-head-row">
                        <h1><?php echo esc_html($profileName); ?></h1>
                        <?php if ($canEditProfile) : ?>
                        <button type="button" class="ose-user-edit"><?php esc_html_e('Edit Profile', 'open-scene-engine'); ?></button>
                        <?php endif; ?>
                    </div>
                    <p><?php echo esc_html($bio !== '' ? $bio : __('No bio added yet.', 'open-scene-engine')); ?></p>
                    <div class="ose-user-meta">
                        <span><i data-lucide="calendar-days" class="ose-lucide"></i><?php echo esc_html__('Joined ', 'open-scene-engine') . esc_html(date_i18n('F Y', strtotime((string) $ssrUser->user_registered))); ?></span>
                        <span><i data-lucide="map-pin" class="ose-lucide"></i><?php esc_html_e('OpenScene', 'open-scene-engine'); ?></span>
                    </div>
                </div>
            </section>
            <div id="openscene-user-content-root" data-openscene-context="<?php echo esc_attr((string) wp_json_encode($context)); ?>"></div>
        </main>
        <aside class="ose-right">
            <div class="ose-right-rail">
                <section class="ose-widget">
                    <div class="ose-widget-head">
                        <h3><?php esc_html_e('Upcoming Bangalore Events', 'open-scene-engine'); ?></h3>
                        <span class="ose-widget-icon ose-widget-icon-events"><i data-lucide="calendar-days" class="ose-lucide"></i></span>
                    </div>
                    <div class="ose-events">
                        <?php if (! empty($eventRows)) : ?>
                            <?php foreach ($eventRows as $eventRow) : ?>
                                <?php
                                $eventDateRaw = (string) ($eventRow['event_date'] ?? '');
                                $eventTimestamp = strtotime($eventDateRaw . ' UTC');
                                $eventMonth = $eventTimestamp ? strtoupper(gmdate('M', $eventTimestamp)) : 'NA';
                                $eventDay = $eventTimestamp ? gmdate('d', $eventTimestamp) : '--';
                                $eventTitle = (string) ($eventRow['title'] ?? 'Untitled event');
                                $eventVenue = (string) ($eventRow['venue_name'] ?? ($eventRow['venue_address'] ?? __('Venue TBA', 'open-scene-engine')));
                                $eventTime = $eventTimestamp ? gmdate('M j, Y H:i', $eventTimestamp) . ' UTC' : '';
                                ?>
                            <article class="ose-event">
                                <div class="ose-event-date">
                                    <span class="ose-event-month"><?php echo esc_html($eventMonth); ?></span>
                                    <span class="ose-event-day"><?php echo esc_html($eventDay); ?></span>
                                </div>
                                <div>
                                    <h4><a href="<?php echo esc_url('/openscene/?view=event&id=' . (int) ($eventRow['id'] ?? 0)); ?>"><?php echo esc_html($eventTitle); ?></a></h4>
                                    <p><?php echo esc_html(trim($eventVenue . ($eventTime !== '' ? ' · ' . $eventTime : ''))); ?></p>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p class="ose-events-note"><?php esc_html_e('No upcoming events.', 'open-scene-engine'); ?></p>
                        <?php endif; ?>
                    </div>
                    <a class="ose-widget-btn" href="/openscene/?view=events"><?php esc_html_e('View Calendar', 'open-scene-engine'); ?></a>
                </section>
                <section class="ose-widget">
                    <div class="ose-widget-head">
                        <h3><?php esc_html_e('Community Rules', 'open-scene-engine'); ?></h3>
                        <span class="ose-widget-icon ose-widget-icon-rules"><i data-lucide="gavel" class="ose-lucide"></i></span>
                    </div>
                    <ol class="ose-rules">
                        <?php foreach ($rules as $index => $rule) : ?>
                        <li>
                            <span><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                            <p><?php echo esc_html($rule); ?></p>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
                <footer class="ose-footer">
                    <nav>
                        <a href="#"><?php esc_html_e('About', 'open-scene-engine'); ?></a>
                        <a href="#"><?php esc_html_e('Guidelines', 'open-scene-engine'); ?></a>
                        <a href="#"><?php esc_html_e('Privacy', 'open-scene-engine'); ?></a>
                        <a href="#"><?php esc_html_e('Manifesto', 'open-scene-engine'); ?></a>
                    </nav>
                    <p><?php esc_html_e('© 2026 scene.wtf — Bangalore Underground Collective', 'open-scene-engine'); ?></p>
                </footer>
            </div>
        </aside>
    </div>
</div>
</div>
<?php elseif ($isUser404) : ?>
<main class="ose-post-detail-main">
    <h1><?php esc_html_e('User not found', 'open-scene-engine'); ?></h1>
    <p><?php esc_html_e('This profile does not exist.', 'open-scene-engine'); ?></p>
</main>
<?php elseif ($isModeratorRoute && ! $canModerate) : ?>
<main class="ose-post-detail-main">
    <h1><?php esc_html_e('Forbidden', 'open-scene-engine'); ?></h1>
    <p><?php esc_html_e('You do not have permission to access the moderator panel.', 'open-scene-engine'); ?></p>
</main>
<?php else : ?>
<div id="openscene-root" data-openscene-context="<?php echo esc_attr((string) wp_json_encode($context)); ?>"></div>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
