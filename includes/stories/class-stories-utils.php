<?php
if ( ! defined('ABSPATH') ) exit;

final class Koopo_Stories_Utils {

    private static $user_cache = [];
    private static $avatar_cache = [];
    private static $profile_url_cache = [];

    public static function can_user_post_story( int $user_id ) : bool {
        if ( $user_id <= 0 ) {
            return false;
        }

        if ( get_option( Koopo_Stories_Module::OPTION_ENABLE, '1' ) !== '1' ) {
            return false;
        }

        $allowed = apply_filters( 'koopo_stories_user_can_upload', true, $user_id );
        return (bool) $allowed;
    }

    public static function ensure_can_upload() {
        $user_id = get_current_user_id();
        if ( ! self::can_user_post_story( $user_id ) ) {
            return new WP_REST_Response([ 'error' => 'forbidden', 'message' => 'upload_not_allowed' ], 403);
        }

        return null;
    }

    public static function enforce_daily_upload_limit( int $user_id ) {
        $max_per_day = (int) get_option('koopo_stories_max_uploads_per_day', 20);
        if ( $max_per_day <= 0 ) {
            return null;
        }

        $today_start = strtotime('today', current_time('timestamp'));
        $ids_today = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'any',
            'author' => $user_id,
            'fields' => 'ids',
            'posts_per_page' => $max_per_day,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results' => false,
            'date_query' => [
                [
                    'after' => date('Y-m-d H:i:s', $today_start),
                    'inclusive' => true,
                ],
            ],
        ]);

        if ( is_array($ids_today) && count($ids_today) >= $max_per_day ) {
            return new WP_REST_Response([ 'error' => 'limit_reached', 'message' => 'daily_upload_limit' ], 429);
        }

        return null;
    }

    public static function get_story_duration_hours() : int {
        $duration_hours = (int) get_option('koopo_stories_duration_hours', 24);
        return $duration_hours < 1 ? 24 : $duration_hours;
    }

    public static function get_story_expiry_timestamp() : int {
        return time() + ( self::get_story_duration_hours() * HOUR_IN_SECONDS );
    }

    public static function get_max_items_per_story() : int {
        $max_items_per_story = (int) get_option('koopo_stories_max_items_per_story', 20);
        return $max_items_per_story < 0 ? 0 : $max_items_per_story;
    }

    public static function is_story_at_item_limit( int $story_id, int $max_items_per_story ) : bool {
        if ( $max_items_per_story <= 0 || $story_id <= 0 ) {
            return false;
        }

        $item_ids = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => $max_items_per_story,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results' => false,
            'meta_query' => [
                [
                    'key' => 'story_id',
                    'value' => $story_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return is_array($item_ids) && count($item_ids) >= $max_items_per_story;
    }

    public static function prepare_upload_file( WP_REST_Request $req ) {
        $files = $req->get_file_params();
        if ( ( empty($_FILES['file']) || ! is_array($_FILES['file']) ) && isset($files['file']) ) {
            $_FILES['file'] = $files['file'];
        }

        if ( empty($_FILES['file']) || ! is_array($_FILES['file']) ) {
            $max_upload = self::get_max_upload_size_bytes();
            $content_len = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
            $message = $content_len > 0
                ? 'Upload exceeded server limits.'
                : 'Missing file.';
            return [
                'error' => new WP_REST_Response([
                    'error' => 'missing_file',
                    'message' => $message,
                    'max_upload_bytes' => $max_upload,
                ], 400),
            ];
        }

        return [ 'file' => $_FILES['file'] ];
    }

    public static function get_max_upload_size_bytes() : int {
        $configured_max = self::get_direct_max_upload_size_bytes();
        $server_max = (int) wp_max_upload_size();
        return $server_max > 0 ? min($configured_max, $server_max) : $configured_max;
    }

    public static function get_direct_max_upload_size_bytes() : int {
        $max_mb = (int) get_option('koopo_stories_max_upload_size_mb', 50);
        if ( $max_mb < 1 ) $max_mb = 1;

        return $max_mb * MB_IN_BYTES;
    }

    public static function get_allowed_upload_mimes() : array {
        $allowed_images = (array) get_option('koopo_stories_allowed_image_mimes', ['image/jpeg','image/png','image/webp','image/avif']);
        $allowed_videos = (array) get_option('koopo_stories_allowed_video_mimes', ['video/mp4','video/webm']);
        return array_values(array_unique(array_map('sanitize_mime_type', array_merge($allowed_images, $allowed_videos))));
    }

    public static function validate_upload_file( array $file ) {
        $max_bytes = self::get_max_upload_size_bytes();
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ( $size > 0 && $size > $max_bytes ) {
            return new WP_REST_Response([
                'error' => 'file_too_large',
                'message' => 'File exceeds the allowed size.',
                'max_upload_bytes' => $max_bytes,
            ], 400);
        }

        $allowed_mimes = self::get_allowed_upload_mimes();

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $mime = $filetype['type'] ?? '';
        if ( empty($mime) || ! in_array($mime, $allowed_mimes, true) ) {
            return new WP_REST_Response([
                'error' => 'invalid_file_type',
                'message' => 'File type not allowed.',
                'allowed_mimes' => $allowed_mimes,
            ], 400);
        }

        return null;
    }

    public static function build_feed_cache_key( int $user_id, array $params ) : string {
        $user_salt = (string) get_user_meta($user_id, 'koopo_stories_feed_salt', true);
        $global_salt = (string) get_option('koopo_stories_feed_global_salt', '');
        $payload = [
            'user_id' => $user_id,
            'scope' => $params['scope'] ?? '',
            'only_me' => isset($params['only_me']) ? (int) $params['only_me'] : 0,
            'exclude_me' => isset($params['exclude_me']) ? (int) $params['exclude_me'] : 0,
            'order' => $params['order'] ?? '',
            'limit' => isset($params['limit']) ? (int) $params['limit'] : 0,
            'compact' => isset($params['compact']) ? (int) $params['compact'] : 0,
            'user_salt' => $user_salt,
            'global_salt' => $global_salt,
        ];
        ksort($payload);

        return 'koopo_stories_feed_' . md5(wp_json_encode($payload));
    }

    public static function get_author_payload( int $author_id, int $avatar_size = 96, bool $include_profile_url = true ) : array {
        $author = self::get_user_cached($author_id);
        $name = $author ? $author->display_name : ('User #' . $author_id);
        $profile_url = '';
        if ( $include_profile_url ) {
            $profile_url = self::get_profile_url_cached($author_id);
        }

        $payload = [
            'id'      => $author_id,
            'user_id' => $author_id,
            'userId'  => $author_id,
            'name'    => $name,
            'avatar'  => self::get_avatar_url_cached($author_id, $avatar_size),
        ];

        if ( $include_profile_url ) {
            $payload['profile_url'] = $profile_url;
        }

        return $payload;
    }

    public static function get_avatar_url_cached( int $user_id, int $size ) : string {
        if ( $user_id <= 0 ) return '';
        $size = max(1, $size);
        $key = $user_id . ':' . $size;
        if ( array_key_exists($key, self::$avatar_cache) ) {
            return self::$avatar_cache[$key];
        }

        $url = '';
        if ( function_exists('bp_core_fetch_avatar') ) {
            $bb_url = bp_core_fetch_avatar([
                'item_id' => $user_id,
                'type' => 'full',
                'width' => $size,
                'height' => $size,
                'html' => false,
            ]);
            if ( is_string($bb_url) && $bb_url !== '' ) {
                $url = $bb_url;
            }
        }
        if ( $url === '' ) {
            $url = get_avatar_url($user_id, [ 'size' => $size ]);
        }
        self::$avatar_cache[$key] = is_string($url) ? $url : '';
        return self::$avatar_cache[$key];
    }

    public static function get_profile_url_cached( int $user_id ) : string {
        if ( $user_id <= 0 ) return '';
        if ( array_key_exists($user_id, self::$profile_url_cache) ) {
            return self::$profile_url_cache[$user_id];
        }

        $url = '';
        if ( function_exists('bp_core_get_user_domain') ) {
            $url = bp_core_get_user_domain($user_id);
        }

        self::$profile_url_cache[$user_id] = is_string($url) ? $url : '';
        return self::$profile_url_cache[$user_id];
    }

    public static function get_user_display_name( int $user_id, string $fallback = 'User' ) : string {
        $user = self::get_user_cached($user_id);
        return $user ? $user->display_name : $fallback;
    }

    public static function get_user_cached( int $user_id ) {
        if ( $user_id <= 0 ) return null;
        if ( array_key_exists($user_id, self::$user_cache) ) {
            return self::$user_cache[$user_id];
        }

        $user = get_user_by('id', $user_id);
        self::$user_cache[$user_id] = $user ?: null;
        return self::$user_cache[$user_id];
    }

    public static function build_archive_cache_key( int $user_id, array $params ) : string {
        $user_salt = (string) get_user_meta($user_id, 'koopo_stories_feed_salt', true);
        $payload = [
            'user_id' => $user_id,
            'limit' => isset($params['limit']) ? (int) $params['limit'] : 0,
            'page' => isset($params['page']) ? (int) $params['page'] : 0,
            'compact' => isset($params['compact']) ? (int) $params['compact'] : 0,
            'user_salt' => $user_salt,
        ];
        ksort($payload);

        return 'koopo_stories_archive_' . md5(wp_json_encode($payload));
    }

    public static function get_cache_ttl( string $key, int $default = 60 ) : int {
        $ttl = (int) apply_filters('koopo_stories_cache_ttl', $default, $key);
        return $ttl > 0 ? $ttl : $default;
    }

    public static function build_story_item_payload( int $item_id, bool $compact = false ) : ?array {
        $attachment_id = (int) get_post_meta($item_id, 'attachment_id', true);
        $type = get_post_meta($item_id, 'media_type', true);
        if ( empty($type) && $attachment_id ) {
            $mime_guess = get_post_mime_type($attachment_id);
            $type = (is_string($mime_guess) && strpos($mime_guess, 'video/') === 0) ? 'video' : 'image';
        } else {
            $type = ($type === 'video') ? 'video' : 'image';
        }

        $src = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        if ( empty($src) ) {
            return null;
        }

        $thumb = '';
        if ( ! $compact && $attachment_id ) {
            $t = wp_get_attachment_image_src($attachment_id, 'medium');
            if ( is_array($t) && ! empty($t[0]) ) $thumb = $t[0];
        }

        $duration = (int) get_post_meta($item_id, 'duration_ms', true);
        if ( $duration <= 0 && $type === 'image' ) $duration = 5000;

        $payload = [
            'item_id' => $item_id,
            'type' => $type,
            'src' => $src,
            'duration_ms' => $type === 'image' ? $duration : null,
        ];

        if ( $type === 'video' && $attachment_id ) {
            $payload['poster'] = esc_url_raw((string) get_post_meta($attachment_id, '_koopo_media_thumbnail_url', true));
            $payload['hls_src'] = esc_url_raw((string) get_post_meta($attachment_id, '_koopo_media_final_delivery_url', true));
            $payload['media_status'] = sanitize_key((string) get_post_meta($attachment_id, '_koopo_media_status', true));
        }

        if ( ! $compact ) {
            $payload['thumb'] = $thumb;
        }

        return $payload;
    }

    public static function get_story_cover_thumb( int $item_id, string $size = 'thumbnail' ) : string {
        if ( $item_id <= 0 ) {
            return '';
        }

        $attachment_id = (int) get_post_meta($item_id, 'attachment_id', true);
        if ( ! $attachment_id ) {
            return '';
        }

        $remote_thumb = esc_url_raw((string) get_post_meta($attachment_id, '_koopo_media_thumbnail_url', true));
        if ( $remote_thumb !== '' ) {
            return $remote_thumb;
        }

        $thumb = wp_get_attachment_image_url($attachment_id, $size);
        return is_string($thumb) ? $thumb : '';
    }

    /**
     * Get cover media for a story item (works for both images and videos).
     * Returns an array with 'url', 'type', and 'thumb' (if available).
     */
    public static function get_story_cover_media( int $item_id, string $size = 'medium' ) : array {
        $result = [
            'url' => '',
            'type' => 'image',
            'thumb' => '',
        ];

        if ( $item_id <= 0 ) {
            return $result;
        }

        $attachment_id = (int) get_post_meta($item_id, 'attachment_id', true);
        if ( ! $attachment_id ) {
            return $result;
        }

        // Determine media type
        $media_type = get_post_meta($item_id, 'media_type', true);
        if ( empty($media_type) ) {
            $mime = get_post_mime_type($attachment_id);
            $media_type = (is_string($mime) && strpos($mime, 'video/') === 0) ? 'video' : 'image';
        }
        $result['type'] = $media_type;

        // Get full URL
        $full_url = wp_get_attachment_url($attachment_id);
        $result['url'] = is_string($full_url) ? $full_url : '';

        // Try to get thumbnail (works for images, returns empty for videos)
        $thumb = wp_get_attachment_image_url($attachment_id, $size);
        if ( is_string($thumb) && $thumb !== '' ) {
            $result['thumb'] = $thumb;
        }

        // For videos/gifs without a thumb, the full URL will be used for display
        return $result;
    }

    public static function get_privacy_rank( string $privacy ) : int {
        $privacy = self::normalize_privacy($privacy);
        $privacy_rank = [
            'public' => 0,
            'friends' => 1,
            'close_friends' => 2,
        ];

        return $privacy_rank[$privacy] ?? 0;
    }

    public static function pick_more_restrictive_privacy( string $current, string $next ) : string {
        $current_rank = self::get_privacy_rank($current);
        $next_rank = self::get_privacy_rank($next);
        return $next_rank > $current_rank ? self::normalize_privacy($next) : self::normalize_privacy($current);
    }

    public static function rate_limit( string $action, int $user_id, int $max, int $window ) : bool {
        if ( $user_id <= 0 || $max <= 0 || $window <= 0 ) return true;
        $key = 'koopo_stories_rl_' . $action . '_' . $user_id;
        $state = get_transient($key);
        $now = time();

        if ( ! is_array($state) || empty($state['reset']) || $state['reset'] <= $now ) {
            set_transient($key, [ 'count' => 1, 'reset' => $now + $window ], $window);
            return true;
        }

        if ( (int) $state['count'] >= $max ) {
            return false;
        }

        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, $state['reset'] - $now);
        return true;
    }

    public static function get_default_privacy() : string {
        return self::normalize_privacy_value(get_option('koopo_stories_default_privacy', 'public'), 'public');
    }

    public static function normalize_privacy( $privacy, ?string $fallback = null ) : string {
        $fallback = $fallback === null
            ? self::get_default_privacy()
            : self::normalize_privacy_value($fallback, 'friends');

        return self::normalize_privacy_value($privacy, $fallback);
    }

    private static function normalize_privacy_value( $privacy, string $fallback ) : string {
        $privacy = is_string($privacy) ? sanitize_key($privacy) : '';
        if ( $privacy === 'connections' ) {
            return 'friends';
        }

        $allowed = ['public', 'friends', 'close_friends'];
        if ( in_array($privacy, $allowed, true) ) {
            return $privacy;
        }

        return in_array($fallback, $allowed, true) ? $fallback : 'friends';
    }
}
