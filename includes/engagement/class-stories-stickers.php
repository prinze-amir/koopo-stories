<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Stickers
 * Manages interactive stickers: mentions, links, locations, polls, text, filters, AR effects, and media/GIF.
 */
class Koopo_Stories_Stickers {

    const TABLE_NAME = 'koopo_story_stickers';

    /**
     * Install the stickers table
     */
    public static function install() : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `story_id` BIGINT(20) UNSIGNED NOT NULL,
            `item_id` BIGINT(20) UNSIGNED NOT NULL,
            `sticker_type` VARCHAR(20) NOT NULL,
            `sticker_data` LONGTEXT NOT NULL,
            `position_x` DECIMAL(5,2) DEFAULT 50.00,
            `position_y` DECIMAL(5,2) DEFAULT 50.00,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `story_id_idx` (`story_id`),
            KEY `item_id_idx` (`item_id`),
            KEY `type_idx` (`sticker_type`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add a sticker to a story item
     */
    public static function add_sticker( int $story_id, int $item_id, string $type, array $data, float $x = 50.0, float $y = 50.0 ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $type = self::normalize_sticker_type($type);

        // Validate sticker type
        $allowed_types = ['mention', 'link', 'location', 'poll', 'text', 'media', 'shared_post', 'filter', 'ar_effect'];
        if ( ! in_array($type, $allowed_types, true) ) {
            return 0;
        }

        // Validate and sanitize data based on type
        $sanitized_data = self::sanitize_sticker_data($type, $data);
        if ( empty($sanitized_data) ) {
            return 0;
        }

        $result = $wpdb->insert(
            $table,
            [
                'story_id' => $story_id,
                'item_id' => $item_id,
                'sticker_type' => $type,
                'sticker_data' => wp_json_encode($sanitized_data),
                'position_x' => max(0, min(100, $x)),
                'position_y' => max(0, min(100, $y)),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%f', '%f', '%s']
        );

        if ( $result === false ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    private static function normalize_sticker_type( string $type ) : string {
        $type = sanitize_key($type);
        $aliases = [
            'gif' => 'media',
            'giphy' => 'media',
            'tenor' => 'media',
            'lottie' => 'media',
        ];
        return $aliases[$type] ?? $type;
    }

    /**
     * Get all stickers for a story item
     */
    public static function get_stickers( int $item_id ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE item_id = %d ORDER BY created_at ASC",
            $item_id
        ), ARRAY_A);

        if ( ! is_array($results) ) {
            return [];
        }

        // Decode JSON data for each sticker
        $stickers = [];
        foreach ( $results as $row ) {
            $data = json_decode($row['sticker_data'], true);
            if ( is_array($data) ) {
                // For mention stickers, ensure profile_url is included (for backward compatibility)
                if ( $row['sticker_type'] === 'mention' && ! isset($data['profile_url']) && isset($data['user_id']) ) {
                    $profile_url = '';
                    if ( function_exists('bp_core_get_user_domain') ) {
                        $profile_url = bp_core_get_user_domain((int) $data['user_id']);
                    }
                    $data['profile_url'] = $profile_url;
                }

                $stickers[] = [
                    'id' => (int) $row['id'],
                    'type' => $row['sticker_type'],
                    'data' => $data,
                    'position' => [
                        'x' => (float) $row['position_x'],
                        'y' => (float) $row['position_y'],
                    ],
                    'created_at' => $row['created_at'],
                ];
            }
        }

        return $stickers;
    }

    /**
     * Delete a sticker
     */
    public static function delete_sticker( int $sticker_id, int $user_id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get sticker to verify ownership
        $sticker = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.* FROM `{$table}` s
             INNER JOIN {$wpdb->posts} p ON s.story_id = p.ID
             WHERE s.id = %d",
            $sticker_id
        ), ARRAY_A);

        if ( ! $sticker ) {
            return false;
        }

        // Only story author or admin can delete
        $author_id = (int) get_post_field('post_author', $sticker['story_id']);
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return false;
        }

