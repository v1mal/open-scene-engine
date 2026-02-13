<?php

declare(strict_types=1);

namespace OpenScene\Engine\Auth;

final class Roles
{
    /** Can access moderation write actions (lock/pin/ban/log actions) via REST moderation endpoints. */
    public const CAP_MODERATE = 'openscene_moderate';

    /** Can delete any comment through the comment moderation endpoint. */
    public const CAP_DELETE_ANY_COMMENT = 'openscene_delete_any_comment';

    /** Can lock a thread via `POST /openscene/v1/posts/{id}/lock`. */
    public const CAP_LOCK_THREAD = 'openscene_lock_thread';

    /** Can pin/unpin a thread via `POST /openscene/v1/posts/{id}/sticky`. */
    public const CAP_PIN_THREAD = 'openscene_pin_thread';

    /** Can ban/unban users via moderation endpoints. */
    public const CAP_BAN_USER = 'openscene_ban_user';

    /** Can soft-delete any post via `DELETE /openscene/v1/posts/{id}`. */
    public const CAP_DELETE_ANY_POST = 'openscene_delete_any_post';

    /** @return list<string> */
    public static function moderationCaps(): array
    {
        return [
            self::CAP_MODERATE,
            self::CAP_DELETE_ANY_COMMENT,
            self::CAP_DELETE_ANY_POST,
            self::CAP_LOCK_THREAD,
            self::CAP_PIN_THREAD,
            self::CAP_BAN_USER,
        ];
    }

    public static function register(): void
    {
        add_role('openscene_member', 'OpenScene Member', [
            'read' => true,
            'openscene_create_post' => true,
            'openscene_comment' => true,
            'openscene_vote' => true,
        ]);

        add_role('openscene_moderator', 'OpenScene Moderator', [
            'read' => true,
            'openscene_create_post' => true,
            'openscene_comment' => true,
            'openscene_vote' => true,
            'openscene_delete_post' => true,
            self::CAP_DELETE_ANY_POST => true,
            'openscene_lock_post' => true,
            'openscene_sticky_post' => true,
            self::CAP_BAN_USER => true,
            self::CAP_MODERATE => true,
            self::CAP_DELETE_ANY_COMMENT => true,
            self::CAP_LOCK_THREAD => true,
            self::CAP_PIN_THREAD => true,
        ]);

        add_role('openscene_admin', 'OpenScene Admin', [
            'read' => true,
            'manage_options' => true,
            'openscene_create_post' => true,
            'openscene_comment' => true,
            'openscene_vote' => true,
            'openscene_delete_post' => true,
            self::CAP_DELETE_ANY_POST => true,
            'openscene_lock_post' => true,
            'openscene_sticky_post' => true,
            self::CAP_BAN_USER => true,
            self::CAP_MODERATE => true,
            self::CAP_DELETE_ANY_COMMENT => true,
            self::CAP_LOCK_THREAD => true,
            self::CAP_PIN_THREAD => true,
        ]);

        self::assignModerationCapsToRole('administrator');
        self::assignModerationCapsToRole('editor');
    }

    public static function unregister(): void
    {
        foreach (['administrator', 'editor', 'openscene_moderator', 'openscene_admin'] as $roleName) {
            $role = get_role($roleName);
            if (! $role) {
                continue;
            }

            foreach (self::moderationCaps() as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    public static function syncRuntimeCaps(): void
    {
        foreach (['administrator', 'editor', 'openscene_moderator', 'openscene_admin'] as $roleName) {
            self::assignModerationCapsToRole($roleName);
        }
    }

    private static function assignModerationCapsToRole(string $roleName): void
    {
        $role = get_role($roleName);
        if (! $role) {
            return;
        }

        foreach (self::moderationCaps() as $cap) {
            $role->add_cap($cap);
        }
    }
}
