<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST {
    const API_VERSION = '1.1';

    public static function bump_user_feed_salt( int $user_id ) : void {
        if ( $user_id <= 0 ) return;
        update_user_meta($user_id, 'koopo_stories_feed_salt', (string) time());
    }

    public static function bump_global_feed_salt() : void {
        update_option('koopo_stories_feed_global_salt', (string) time(), false);
    }

    public static function rate_limit( string $action, int $user_id, int $max, int $window ) : bool {
        return Koopo_Stories_Utils::rate_limit($action, $user_id, $max, $window);
    }

    public static function normalize_privacy( $privacy ) : string {
        return Koopo_Stories_Utils::normalize_privacy($privacy);
    }

    public static function register_routes() : void {
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [ 'Koopo_Stories_REST_Feed', 'get_feed' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
                'args' => [
                    'limit' => [ 'default' => 20 ],
                    'scope' => [ 'default' => 'friends' ], // friends|following|all
                    'exclude_me' => [ 'default' => 0 ],
                    'order' => [ 'default' => 'unseen_first' ], // unseen_first|recent_activity
                    'only_me' => [ 'default' => 0 ],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'create_story' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'get_story' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'update_story' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'delete_story' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/hide', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'get_story_hidden_users' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/search-users', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'search_users' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            'args' => [
                'query' => [ 'required' => true ],
                'limit' => [ 'default' => 8 ],
            ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/hide/(?P<user_id>\d+)', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'add_story_hidden_user' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'remove_story_hidden_user' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/archive', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Feed', 'get_archived_stories' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/items/(?P<id>\d+)/seen', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'mark_seen' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Close friends management
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/close-friends', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'get_close_friends' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/close-friends/(?P<friend_id>\d+)', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'add_close_friend' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [ 'Koopo_Stories_REST_Story', 'remove_close_friend' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
        ] );

        // Reactions
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/reactions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'get_reactions' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/reactions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'add_reaction' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/reactions', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'remove_reaction' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Replies
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/replies', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'get_replies' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/replies', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'add_reply' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/replies/(?P<reply_id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'delete_reply' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Analytics & Insights
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/viewers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'get_viewers' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/analytics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Story', 'get_analytics' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Reporting & Moderation
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/report', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'report_story' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/reports', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'get_reports' ],
            'permission_callback' => [ __CLASS__, 'can_moderate' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/reports/(?P<report_id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [ 'Koopo_Stories_REST_Engagement', 'update_report' ],
            'permission_callback' => [ __CLASS__, 'can_moderate' ],
        ] );

        // Stickers
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/items/(?P<item_id>\d+)/stickers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ 'Koopo_Stories_REST_Stickers', 'add_sticker' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            'args' => [
                'type' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'data' => [
                    'required' => true,
                    'type' => 'object',
                ],
                'position_x' => [
                    'required' => false,
                    'type' => 'number',
                ],
                'position_y' => [
                    'required' => false,
                    'type' => 'number',
                ],
            ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stickers/(?P<sticker_id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [ 'Koopo_Stories_REST_Stickers', 'delete_sticker' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stickers/(?P<sticker_id>\d+)/vote', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ 'Koopo_Stories_REST_Stickers', 'vote_poll' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );
    }

    public static function must_be_logged_in() : bool {
        return is_user_logged_in();
    }

    public static function can_moderate() : bool {
        return is_user_logged_in() && current_user_can('manage_options');
    }
}
