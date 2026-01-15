<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST_Story {

    public static function get_story( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $compact = $req->get_param('compact') === '1' || $req->get_param('mobile') === '1';

        $story_id = (int) $req['id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( $story->post_status !== 'publish' ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        $author_id = (int) $story->post_author;

        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $items_out = [];
        foreach ( $items as $item ) {
            $item_id = (int) $item->ID;
            $item_payload = Koopo_Stories_Utils::build_story_item_payload($item_id, $compact);
            if ( ! $item_payload ) {
                continue;
            }
            $item_payload['story_id'] = $story_id;
            $item_payload['created_at'] = mysql_to_rfc3339( get_gmt_from_date($item->post_date) );

            if ( ! $compact ) {
                $item_payload['stickers'] = Koopo_Stories_Stickers::get_stickers($item_id);
            }

            $items_out[] = $item_payload;
        }

        $privacy = Koopo_Stories_REST::normalize_privacy(get_post_meta($story_id, 'privacy', true));
        $is_archived = (int) get_post_meta($story_id, 'is_archived', true) === 1;
        $posted_ts = get_post_time('U', true, $story_id);
        if ( ! $posted_ts ) {
            $posted_ts = strtotime($story->post_date_gmt ?: $story->post_date);
        }
        $posted_at_human = $posted_ts
            ? sprintf('%s ago', human_time_diff($posted_ts, current_time('timestamp')))
            : '';

        $payload = [
            'api_version' => Koopo_Stories_REST::API_VERSION,
            'story_id' => $story_id,
            'author' => Koopo_Stories_Utils::get_author_payload($author_id, 96, true),
            'items' => $items_out,
            'privacy' => $privacy,
            'is_archived' => $is_archived,
            'can_manage' => self::can_manage_story($story_id, $user_id),
            'posted_at_human' => $posted_at_human,
        ];

        if ( self::can_manage_story($story_id, $user_id) ) {
            // Get view counts
            $item_ids = array_map(function($item) { return (int) $item->ID; }, $items);
            $view_stats = Koopo_Stories_Views_Table::get_story_analytics($item_ids);

            // Get reaction counts
            $reaction_counts = Koopo_Stories_Reactions::get_reaction_counts($story_id);
            $reaction_by_item = Koopo_Stories_Reactions::get_reaction_counts_by_item($story_id, $item_ids);
            $total_reactions = array_sum($reaction_counts);

            $payload['analytics'] = [
                'view_count' => (int) ($view_stats['unique_viewers'] ?? 0),
                'reaction_count' => $total_reactions,
                'reactions' => $reaction_counts,
            ];

            foreach ( $payload['items'] as &$item ) {
                $item_id = (int) ($item['item_id'] ?? 0);
                $item['analytics'] = [
                    'view_count' => (int) ($view_stats['views_by_item'][$item_id] ?? 0),
                    'reaction_count' => (int) ($reaction_by_item[$item_id] ?? 0),
                ];
            }
            unset($item);
        }

        if ( $compact ) {
            unset($payload['author']['profile_url']);
            if ( isset($payload['analytics']) ) {
                unset($payload['analytics']['reactions']);
            }
            foreach ( $payload['items'] as &$item ) {
                unset($item['thumb']);
            }
        }

        return new WP_REST_Response($payload, 200);
    }

    public static function mark_seen( WP_REST_Request $req ) {
        $user_id = get_current_user_id();

        $item_id = (int) $req['id'];

        $item = get_post($item_id);
        if ( ! $item || $item->post_type !== Koopo_Stories_Module::CPT_ITEM ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        $story_id = (int) get_post_meta($item_id, 'story_id', true);
        if ( $story_id && ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        Koopo_Stories_Views_Table::mark_seen($item_id, $user_id);
        Koopo_Stories_REST::bump_user_feed_salt($user_id);
        return new WP_REST_Response([ 'ok' => true ], 200);
    }

    public static function create_story( WP_REST_Request $req ) {
        // MVP: accept multipart upload "file"
        $user_id = get_current_user_id();
        $upload_error = Koopo_Stories_Utils::ensure_can_upload();
        if ( $upload_error ) {
            return $upload_error;
        }

        $limit_error = Koopo_Stories_Utils::enforce_daily_upload_limit($user_id);
        if ( $limit_error ) {
            return $limit_error;
        }

        $expires_at_new = Koopo_Stories_Utils::get_story_expiry_timestamp();
        $max_items_per_story = Koopo_Stories_Utils::get_max_items_per_story();

        $upload_prep = Koopo_Stories_Utils::prepare_upload_file($req);
        if ( isset($upload_prep['error']) ) {
            return $upload_prep['error'];
        }

        $file = $upload_prep['file'];
        $validation_error = Koopo_Stories_Utils::validate_upload_file($file);
        if ( $validation_error ) {
            return $validation_error;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Upload to media library
        $attachment_id = media_handle_upload('file', 0);
        if ( is_wp_error($attachment_id) ) {
            return new WP_REST_Response([ 'error' => 'upload_failed', 'message' => $attachment_id->get_error_message() ], 400);
        }

        // Determine media type
        $mime = get_post_mime_type($attachment_id);
        $media_type = (is_string($mime) && strpos($mime, 'video/') === 0) ? 'video' : 'image';

                // Find existing active story for this user (within configured duration)
        $existing = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'expires_at',
                    'value' => time(),
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => 'expires_at',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $story_id = 0;

        if ( ! empty($existing) ) {
            $story_id = (int) $existing[0]->ID;

            // If story has no expiry meta (legacy), set it now.
            $ex = (int) get_post_meta($story_id, 'expires_at', true);
            if ( $ex <= 0 ) {
                update_post_meta($story_id, 'expires_at', $expires_at_new);
            }

            // If privacy is missing (legacy), default to friends.
            $pv = get_post_meta($story_id, 'privacy', true);
            if ( ! $pv ) {
                update_post_meta($story_id, 'privacy', 'friends');
            }

            // Enforce max items per story: if reached, start a new story instead.
            if ( Koopo_Stories_Utils::is_story_at_item_limit($story_id, $max_items_per_story) ) {
                $story_id = 0;
            }
        }

        if ( ! $story_id ) {
            $story_id = wp_insert_post([
                'post_type' => Koopo_Stories_Module::CPT_STORY,
                'post_status' => 'publish',
                'post_title' => 'Story - ' . $user_id . ' - ' . current_time('mysql'),
                'post_author' => $user_id,
            ], true);

            if ( is_wp_error($story_id) ) {
                return new WP_REST_Response([ 'error' => 'create_failed' ], 400);
            }

            $privacy = $req->get_param('privacy');
            $allowed_privacy = ['public', 'friends', 'close_friends'];
            $privacy = in_array($privacy, $allowed_privacy, true) ? $privacy : 'friends';
            update_post_meta($story_id, 'privacy', $privacy);
            update_post_meta($story_id, 'expires_at', $expires_at_new);
        }

        // Create story item
        $item_id = wp_insert_post([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'post_title' => 'Item - ' . $attachment_id,
            'post_author' => $user_id,
        ], true);

        if ( is_wp_error($item_id) ) {
            return new WP_REST_Response([ 'error' => 'create_item_failed' ], 400);
        }

        update_post_meta($item_id, 'story_id', $story_id);
        update_post_meta($item_id, 'attachment_id', (int)$attachment_id);
        update_post_meta($item_id, 'media_type', $media_type);
        if ( $media_type === 'image' ) {
            update_post_meta($item_id, 'duration_ms', 5000);
        }

        // bump modified date of story
        wp_update_post([
            'ID' => $story_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);

        Koopo_Stories_REST::bump_global_feed_salt();
        Koopo_Stories_REST::bump_user_feed_salt($user_id);
        do_action('koopo_stories_story_created', $story_id, $item_id, $user_id);
        return new WP_REST_Response([
            'ok' => true,
            'story_id' => $story_id,
            'item_id' => $item_id,
        ], 200);
    }

    public static function update_story( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $author_id = (int) $story->post_author;
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        $privacy = $req->get_param('privacy');
        if ( $privacy !== null ) {
            $allowed_privacy = ['public', 'friends', 'close_friends'];
            $privacy = in_array($privacy, $allowed_privacy, true) ? $privacy : 'friends';
            update_post_meta($story_id, 'privacy', $privacy);
        } else {
            $privacy = get_post_meta($story_id, 'privacy', true);
            if ( empty($privacy) ) $privacy = 'friends';
        }

        $archive_param = $req->get_param('archive');
        if ( $archive_param !== null ) {
            $archive = filter_var($archive_param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $archive = ($archive === null) ? false : $archive;
            update_post_meta($story_id, 'is_archived', $archive ? 1 : 0);

            if ( $archive ) {
                update_post_meta($story_id, 'expires_at', time() - 1);
            } else {
                $expires_at_new = Koopo_Stories_Utils::get_story_expiry_timestamp();
                update_post_meta($story_id, 'expires_at', $expires_at_new);
            }
        }

        Koopo_Stories_REST::bump_global_feed_salt();
        Koopo_Stories_REST::bump_user_feed_salt($user_id);
        return new WP_REST_Response([
            'story_id' => $story_id,
            'privacy' => $privacy,
            'is_archived' => (int) get_post_meta($story_id, 'is_archived', true) === 1,
        ], 200);
    }

    public static function delete_story( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $author_id = (int) $story->post_author;
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        if ( class_exists('Koopo_Stories_Cleanup') ) {
            Koopo_Stories_Cleanup::delete_story($story_id);
        } else {
            wp_delete_post($story_id, true);
        }

        Koopo_Stories_REST::bump_global_feed_salt();
        Koopo_Stories_REST::bump_user_feed_salt($user_id);
        return new WP_REST_Response([ 'deleted' => true, 'story_id' => $story_id ], 200);
    }

    private static function get_hidden_user_ids( int $story_id ) : array {
        $hidden = get_post_meta($story_id, 'hide_from_user_ids', true);
        if ( empty($hidden) ) return [];
        if ( is_string($hidden) ) {
            $hidden = array_filter(array_map('intval', explode(',', $hidden)));
        }
        if ( ! is_array($hidden) ) return [];
        return array_values(array_unique(array_map('intval', $hidden)));
    }

    private static function can_manage_story( int $story_id, int $user_id ) : bool {
        $author_id = (int) get_post_field('post_author', $story_id);
        return $author_id === $user_id || user_can($user_id, 'manage_options');
    }

    public static function get_story_hidden_users( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( ! self::can_manage_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        $hidden_ids = self::get_hidden_user_ids($story_id);
        $users = [];
        foreach ( $hidden_ids as $hid ) {
            $u = Koopo_Stories_Utils::get_user_cached($hid);
            if ( ! $u ) continue;
            $users[] = [
                'id' => $hid,
                'name' => $u->display_name,
                'username' => $u->user_login,
                'avatar' => get_avatar_url($hid, [ 'size' => 64 ]),
            ];
        }

        return new WP_REST_Response([ 'users' => $users ], 200);
    }

    public static function search_users( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_REST_Response([ 'error' => 'unauthorized' ], 401);
        }

        $query = trim( (string) $req->get_param('query') );
        if ( strlen($query) < 2 ) {
            return new WP_REST_Response([ 'users' => [] ], 200);
        }

        $limit = min(20, max(1, (int) $req->get_param('limit') ?: 8));
        $user_query = new WP_User_Query([
            'number' => $limit,
            'search' => '*' . esc_attr($query) . '*',
            'search_columns' => [ 'user_login', 'display_name', 'user_nicename' ],
            'fields' => [ 'ID', 'display_name', 'user_login' ],
        ]);

        $users = [];
        foreach ( $user_query->get_results() as $u ) {
            $uid = (int) $u->ID;
            $users[] = [
                'id' => $uid,
                'name' => $u->display_name,
                'username' => $u->user_login,
                'avatar' => get_avatar_url($uid, [ 'size' => 64 ]),
                'profile_url' => function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($uid) : '',
            ];
        }

        return new WP_REST_Response([ 'users' => $users ], 200);
    }

    public static function add_story_hidden_user( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $hide_user_id = (int) $req['user_id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( ! self::can_manage_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }
        if ( $hide_user_id <= 0 || $hide_user_id === (int) $story->post_author ) {
            return new WP_REST_Response([ 'error' => 'invalid_user' ], 400);
        }

        $hidden_ids = self::get_hidden_user_ids($story_id);
        if ( ! in_array($hide_user_id, $hidden_ids, true) ) {
            $hidden_ids[] = $hide_user_id;
            update_post_meta($story_id, 'hide_from_user_ids', array_values(array_unique($hidden_ids)));
        }

        Koopo_Stories_REST::bump_global_feed_salt();
        return new WP_REST_Response([ 'ok' => true, 'user_id' => $hide_user_id ], 200);
    }

    public static function remove_story_hidden_user( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $hide_user_id = (int) $req['user_id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( ! self::can_manage_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }
        if ( $hide_user_id <= 0 ) {
            return new WP_REST_Response([ 'error' => 'invalid_user' ], 400);
        }

        $hidden_ids = self::get_hidden_user_ids($story_id);
        $hidden_ids = array_values(array_filter($hidden_ids, function($id) use ($hide_user_id) {
            return (int) $id !== $hide_user_id;
        }));
        update_post_meta($story_id, 'hide_from_user_ids', $hidden_ids);

        Koopo_Stories_REST::bump_global_feed_salt();
        return new WP_REST_Response([ 'ok' => true, 'user_id' => $hide_user_id ], 200);
    }

    public static function get_close_friends( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_REST_Response(['error' => 'unauthorized'], 401);
        }

        $friend_ids = Koopo_Stories_Close_Friends::get_close_friends($user_id);

        $friends = [];
        foreach ( $friend_ids as $fid ) {
            $user = Koopo_Stories_Utils::get_user_cached((int) $fid);
            if ( ! $user ) continue;
            $payload = Koopo_Stories_Utils::get_author_payload((int) $fid, 64, true);
            $payload['username'] = $user->user_login;
            $friends[] = $payload;
        }

        return new WP_REST_Response([
            'friends' => $friends,
            'count' => count($friends),
        ], 200);
    }

    public static function add_close_friend( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $friend_id = (int) $req['friend_id'];

        $success = Koopo_Stories_Close_Friends::add_friend($user_id, $friend_id);
        if ( $success ) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Friend added to close friends',
            ], 200);
        }

        return new WP_REST_Response([ 'error' => 'failed' ], 400);
    }

    public static function remove_close_friend( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $friend_id = (int) $req['friend_id'];

        $success = Koopo_Stories_Close_Friends::remove_friend($user_id, $friend_id);
        if ( $success ) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Friend removed from close friends',
            ], 200);
        }

        return new WP_REST_Response([ 'error' => 'failed' ], 400);
    }

    public static function get_viewers( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $limit = min(200, max(1, (int) $req->get_param('limit') ?: 50));
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;

        // Verify story exists and user can view it
        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $author_id = (int) $story->post_author;

        // Only story author can see viewer list
        if ( $author_id !== $user_id ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        // Get all items for this story
        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
        ]);

        $item_ids = array_map('intval', $items);
        if ( empty($item_ids) ) {
            return new WP_REST_Response([ 'viewers' => [], 'total_count' => 0 ], 200);
        }

        $viewers_data = Koopo_Stories_Views_Table::get_story_viewers($item_ids, $limit);
        $viewer_ids = array_map(static function($row){
            return (int) ($row['viewer_user_id'] ?? 0);
        }, $viewers_data ?: []);
        $reaction_map = Koopo_Stories_Reactions::get_reactions_map($story_id, $viewer_ids, $item_id);

        $viewers = [];
        foreach ( $viewers_data as $row ) {
            $viewer_id = (int) $row['viewer_user_id'];
            $user = Koopo_Stories_Utils::get_user_cached($viewer_id);
            if ( ! $user ) continue;
            $payload = Koopo_Stories_Utils::get_author_payload($viewer_id, 64, true);
            $payload['viewed_at'] = $row['viewed_at'];
            $payload['reaction'] = $reaction_map[$viewer_id] ?? '';
            $viewers[] = $payload;
        }

        return new WP_REST_Response([
            'viewers' => $viewers,
            'total_count' => Koopo_Stories_Views_Table::get_story_view_count($item_ids),
        ], 200);
    }

    public static function get_analytics( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];

        // Verify story exists and user can view it
        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        // Only story author/admin can see analytics
        if ( ! self::can_manage_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        // Get all items for this story
        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
        ]);

        $item_ids = array_map('intval', $items);
        if ( empty($item_ids) ) {
            return new WP_REST_Response([ 'analytics' => [] ], 200);
        }

        $analytics = Koopo_Stories_Views_Table::get_story_analytics($item_ids);
        $reaction_counts = Koopo_Stories_Reactions::get_reaction_counts($story_id);
        $replies = Koopo_Stories_Replies::get_replies($story_id, $user_id);

        return new WP_REST_Response([
            'story_id' => $story_id,
            'items' => $analytics,
            'reactions' => $reaction_counts,
            'replies' => $replies,
        ], 200);
    }
}
