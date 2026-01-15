<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_Cleanup {

    public static function run() : void {
        $expired_story_ids = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 200,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'expires_at',
                    'value' => time(),
                    'compare' => '<=',
                    'type' => 'NUMERIC',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => 'is_archived',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => 'is_archived',
                        'value' => 1,
                        'compare' => '!=',
                        'type' => 'NUMERIC',
                    ],
                ],
            ],
        ]);

        if ( empty($expired_story_ids) ) return;

        foreach ( $expired_story_ids as $story_id ) {
            $sid = (int) $story_id;
            update_post_meta($sid, 'is_archived', 1);
        }
    }

    public static function delete_story( int $story_id ) : void {
        // Delete items
        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
        ]);

        // Delete view rows
        if ( ! empty($items) ) {
            global $wpdb;
            $table = Koopo_Stories_Views_Table::table_name();
            $placeholders = implode(',', array_fill(0, count($items), '%d'));
            $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE story_item_id IN ({$placeholders})", array_map('intval', $items)) );
        }

        foreach ($items as $item_id) {
            wp_delete_post((int)$item_id, true);
        }

        wp_delete_post($story_id, true);
    }
}
