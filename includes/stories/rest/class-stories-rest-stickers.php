<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST_Stickers {

    private static function normalize_request_type( string $raw_type ) : string {
        $type = sanitize_key($raw_type);
        $aliases = [
            'gif' => 'media',
            'giphy' => 'media',
            'tenor' => 'media',
            'lottie' => 'media',
        ];
        return $aliases[$type] ?? $type;
    }

    private static function normalize_media_payload( string $raw_type, array $data ) : array {
        $normalized_type = self::normalize_request_type($raw_type);
        if ( $normalized_type !== 'media' ) {
            return $data;
        }

        $source = sanitize_key($raw_type);
        if ( in_array($source, ['gif', 'giphy'], true) ) {
            if ( empty($data['provider']) ) {
                $data['provider'] = 'giphy';
            }
            if ( empty($data['mime']) ) {
                $data['mime'] = 'image/gif';
            }
        } elseif ( $source === 'tenor' ) {
            if ( empty($data['provider']) ) {
                $data['provider'] = 'tenor';
            }
            if ( empty($data['mime']) ) {
                $data['mime'] = 'image/gif';
            }
        } elseif ( $source === 'lottie' ) {
            if ( empty($data['provider']) ) {
                $data['provider'] = 'lottie';
            }
            if ( empty($data['mime']) ) {
                $data['mime'] = 'application/json';
            }
        }

        return $data;
    }

    /**
     * Add a sticker to a story item
     */
    public static function add_sticker( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = (int) $req['item_id'];
        $raw_type = (string) $req->get_param('type');
        $type = self::normalize_request_type($raw_type);
        $data = $req->get_param('data');
        $position_x_param = $req->get_param('position_x');
        $position_y_param = $req->get_param('position_y');
        $position_x = $position_x_param === null ? 50.0 : (float) $position_x_param;
        $position_y = $position_y_param === null ? 50.0 : (float) $position_y_param;

        // Verify story ownership
        $author_id = (int) get_post_field('post_author', $story_id);
        if ( $author_id !== $user_id ) {
            return new WP_REST_Response(['error' => 'not_story_owner'], 403);
        }

        // Verify item belongs to story
        $item_story_id = (int) get_post_meta($item_id, 'story_id', true);
        if ( $item_story_id !== $story_id ) {
            return new WP_REST_Response(['error' => 'invalid_item'], 400);
        }

        if ( ! is_array($data) ) {
            return new WP_REST_Response(['error' => 'invalid_data'], 400);
        }

        $data = self::normalize_media_payload($raw_type, $data);

        // Check if stickers table exists
        global $wpdb;
        $stickers_table = $wpdb->prefix . 'koopo_story_stickers';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stickers_table}'") === $stickers_table;

        if ( ! $table_exists ) {
            return new WP_REST_Response([
                'error' => 'stickers_table_missing',
                'message' => 'Database table not found. Please deactivate and reactivate the plugin.',
            ], 500);
        }

        $sticker_id = Koopo_Stories_Stickers::add_sticker($story_id, $item_id, $type, $data, $position_x, $position_y);

        if ( $sticker_id > 0 ) {
            return new WP_REST_Response([
                'success' => true,
                'sticker_id' => $sticker_id,
                'message' => 'Sticker added successfully',
            ], 200);
        }

        return new WP_REST_Response([
            'error' => 'failed_to_add_sticker',
            'message' => 'Sticker validation or database insert failed.',
        ], 500);
    }

    /**
     * Get sticker provider configuration for mobile/web clients.
     */
    public static function get_providers( WP_REST_Request $req ) {
        $lottie_raw = (string) get_option('koopo_stories_stickers_lottie_library', '');
        $lottie_urls = array_values(array_filter(array_map('trim', preg_split('/\R+/', $lottie_raw))));

        return new WP_REST_Response([
            'giphy' => [
                'enabled' => get_option('koopo_stories_stickers_giphy_enabled', '0') === '1',
                'api_key' => (string) get_option('koopo_stories_stickers_giphy_api_key', ''),
            ],
            'tenor' => [
                'enabled' => get_option('koopo_stories_stickers_tenor_enabled', '0') === '1',
                'api_key' => (string) get_option('koopo_stories_stickers_tenor_api_key', ''),
            ],
            'lottie' => [
                'enabled' => get_option('koopo_stories_stickers_lottie_enabled', '0') === '1',
                'library' => array_slice($lottie_urls, 0, 60),
            ],
        ], 200);
    }

    /**
     * Delete a sticker
     */
    public static function delete_sticker( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $sticker_id = (int) $req['sticker_id'];

        $result = Koopo_Stories_Stickers::delete_sticker($sticker_id, $user_id);

        if ( $result ) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Sticker deleted successfully',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed_to_delete_sticker'], 403);
    }

    /**
     * Vote on a poll sticker
     */
    public static function vote_poll( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $sticker_id = (int) $req['sticker_id'];
        $option_index = (int) $req->get_param('option_index');

        global $wpdb;
        $table = $wpdb->prefix . Koopo_Stories_Stickers::TABLE_NAME;
        $story_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT story_id FROM `{$table}` WHERE id = %d",
            $sticker_id
        ) );

        if ( $story_id <= 0 || ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $result = Koopo_Stories_Stickers::vote_poll($sticker_id, $user_id, $option_index);

        if ( $result ) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Vote recorded successfully',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed_to_vote'], 400);
    }
}
