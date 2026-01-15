<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Reactions
 * Manages emoji reactions on stories
 */
class Koopo_Stories_Reactions {

    const TABLE_NAME = 'koopo_story_reactions';

    /**
     * Install the reactions table
     */
    public static function install() : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `story_id` BIGINT(20) UNSIGNED NOT NULL,
            `item_id` BIGINT(20) UNSIGNED DEFAULT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `reaction` VARCHAR(10) NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_story_reaction` (`user_id`, `story_id`, `item_id`),
            KEY `story_id_idx` (`story_id`),
            KEY `item_id_idx` (`item_id`),
            KEY `user_id_idx` (`user_id`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add or update a reaction
     */
    public static function add_reaction( int $story_id, int $user_id, string $reaction, int $item_id = null ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Validate reaction (emoji or simple string)
        $allowed_reactions = [
            '❤️', '😂', '😮', '😢', '👏', '🔥', '💩', '🤦🏽‍♂️', '👿',
            '🤯', '😘', '😡', '🥰', '😳', '🥶', 'like',
        ];
        $allowed_reactions = apply_filters('koopo_stories_allowed_reactions', $allowed_reactions);
        if ( ! in_array($reaction, $allowed_reactions, true) ) {
            return false;
        }

        $result = $wpdb->replace(
            $table,
            [
                'story_id' => $story_id,
                'item_id' => $item_id,
                'user_id' => $user_id,
                'reaction' => $reaction,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Remove a reaction
     */
    public static function remove_reaction( int $story_id, int $user_id, int $item_id = null ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $where = [
            'story_id' => $story_id,
            'user_id' => $user_id,
        ];

        $where_format = ['%d', '%d'];

        if ( $item_id !== null ) {
            $where['item_id'] = $item_id;
            $where_format[] = '%d';
        } else {
            // For null item_id, we need a custom query
            $result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE story_id = %d AND user_id = %d AND item_id IS NULL",
                $story_id,
                $user_id
            ));
            return $result !== false;
        }

        $result = $wpdb->delete($table, $where, $where_format);
        return $result !== false;
    }

    /**
     * Get reactions for a story
     */
    public static function get_reactions( int $story_id, int $item_id = null ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $item_id !== null ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, reaction, created_at FROM `{$table}` WHERE story_id = %d AND item_id = %d ORDER BY created_at DESC",
                $story_id,
                $item_id
            ), ARRAY_A);
        } else {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, reaction, created_at FROM `{$table}` WHERE story_id = %d AND item_id IS NULL ORDER BY created_at DESC",
                $story_id
            ), ARRAY_A);
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Get reaction counts grouped by reaction type
     */
    public static function get_reaction_counts( int $story_id, int $item_id = null ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $item_id !== null ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT reaction, COUNT(*) as count FROM `{$table}` WHERE story_id = %d AND item_id = %d GROUP BY reaction",
                $story_id,
                $item_id
            ), ARRAY_A);
        } else {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT reaction, COUNT(*) as count FROM `{$table}` WHERE story_id = %d AND item_id IS NULL GROUP BY reaction",
                $story_id
            ), ARRAY_A);
        }

        $counts = [];
        if ( is_array($results) ) {
            foreach ( $results as $row ) {
                $counts[$row['reaction']] = (int) $row['count'];
            }
        }

        return $counts;
    }

    /**
     * Get reaction counts per item for a story
     */
    public static function get_reaction_counts_by_item( int $story_id, array $item_ids ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));
        if ( empty($item_ids) ) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, COUNT(*) as count
             FROM `{$table}`
             WHERE story_id = %d AND item_id IN ({$placeholders})
             GROUP BY item_id",
            array_merge([ $story_id ], $item_ids)
        ), ARRAY_A);

        $counts = [];
        if ( is_array($results) ) {
            foreach ( $results as $row ) {
                $item_id = (int) ($row['item_id'] ?? 0);
                $counts[$item_id] = (int) ($row['count'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Get user's reaction on a story
     */
    public static function get_user_reaction( int $story_id, int $user_id, int $item_id = null ) : ?string {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $item_id !== null ) {
            $reaction = $wpdb->get_var( $wpdb->prepare(
                "SELECT reaction FROM `{$table}` WHERE story_id = %d AND user_id = %d AND item_id = %d",
                $story_id,
                $user_id,
                $item_id
            ));
        } else {
            $reaction = $wpdb->get_var( $wpdb->prepare(
                "SELECT reaction FROM `{$table}` WHERE story_id = %d AND user_id = %d AND item_id IS NULL",
                $story_id,
                $user_id
            ));
        }

        return $reaction ?: null;
    }

    /**
     * Get reactions for multiple users on a story (map of user_id => reaction)
     */
    public static function get_reactions_map( int $story_id, array $user_ids, int $item_id = null ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $user_ids = array_values(array_filter(array_map('intval', $user_ids)));
        if ( empty($user_ids) ) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $params = array_merge([ $story_id ], $user_ids);

        if ( $item_id !== null ) {
            $query = $wpdb->prepare(
                "SELECT user_id, reaction FROM `{$table}` WHERE story_id = %d AND item_id = %d AND user_id IN ({$placeholders})",
                array_merge([ $story_id, $item_id ], $user_ids)
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT user_id, reaction FROM `{$table}` WHERE story_id = %d AND item_id IS NULL AND user_id IN ({$placeholders})",
                $params
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        if ( ! is_array($rows) ) {
            return [];
        }

        $map = [];
        foreach ( $rows as $row ) {
            $uid = (int) ($row['user_id'] ?? 0);
            $reaction = $row['reaction'] ?? '';
            if ( $uid > 0 && $reaction !== '' ) {
                $map[$uid] = $reaction;
            }
        }

        return $map;
    }

    /**
     * Get total reaction count for a story
     */
    public static function get_total_count( int $story_id, int $item_id = null ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $item_id !== null ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE story_id = %d AND item_id = %d",
                $story_id,
                $item_id
            ));
        } else {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE story_id = %d AND item_id IS NULL",
                $story_id
            ));
        }

        return $count;
    }
}