        $result = $wpdb->delete(
            $table,
            ['id' => $sticker_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Sanitize sticker data based on type
     */
    private static function sanitize_sticker_data( string $type, array $data ) : array {
        switch ( $type ) {
            case 'mention':
                // Mention: { user_id, username, display_name, profile_url }
                // Accept either user_id or username
                $user = false;

                if ( ! empty($data['user_id']) && is_numeric($data['user_id']) ) {
                    $user_id = (int) $data['user_id'];
                    $user = get_user_by('id', $user_id);
                } elseif ( ! empty($data['username']) ) {
                    $user = get_user_by('login', $data['username']);
                }

                if ( ! $user ) {
                    return [];
                }

                $user_id = (int) $user->ID;

                // Get BuddyBoss/BuddyPress profile URL if available
                $profile_url = '';
                if ( function_exists('bp_core_get_user_domain') ) {
                    $profile_url = bp_core_get_user_domain($user_id);
                }

                return [
                    'user_id' => $user_id,
                    'username' => $user->user_login,
                    'display_name' => $user->display_name,
                    'profile_url' => $profile_url,
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];

            case 'link':
                // Link: { url, title }
                if ( empty($data['url']) ) {
                    return [];
                }
                $url = esc_url_raw($data['url']);
                if ( empty($url) ) {
                    return [];
                }
                return [
                    'url' => $url,
                    'title' => isset($data['title']) ? sanitize_text_field($data['title']) : parse_url($url, PHP_URL_HOST),
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];

            case 'location':
                // Location: { name, lat/lng, address, provider metadata }
                if ( empty($data['name']) ) {
                    return [];
                }
                $lat = isset($data['lat']) ? (float) $data['lat'] : (isset($data['latitude']) ? (float) $data['latitude'] : null);
                $lng = isset($data['lng']) ? (float) $data['lng'] : (isset($data['longitude']) ? (float) $data['longitude'] : null);
                $provider = isset($data['provider']) ? sanitize_key($data['provider']) : '';
                if ( ! in_array($provider, ['', 'google', 'custom'], true) ) {
                    $provider = 'custom';
                }
                $provider_place_id = isset($data['provider_place_id']) ? sanitize_text_field((string) $data['provider_place_id']) : '';
                $map_url = '';
                if ( ! empty($data['map_url']) ) {
                    $map_url = esc_url_raw((string) $data['map_url']);
                } elseif ( ! empty($data['mapUrl']) ) {
                    $map_url = esc_url_raw((string) $data['mapUrl']);
                }
                return [
                    'name' => sanitize_text_field($data['name']),
                    'lat' => $lat,
                    'lng' => $lng,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'address' => isset($data['address']) ? sanitize_text_field($data['address']) : '',
                    'provider' => $provider,
                    'provider_place_id' => $provider_place_id,
                    'map_url' => $map_url,
                    'mapUrl' => $map_url,
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];

            case 'poll':
                // Poll: { question, options: [text, votes] }
                if ( empty($data['question']) || empty($data['options']) || ! is_array($data['options']) ) {
                    return [];
                }
                $options = [];
                foreach ( $data['options'] as $idx => $opt ) {
                    if ( is_string($opt) ) {
                        $text = sanitize_text_field($opt);
                        if ( $text === '' ) {
                            continue;
                        }
                        $options[] = [
                            'text' => $text,
                            'votes' => 0,
                        ];
                    } elseif ( is_array($opt) && isset($opt['text']) ) {
                        $text = sanitize_text_field($opt['text']);
                        if ( $text === '' ) {
                            continue;
                        }
                        $options[] = [
                            'text' => $text,
                            'votes' => isset($opt['votes']) ? (int) $opt['votes'] : 0,
                        ];
                    }
                }
                if ( count($options) < 2 || count($options) > 6 ) {
                    return [];
                }
                return [
                    'question' => sanitize_text_field($data['question']),
                    'options' => $options,
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];
            case 'text':
                if ( empty($data['text']) ) {
                    return [];
                }
                return [
                    'text' => sanitize_textarea_field($data['text']),
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];
            case 'filter':
                $key = isset($data['key']) ? sanitize_key($data['key']) : '';
                $allowed_filter_keys = ['warm', 'cool', 'mono', 'golden', 'cinema', 'rose', 'neon'];
                if ( ! in_array($key, $allowed_filter_keys, true) ) {
                    return [];
                }
                $tint = isset($data['tint']) ? sanitize_hex_color((string) $data['tint']) : '';
                $opacity = isset($data['opacity']) ? (float) $data['opacity'] : 0.0;
                if ( ! $tint || $opacity <= 0 ) {
                    return [];
                }
                return [
                    'key' => $key,
                    'tint' => $tint,
                    'opacity' => max(0.0, min(0.55, $opacity)),
                ];
            case 'ar_effect':
                $key = isset($data['key']) ? sanitize_key($data['key']) : '';
                $allowed_ar_effect_keys = ['glasses', 'beauty-glow', 'soft-glam', 'fresh-tone', 'studio-warm'];
                if ( ! in_array($key, $allowed_ar_effect_keys, true) ) {
                    return [];
                }
                return [
                    'key' => $key,
                    'provider' => isset($data['provider']) ? sanitize_key($data['provider']) : 'mediapipe',
                    'renderer' => isset($data['renderer']) ? sanitize_key($data['renderer']) : 'skia',
                    'frame' => self::sanitize_ar_effect_frame($data['frame'] ?? null),
                ];
            case 'media':
                if (empty($data['url'])) {
                    return [];
                }
                $url = esc_url_raw($data['url']);
                if (!$url) {
                    return [];
                }
                $mime = isset($data['mime']) ? sanitize_text_field($data['mime']) : '';
                if ( $mime === '' ) {
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if ( $ext === 'gif' ) {
                        $mime = 'image/gif';
                    } elseif ( $ext === 'json' ) {
                        $mime = 'application/json';
                    }
                }

                $provider = isset($data['provider']) ? sanitize_key($data['provider']) : '';
                if ( $provider === '' && $mime === 'image/gif' ) {
                    $provider = 'giphy';
                }
                if ( ! in_array($provider, ['', 'giphy', 'tenor', 'lottie', 'external'], true) ) {
                    $provider = 'external';
                }
                $title = isset($data['title']) ? sanitize_text_field($data['title']) : '';
                return [
                    'url' => $url,
                    'mime' => $mime,
                    'provider' => $provider,
                    'title' => $title,
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];
            case 'shared_post':
                $title = isset($data['title']) ? sanitize_text_field($data['title']) : '';
                $caption = isset($data['caption']) ? sanitize_textarea_field($data['caption']) : '';
                $author_name = isset($data['author_name']) ? sanitize_text_field($data['author_name']) : (isset($data['authorName']) ? sanitize_text_field($data['authorName']) : '');
                $image_url = '';
                if ( ! empty($data['image_url']) ) {
                    $image_url = esc_url_raw((string) $data['image_url']);
                } elseif ( ! empty($data['imageUrl']) ) {
                    $image_url = esc_url_raw((string) $data['imageUrl']);
                }
                $link_url = '';
                if ( ! empty($data['link_url']) ) {
                    $link_url = esc_url_raw((string) $data['link_url']);
                } elseif ( ! empty($data['linkUrl']) ) {
                    $link_url = esc_url_raw((string) $data['linkUrl']);
                }
                $link_title = isset($data['link_title']) ? sanitize_text_field($data['link_title']) : (isset($data['linkTitle']) ? sanitize_text_field($data['linkTitle']) : '');

                if ( $title === '' && $caption === '' && $image_url === '' ) {
                    return [];
                }

                return [
                    'author_name' => $author_name,
                    'authorName' => $author_name,
                    'title' => $title,
                    'caption' => $caption,
                    'image_url' => $image_url,
                    'imageUrl' => $image_url,
                    'link_url' => $link_url,
                    'linkUrl' => $link_url,
                    'link_title' => $link_title,
                    'linkTitle' => $link_title,
                    'style' => self::sanitize_style($data['style'] ?? []),
                ];
            default:
                return [];
        }
    }

    private static function sanitize_style( array $style ) : array {
        $out = [];
        if (isset($style['scale'])) {
            $out['scale'] = max(0.5, min(2.0, (float) $style['scale']));
        }
        if (isset($style['box_w'])) {
            $out['box_w'] = max(80, min(600, (int) $style['box_w']));
        }
        if (isset($style['box_h'])) {
            $out['box_h'] = max(30, min(400, (int) $style['box_h']));
        }
        if (isset($style['text_color'])) {
            $c = sanitize_hex_color($style['text_color']);
            if ($c) $out['text_color'] = $c;
        }
        if (isset($style['bg_color'])) {
            $c = sanitize_hex_color($style['bg_color']);
            if ($c) $out['bg_color'] = $c;
        }
        if (isset($style['bg_opacity'])) {
            $out['bg_opacity'] = max(0.0, min(1.0, (float) $style['bg_opacity']));
        }
        if (isset($style['font_size'])) {
            $out['font_size'] = max(10, min(72, (int) $style['font_size']));
        }
        if (isset($style['font_family'])) {
            $allowed_fonts = ['inherit', 'Georgia', 'Trebuchet MS', 'Courier New', 'Tahoma'];
            if (in_array($style['font_family'], $allowed_fonts, true)) {
                $out['font_family'] = $style['font_family'];
            }
        }
        if (isset($style['font_weight'])) {
            $allowed_weights = ['normal', 'bold', '500', '600', '700'];
            if (in_array((string) $style['font_weight'], $allowed_weights, true)) {
                $out['font_weight'] = (string) $style['font_weight'];
            }
        }
        if (isset($style['font_style'])) {
            $allowed_styles = ['normal', 'italic'];
            if (in_array((string) $style['font_style'], $allowed_styles, true)) {
                $out['font_style'] = (string) $style['font_style'];
            }
        }
        if (isset($style['text_decoration'])) {
            $allowed_decorations = ['none', 'underline'];
            if (in_array((string) $style['text_decoration'], $allowed_decorations, true)) {
                $out['text_decoration'] = (string) $style['text_decoration'];
            }
        }
        if (isset($style['text_align'])) {
            $allowed_align = ['left', 'center', 'right'];
            if (in_array((string) $style['text_align'], $allowed_align, true)) {
                $out['text_align'] = (string) $style['text_align'];
            }
        }
        return $out;
    }

    private static function sanitize_ar_effect_frame( $frame ) : array {
        if ( ! is_array($frame) ) {
            return [];
        }

        $out = [
            'source' => isset($frame['source']) ? sanitize_key((string) $frame['source']) : 'mediapipe',
            'status' => isset($frame['status']) ? sanitize_text_field((string) $frame['status']) : '',
            'width' => isset($frame['width']) ? max(0, (float) $frame['width']) : null,
            'height' => isset($frame['height']) ? max(0, (float) $frame['height']) : null,
            'orientation' => isset($frame['orientation']) ? sanitize_key((string) $frame['orientation']) : '',
            'mirrored' => ! empty($frame['mirrored']),
            'faces' => [],
        ];

        $faces = isset($frame['faces']) && is_array($frame['faces']) ? array_slice($frame['faces'], 0, 1) : [];
        foreach ( $faces as $face_index => $face ) {
            if ( ! is_array($face) ) {
                continue;
            }
            $points = isset($face['points']) && is_array($face['points']) ? array_slice($face['points'], 0, 520) : [];
            $safe_points = [];
            foreach ( $points as $point_index => $point ) {
                if ( ! is_array($point) ) {
                    continue;
                }
                $x = isset($point['x']) ? (float) $point['x'] : null;
                $y = isset($point['y']) ? (float) $point['y'] : null;
                if ( $x === null || $y === null || ! is_finite($x) || ! is_finite($y) ) {
                    continue;
                }
                $safe_points[] = [
                    'id' => isset($point['id']) ? sanitize_key((string) $point['id']) : ('p' . (int) $point_index),
                    'x' => max(0.0, min(1.0, $x)),
                    'y' => max(0.0, min(1.0, $y)),
                    'z' => isset($point['z']) && is_finite((float) $point['z']) ? (float) $point['z'] : 0.0,
                ];
            }
            if ( ! empty($safe_points) ) {
                $out['faces'][] = [
                    'id' => isset($face['id']) ? sanitize_key((string) $face['id']) : ('face-' . (int) $face_index),
                    'points' => $safe_points,
                ];
            }
        }

        return $out;
    }

    /**
     * Record a poll vote
     */
    public static function vote_poll( int $sticker_id, int $user_id, int $option_index ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $votes_table = $wpdb->prefix . 'koopo_story_poll_votes';

        // Get the sticker
        $sticker = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND sticker_type = 'poll'",
            $sticker_id
        ), ARRAY_A);

        if ( ! $sticker ) {
            return false;
        }

        $data = json_decode($sticker['sticker_data'], true);
        if ( ! is_array($data) || ! isset($data['options']) ) {
            return false;
        }

        // Validate option index
        if ( $option_index < 0 || $option_index >= count($data['options']) ) {
            return false;
        }

        // Check if user already voted
        $existing_vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$votes_table}` WHERE sticker_id = %d AND user_id = %d",
            $sticker_id,
            $user_id
        ));

        if ( $existing_vote ) {
            // Update existing vote
            $wpdb->update(
                $votes_table,
                ['option_index' => $option_index],
                ['id' => $existing_vote],
                ['%d'],
                ['%d']
            );
        } else {
            // Record new vote
            $wpdb->insert(
                $votes_table,
                [
                    'sticker_id' => $sticker_id,
                    'user_id' => $user_id,
                    'option_index' => $option_index,
                    'voted_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%s']
            );
        }

        // Update vote counts in sticker data
        $vote_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_index, COUNT(*) as count FROM `{$votes_table}` WHERE sticker_id = %d GROUP BY option_index",
            $sticker_id
        ), ARRAY_A);

        // Reset all vote counts
        foreach ( $data['options'] as $idx => $opt ) {
            $data['options'][$idx]['votes'] = 0;
        }

        // Update with actual counts
        foreach ( $vote_counts as $count_row ) {
            $idx = (int) $count_row['option_index'];
            if ( isset($data['options'][$idx]) ) {
                $data['options'][$idx]['votes'] = (int) $count_row['count'];
            }
        }

        // Save updated data
        $wpdb->update(
            $table,
            ['sticker_data' => wp_json_encode($data)],
            ['id' => $sticker_id],
            ['%s'],
            ['%d']
        );

        return true;
    }

    /**
     * Install poll votes table
     */
    public static function install_poll_votes_table() : void {
        global $wpdb;
        $table = $wpdb->prefix . 'koopo_story_poll_votes';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `sticker_id` BIGINT(20) UNSIGNED NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `option_index` INT NOT NULL,
            `voted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_sticker_vote` (`sticker_id`, `user_id`),
            KEY `user_id_idx` (`user_id`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get user's vote for a poll
     */
    public static function get_user_vote( int $sticker_id, int $user_id ) : ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'koopo_story_poll_votes';

        $vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_index FROM `{$table}` WHERE sticker_id = %d AND user_id = %d",
            $sticker_id,
            $user_id
        ));

        return $vote !== null ? (int) $vote : null;
    }
}
