<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST_Engagement {

    public static function get_reactions( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $author_id = (int) $story->post_author;
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $reactions_raw = Koopo_Stories_Reactions::get_reactions($story_id, $item_id);
        $counts = Koopo_Stories_Reactions::get_reaction_counts($story_id, $item_id);
        $user_reaction = Koopo_Stories_Reactions::get_user_reaction($story_id, $user_id, $item_id);

        $reactions = [];
        foreach ( $reactions_raw as $row ) {
            $reaction_user_id = (int) ($row['user_id'] ?? 0);
            $reaction_user = $reaction_user_id > 0
                ? Koopo_Stories_Utils::get_user_cached($reaction_user_id)
                : null;
            $reactions[] = [
                'user_id' => $reaction_user_id,
                'reaction' => $row['reaction'] ?? '',
                'created_at' => $row['created_at'] ?? '',
                'user' => $reaction_user_id > 0
                    ? array_merge(
                        Koopo_Stories_Utils::get_author_payload($reaction_user_id, 48, true),
                        [ 'username' => $reaction_user ? $reaction_user->user_login : '' ]
                    )
                    : null,
            ];
        }

        return new WP_REST_Response([
            'reactions' => $reactions,
            'counts' => $counts,
            'total' => array_sum($counts),
            'user_reaction' => $user_reaction,
        ], 200);
    }

    /**
     * Add or update a reaction
     */
    public static function add_reaction( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;
        $reaction = $req->get_param('reaction');

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $limit_reactions = (int) get_option('koopo_stories_rate_limit_reactions', 200);
        if ( $limit_reactions > 0 && ! Koopo_Stories_REST::rate_limit('reaction', $user_id, $limit_reactions, HOUR_IN_SECONDS) ) {
            return new WP_REST_Response([ 'error' => 'rate_limited' ], 429);
        }

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if ( (int) $story->post_author === $user_id ) {
            return new WP_REST_Response([ 'error' => 'cannot_react_own_story' ], 403);
        }

        if ( empty($reaction) ) {
            return new WP_REST_Response(['error' => 'reaction_required'], 400);
        }

        $success = Koopo_Stories_Reactions::add_reaction($story_id, $user_id, $reaction, $item_id);

        if ( $success ) {
            // Send BuddyBoss notification
            self::send_reaction_notification($story_id, sender_id: $user_id, reaction: $reaction);

            do_action('koopo_stories_reaction_added', $story_id, $user_id, $reaction, $item_id);

            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Reaction added',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Remove a reaction
     */
    public static function remove_reaction( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;

        $success = Koopo_Stories_Reactions::remove_reaction($story_id, $user_id, $item_id);

        if ( $success ) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Reaction removed',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Get replies for a story
     */
    public static function get_replies( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;
        $limit = min(100, max(1, (int) $req->get_param('limit') ?: 50));

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $author_id = (int) $story->post_author;
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $replies = Koopo_Stories_Replies::get_replies($story_id, $user_id, $item_id, $limit);

        // Enhance replies with user info
        $replies_out = [];
        foreach ( $replies as $reply ) {
            $user = Koopo_Stories_Utils::get_user_cached((int) $reply['user_id']);
            $user_payload = Koopo_Stories_Utils::get_author_payload((int) $reply['user_id'], 48, true);
            $replies_out[] = [
                'id' => (int) $reply['id'],
                'message' => $reply['message'],
                'is_dm' => (bool) $reply['is_dm'],
                'created_at' => $reply['created_at'],
                'user' => [
                    'id' => (int) $reply['user_id'],
                    'name' => $user ? $user->display_name : 'User',
                    'avatar' => $user_payload['avatar'],
                    'profile_url' => $user_payload['profile_url'] ?? '',
                ],
            ];
        }

        return new WP_REST_Response([
            'replies' => $replies_out,
            'count' => count($replies_out),
        ], 200);
    }

    /**
     * Add a reply
     */
    public static function add_reply( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;
        $message = $req->get_param('message');
        $is_dm = $req->get_param('is_dm') !== '0'; // Default to true (DM)

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $limit_replies = (int) get_option('koopo_stories_rate_limit_replies', 60);
        if ( $limit_replies > 0 && ! Koopo_Stories_REST::rate_limit('reply', $user_id, $limit_replies, HOUR_IN_SECONDS) ) {
            return new WP_REST_Response([ 'error' => 'rate_limited' ], 429);
        }

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if ( (int) $story->post_author === $user_id ) {
            return new WP_REST_Response([ 'error' => 'cannot_reply_own_story' ], 403);
        }

        if ( empty($message) ) {
            return new WP_REST_Response(['error' => 'message_required'], 400);
        }

        $reply_id = Koopo_Stories_Replies::add_reply($story_id, $user_id, $message, $item_id, $is_dm);

        if ( $reply_id > 0 ) {
            // Send BuddyBoss notification if it's a DM
            if ( $is_dm ) {
                self::send_reply_notification($story_id, $user_id, $message);
            }
            do_action('koopo_stories_reply_added', $story_id, $user_id, $reply_id, $item_id, (bool) $is_dm);

            return new WP_REST_Response([
                'ok' => true,
                'reply_id' => $reply_id,
                'message' => 'Reply added',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Delete a reply
     */
    public static function delete_reply( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $reply_id = (int) $req['reply_id'];

        $success = Koopo_Stories_Replies::delete_reply($reply_id, $user_id);

        if ( $success ) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Reply deleted',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Report a story
     */
    public static function report_story( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];

        // Verify story exists
        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        // Can't report your own story
        if ( (int) $story->post_author === $user_id ) {
            return new WP_REST_Response([ 'error' => 'cannot_report_own_story' ], 400);
        }

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        $limit_reports = (int) get_option('koopo_stories_rate_limit_reports', 10);
        if ( $limit_reports > 0 && ! Koopo_Stories_REST::rate_limit('report', $user_id, $limit_reports, HOUR_IN_SECONDS) ) {
            return new WP_REST_Response([ 'error' => 'rate_limited' ], 429);
        }

        $reason = sanitize_text_field( $req->get_param('reason') ?: 'other' );
        $description = sanitize_textarea_field( $req->get_param('description') ?: '' );

        $result = Koopo_Stories_Reports::submit_report($story_id, $user_id, $reason, $description);

        if ( $result ) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Report submitted successfully',
            ], 200);
        }

        return new WP_REST_Response([ 'error' => 'failed_to_submit_report' ], 500);
    }

    /**
     * Get pending reports (admin only)
     */
    public static function get_reports( WP_REST_Request $req ) {
        $limit = max(1, min(100, (int) $req->get_param('limit') ?: 50));
        $offset = max(0, (int) $req->get_param('offset') ?: 0);

        $reports = Koopo_Stories_Reports::get_pending_reports($limit, $offset);
        $stats = Koopo_Stories_Reports::get_stats();

        // Enrich with story and user data
        $enriched = [];
        foreach ( $reports as $report ) {
            $story_id = (int) $report['story_id'];
            $story = get_post($story_id);

            if ( ! $story ) continue;
            $author = Koopo_Stories_Utils::get_user_cached((int) $story->post_author);

            $enriched[] = [
                'id' => (int) $report['id'],
                'story_id' => $story_id,
                'story' => [
                    'title' => 'Story by ' . ($author ? $author->display_name : 'Unknown'),
                    'author' => Koopo_Stories_Utils::get_author_payload((int) $story->post_author, 64, true),
                    'created_at' => mysql_to_rfc3339( get_gmt_from_date($story->post_date) ),
                ],
                'reason' => $report['reason'],
                'description' => $report['description'],
                'reporter' => Koopo_Stories_Utils::get_author_payload((int) $report['reporter_user_id'], 48, true),
                'status' => $report['status'],
                'created_at' => $report['created_at'],
            ];
        }

        return new WP_REST_Response([
            'stats' => $stats,
            'reports' => $enriched,
        ], 200);
    }

    /**
     * Update report status (admin only)
     */
    public static function update_report( WP_REST_Request $req ) {
        $report_id = (int) $req['report_id'];
        $status = sanitize_text_field( $req->get_param('status') ?: 'reviewed' );
        $action_taken = sanitize_text_field( $req->get_param('action_taken') ?: null );
        $reviewer_id = get_current_user_id();

        $success = Koopo_Stories_Reports::update_report_status($report_id, $status, $reviewer_id, $action_taken);

        if ( $success ) {
            return new WP_REST_Response([ 'success' => true ], 200);
        }

        return new WP_REST_Response([ 'error' => 'update_failed' ], 500);
    }

    private static function send_reply_notification( int $story_id, int $sender_id, string $message ) {
        // Get story author
        $author_id = (int) get_post_field('post_author', $story_id);

        // Don't notify if replying to own story
        if ( $author_id === $sender_id ) {
            return;
        }

        $sender_name = Koopo_Stories_Utils::get_user_display_name($sender_id, 'Someone');

        // Send BuddyBoss private message
        if ( function_exists('messages_new_message') ) {
            $message_content = sprintf(
                '%s replied to your story: %s',
                $sender_name,
                $message
            );

            messages_new_message([
                'sender_id' => $sender_id,
                'thread_id' => false,
                'recipients' => [ $author_id ],
                'subject' => sprintf('%s replied to your story', $sender_name),
                'content' => $message_content,
            ]);
        }

        // Send BuddyBoss notification
        if ( function_exists('bp_notifications_add_notification') ) {
            bp_notifications_add_notification([
                'user_id' => $author_id,
                'item_id' => $story_id,
                'secondary_item_id' => $sender_id,
                'component_name' => 'stories',
                'component_action' => 'story_reply',
                'date_notified' => bp_core_current_time(),
                'is_new' => 1,
            ]);
        }
    }

    private static function send_reaction_notification( int $story_id, int $sender_id, string $reaction ) {
        // Get story author
        $author_id = (int) get_post_field('post_author', $story_id);

        // Don't notify if reacting to own story
        if ( $author_id === $sender_id ) {
            return;
        }

        $sender_name = Koopo_Stories_Utils::get_user_display_name($sender_id, 'Someone');

        // Send BuddyBoss notification
        if ( function_exists('bp_notifications_add_notification') ) {
            $notification_id = bp_notifications_add_notification([
                'user_id' => $author_id,
                'item_id' => $story_id,
                'secondary_item_id' => $sender_id,
                'component_name' => 'stories',
                'component_action' => 'story_reaction',
                'date_notified' => bp_core_current_time(),
                'is_new' => 1,
            ]);
            if ( $notification_id && function_exists('bp_notifications_add_meta') ) {
                bp_notifications_add_meta($notification_id, 'reaction', $reaction);
            }
        }
    }
}
