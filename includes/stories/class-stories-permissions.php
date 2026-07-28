<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_Permissions {

    private static $story_visibility_cache = [];
    private static $hidden_story_users_cache = [];
    private static $hidden_global_users_cache = [];
    private static $blocked_cache = [];
    private static $friend_ids_cache = [];
    private static $following_ids_cache = [];
    private static $following_cache = [];
    private static $close_friend_ids_cache = [];

    /**
     * Story privacy values:
     * - public: any logged-in user
     * - friends: connections/friends (and optionally follow)
     * - close_friends: only users in close friends list
     */
    public static function can_view_story( int $story_id, int $viewer_id ) : bool {
        if ( $viewer_id <= 0 ) return false;
        $cache_key = $story_id . ':' . $viewer_id;
        if ( array_key_exists($cache_key, self::$story_visibility_cache) ) {
            return self::$story_visibility_cache[$cache_key];
        }

        $author_id = (int) get_post_field('post_author', $story_id);
        if ( $author_id === $viewer_id ) {
            self::$story_visibility_cache[$cache_key] = true;
            return true;
        }
        if ( user_can($viewer_id, 'manage_options') ) {
            self::$story_visibility_cache[$cache_key] = true;
            return true;
        }

        // Respect BuddyBoss/BuddyPress block relationships.
        if ( self::is_blocked_between($author_id, $viewer_id) ) {
            self::$story_visibility_cache[$cache_key] = false;
            return false;
        }

        // Global hidden list (hide all stories from specific users).
        if ( self::is_hidden_globally($author_id, $viewer_id) ) {
            self::$story_visibility_cache[$cache_key] = false;
            return false;
        }

        // Per-story hidden user list (does not block user globally).
        if ( self::is_hidden_from_story($story_id, $viewer_id) ) {
            self::$story_visibility_cache[$cache_key] = false;
            return false;
        }

        $privacy = class_exists('Koopo_Stories_Utils')
            ? Koopo_Stories_Utils::normalize_privacy(get_post_meta($story_id, 'privacy', true))
            : 'friends';

        if ( $privacy === 'public' ) {
            self::$story_visibility_cache[$cache_key] = true;
            return true;
        }

        if ( $privacy === 'close_friends' ) {
            // Only close friends can view
            $result = in_array($viewer_id, self::close_friend_ids($author_id), true);
            self::$story_visibility_cache[$cache_key] = $result;
            return $result;
        }

        // friends/connections (default privacy level)
        $friends = self::friend_ids($viewer_id);
        if ( in_array($author_id, $friends, true) ) {
            self::$story_visibility_cache[$cache_key] = true;
            return true;
        }

        // follow relationship (BuddyBoss Follow or BuddyPress followers plugin)
        if ( in_array($author_id, self::following_ids($viewer_id), true) ) {
            self::$story_visibility_cache[$cache_key] = true;
            return true;
        }

        self::$story_visibility_cache[$cache_key] = false;
        return false;
    }

    public static function is_hidden_from_story( int $story_id, int $viewer_id ) : bool {
        if ( $story_id <= 0 || $viewer_id <= 0 ) return false;
        if ( ! array_key_exists($story_id, self::$hidden_story_users_cache) ) {
            $hidden = get_post_meta($story_id, 'hide_from_user_ids', true);
            if ( is_string($hidden) ) {
                $hidden = array_filter(array_map('intval', explode(',', $hidden)));
            }
            self::$hidden_story_users_cache[$story_id] = is_array($hidden)
                ? array_values(array_unique(array_map('intval', $hidden)))
                : [];
        }
        return in_array($viewer_id, self::$hidden_story_users_cache[$story_id], true);
    }

    public static function is_hidden_globally( int $author_id, int $viewer_id ) : bool {
        if ( $author_id <= 0 || $viewer_id <= 0 ) return false;
        if ( ! array_key_exists($author_id, self::$hidden_global_users_cache) ) {
            $hidden = get_user_meta($author_id, 'koopo_stories_hide_all_user_ids', true);
            if ( is_string($hidden) ) {
                $hidden = array_filter(array_map('intval', explode(',', $hidden)));
            }
            self::$hidden_global_users_cache[$author_id] = is_array($hidden)
                ? array_values(array_unique(array_map('intval', $hidden)))
                : [];
        }
        return in_array($viewer_id, self::$hidden_global_users_cache[$author_id], true);
    }

    public static function is_blocked_between( int $user_a, int $user_b ) : bool {
        if ( $user_a <= 0 || $user_b <= 0 ) return false;
        $cache_key = ($user_a < $user_b)
            ? ($user_a . ':' . $user_b)
            : ($user_b . ':' . $user_a);
        if ( array_key_exists($cache_key, self::$blocked_cache) ) {
            return self::$blocked_cache[$cache_key];
        }

        $blocked = false;

        // BuddyBoss Platform
        if ( function_exists('bp_is_user_blocked') ) {
            if ( bp_is_user_blocked($user_a, $user_b) || bp_is_user_blocked($user_b, $user_a) ) {
                $blocked = true;
            }
        }
        if ( ! $blocked && function_exists('bp_is_user_blocked_by') ) {
            if ( bp_is_user_blocked_by($user_a, $user_b) || bp_is_user_blocked_by($user_b, $user_a) ) {
                $blocked = true;
            }
        }
        if ( ! $blocked && function_exists('bb_is_user_blocked') ) {
            if ( bb_is_user_blocked($user_a, $user_b) || bb_is_user_blocked($user_b, $user_a) ) {
                $blocked = true;
            }
        }

        self::$blocked_cache[$cache_key] = $blocked;
        return $blocked;
    }

    public static function friend_ids( int $user_id ) : array {
        if ( $user_id <= 0 ) return [];
        if ( array_key_exists($user_id, self::$friend_ids_cache) ) {
            return self::$friend_ids_cache[$user_id];
        }
        if ( function_exists('friends_get_friend_user_ids') ) {
            $ids = friends_get_friend_user_ids($user_id);
            self::$friend_ids_cache[$user_id] = is_array($ids)
                ? array_values(array_unique(array_map('intval', $ids)))
                : [];
            return self::$friend_ids_cache[$user_id];
        }
        self::$friend_ids_cache[$user_id] = [];
        return [];
    }

    public static function following_ids( int $user_id ) : array {
        if ( $user_id <= 0 ) return [];
        if ( array_key_exists($user_id, self::$following_ids_cache) ) {
            return self::$following_ids_cache[$user_id];
        }

        // BuddyBoss Platform (bp_get_following returns array of IDs; bp_get_following_ids returns CSV)
        if ( function_exists('bp_get_following') ) {
            $ids = bp_get_following([ 'user_id' => $user_id ]);
            if ( is_string($ids) ) {
                $ids = array_filter(array_map('intval', explode(',', $ids)));
            }
            self::$following_ids_cache[$user_id] = is_array($ids)
                ? array_values(array_unique(array_map('intval', $ids)))
                : [];
            return self::$following_ids_cache[$user_id];
        }
        if ( function_exists('bp_get_following_ids') ) {
            $csv = bp_get_following_ids([ 'user_id' => $user_id ]);
            $ids = array_filter(array_map('intval', explode(',', (string)$csv)));
            self::$following_ids_cache[$user_id] = array_values(array_unique($ids));
            return self::$following_ids_cache[$user_id];
        }

        // BuddyPress followers plugin
        if ( function_exists('bp_follow_get_following') ) {
            $ids = bp_follow_get_following([
                'user_id' => $user_id,
                'per_page' => 9999,
            ]);
            self::$following_ids_cache[$user_id] = is_array($ids)
                ? array_values(array_unique(array_map('intval', $ids)))
                : [];
            return self::$following_ids_cache[$user_id];
        }

        self::$following_ids_cache[$user_id] = [];
        return [];
    }

    public static function is_following( int $follower_id, int $leader_id ) : bool {
        if ( $follower_id <= 0 || $leader_id <= 0 ) return false;
        $cache_key = $follower_id . ':' . $leader_id;
        if ( array_key_exists($cache_key, self::$following_cache) ) {
            return self::$following_cache[$cache_key];
        }

        // BuddyBoss Platform
        if ( function_exists('bp_is_following') ) {
            self::$following_cache[$cache_key] = (bool) bp_is_following($leader_id, $follower_id);
            return self::$following_cache[$cache_key];
        }

        // BuddyPress followers plugin
        if ( function_exists('bp_follow_is_following') ) {
            self::$following_cache[$cache_key] = (bool) bp_follow_is_following([
                'leader_id' => $leader_id,
                'follower_id' => $follower_id,
            ]);
            return self::$following_cache[$cache_key];
        }

        self::$following_cache[$cache_key] = false;
        return false;
    }

    private static function close_friend_ids( int $user_id ) : array {
        if ( $user_id <= 0 ) {
            return [];
        }
        if ( array_key_exists($user_id, self::$close_friend_ids_cache) ) {
            return self::$close_friend_ids_cache[$user_id];
        }

        self::$close_friend_ids_cache[$user_id] = class_exists('Koopo_Stories_Close_Friends')
            ? array_values(array_unique(array_map('intval', Koopo_Stories_Close_Friends::get_close_friends($user_id))))
            : [];

        return self::$close_friend_ids_cache[$user_id];
    }
}
