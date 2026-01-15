<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST_Stickers {

    /**
     * Add a sticker to a story item
     */
    public static function add_sticker( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = (int) $req['item_id'];
        $type = sanitize_text_field( $req->get_param('type') );
        $data = $req->get_param('data');
        $position_x = (float) ($req->get_param('position_x') ?: 50.0);
        $position_y = (float) ($req->get_param('position_y') ?: 50.0);

        // Log incoming request for debugging
        error_log('Koopo Stories - Add Sticker Request: ' . json_encode([
            'user_id' => $user_id,
            'story_id' => $story_id,
            'item_id' => $item_id,
            'type' => $type,
            'data' => $data,
            'position_x' => $position_x,
            'position_y' => $position_y,
        ]));

        // Verify story ownership
        $author_id = (int) get_post_field('post_author', $story_id);
        if ( $author_id !== $user_id ) {
            error_log('Koopo Stories - Add Sticker Error: User not story owner. User: ' . $user_id . ', Author: ' . $author_id);
            return new WP_REST_Response(['error' => 'not_story_owner'], 403);
        }

        // Verify item belongs to story
        $item_story_id = (int) get_post_meta($item_id, 'story_id', true);
        if ( $item_story_id !== $story_id ) {
            error_log('Koopo Stories - Add Sticker Error: Item does not belong to story. Item story_id: ' . $item_story_id . ', Expected: ' . $story_id);
            return new WP_REST_Response(['error' => 'invalid_item'], 400);
        }

        if ( ! is_array($data) ) {
            error_log('Koopo Stories - Add Sticker Error: Data is not an array. Type: ' . gettype($data) . ', Value: ' . json_encode($data));
            return new WP_REST_Response(['error' => 'invalid_data'], 400);
        }

        // Check if stickers table exists
        global $wpdb;
        $stickers_table = $wpdb->prefix . 'koopo_story_stickers';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stickers_table}'") === $stickers_table;

        if ( ! $table_exists ) {
            error_log('Koopo Stories - Add Sticker Error: Stickers table does not exist: ' . $stickers_table);
            return new WP_REST_Response([
                'error' => 'stickers_table_missing',
                'message' => 'Database table not found. Please deactivate and reactivate the plugin.',
            ], 500);
        }

        $sticker_id = Koopo_Stories_Stickers::add_sticker($story_id, $item_id, $type, $data, $position_x, $position_y);

        if ( $sticker_id > 0 ) {
            error_log('Koopo Stories - Add Sticker Success: Sticker ID ' . $sticker_id);
            return new WP_REST_Response([
                'success' => true,
                'sticker_id' => $sticker_id,
                'message' => 'Sticker added successfully',
            ], 200);
        }

        error_log('Koopo Stories - Add Sticker Error: add_sticker() returned 0 or false');
        return new WP_REST_Response([
            'error' => 'failed_to_add_sticker',
            'message' => 'Sticker validation or database insert failed. Check error log for details.',
        ], 500);
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
