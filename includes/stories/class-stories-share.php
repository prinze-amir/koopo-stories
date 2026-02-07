<?php
if (!defined('ABSPATH'))
    exit;

class Koopo_Stories_Share
{
    private static function get_share_icon_url(): string
    {
        $choice = get_option('koopo_stories_share_icon', 'share-one');
        $map = [
            'share-one' => 'icons/koopo-share-one.png',
            'share-two' => 'icons/koopo-share-two.png',
            'share-three' => 'icons/koopo-share-three.png',
        ];
        $file = $map[$choice] ?? $map['share-one'];
        $path = KOOPO_STORIES_PATH . 'assets/' . $file;
        if (!file_exists($path)) {
            return '';
        }
        return plugins_url('assets/' . $file, KOOPO_STORIES_PATH . 'koopo-stories.php');
    }

    private static function get_share_icon_markup(string $label): string
    {
        $icon_url = self::get_share_icon_url();
        if ($icon_url) {
            return sprintf(
                '<img src="%s" alt="%s" class="koopo-share-icon" />',
                esc_url($icon_url),
                esc_attr($label)
            );
        }

        return sprintf(
            '<span class="dashicons dashicons-share" aria-hidden="true"></span><span class="share-label">%s</span>',
            esc_html($label)
        );
    }

    public static function init()
    {
        add_filter('the_content', [__CLASS__, 'append_share_button']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_scripts']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_super_socializer_integration']);
        add_action('wp_ajax_koopo_stories_get_activity_media', [__CLASS__, 'ajax_get_activity_media']);
        add_action('wp_ajax_koopo_stories_get_activity_media_file', [__CLASS__, 'ajax_get_activity_media_file']);
        add_action('wp_ajax_koopo_stories_share_story_activity', [__CLASS__, 'ajax_share_story_to_activity']);
        add_filter('bp_get_activity_content_body', [__CLASS__, 'render_activity_story_card'], 99, 2);

        // BuddyBoss/BuddyPress Activity Hooks
        add_action('bp_activity_entry_meta', [__CLASS__, 'activity_share_button']);
        // Filter for dropdown options (works in many BP themes / BuddyBoss)
        add_filter('bp_activity_get_options', [__CLASS__, 'add_activity_dropdown_item'], 10, 2);
        // BuddyBoss Nouveau "more options" dropdown
        add_filter('bb_nouveau_get_activity_entry_bubble_buttons', [__CLASS__, 'add_activity_bubble_button'], 10, 2);
        // BuddyBoss Nouveau activity entry buttons
        add_filter('bp_nouveau_get_activity_entry_buttons', [__CLASS__, 'add_activity_entry_button'], 10, 2);
    }

    public static function activity_share_button()
    {
        if (!is_user_logged_in())
            return;
        if (function_exists('bp_nouveau_get_activity_entry_buttons') || function_exists('bb_nouveau_get_activity_entry_bubble_buttons')) {
            return;
        }
        if (get_option('koopo_stories_share_enable_activity', '1') !== '1')
            return;

        // Ensure assets are loaded for activity stream
        if (class_exists('Koopo_Stories_Module')) {
            Koopo_Stories_Module::instance()->enqueue_assets();
        }
        wp_enqueue_script('koopo-stories-share');

        // We use data-img="auto" so JS will find the image in the activity entry
        $activity_id = function_exists('bp_get_activity_id') ? (int) bp_get_activity_id() : 0;
        $link = ($activity_id && function_exists('bp_activity_get_permalink')) ? bp_activity_get_permalink($activity_id) : '';

        $label = __('Share to Story', 'koopo');
        $icon = self::get_share_icon_markup($label);

        // Render a simple icon button for the meta area
        printf(
            '<a href="#" class="button koopo-share-to-story koopo-share-icon-btn bp-secondary-action" data-img="auto" data-link="%s" data-title="%s" data-activity-id="%d" title="%s" style="margin-left:5px;" aria-label="%s">%s</a>',
            esc_url($link),
            esc_attr('Shared Activity'),
            (int) $activity_id,
            esc_attr($label),
            esc_attr($label),
            $icon
        );
    }

    public static function add_activity_dropdown_item($options, $activity)
    {
        if (!is_user_logged_in())
            return $options;
        if (get_option('koopo_stories_share_enable_activity', '1') !== '1')
            return $options;

        // Enqueue assets if we are filtering options (likely on activity loop)
        if (class_exists('Koopo_Stories_Module')) {
            Koopo_Stories_Module::instance()->enqueue_assets();
        }
        wp_enqueue_script('koopo-stories-share');

        $activity_id = isset($activity->id) ? (int) $activity->id : 0;
        $link = ($activity_id && function_exists('bp_activity_get_permalink')) ? bp_activity_get_permalink($activity_id, $activity) : '';

        $options[] = [
            'label' => __('Share to Story', 'koopo'),
            'href' => 'javascript:void(0)',
            'class' => 'koopo-share-to-story',
            'attributes' => [
                'data-img' => 'auto',
                'data-link' => $link,
                'data-title' => 'Shared Activity',
                'data-activity-id' => (int) $activity_id,
            ]
        ];

        return $options;
    }

    public static function add_activity_bubble_button($buttons, $activity_id)
    {
        if (!is_user_logged_in())
            return $buttons;
        if (get_option('koopo_stories_share_enable_activity', '1') !== '1')
            return $buttons;
        if (!$activity_id)
            return $buttons;

        if (class_exists('Koopo_Stories_Module')) {
            Koopo_Stories_Module::instance()->enqueue_assets();
        }
        wp_enqueue_script('koopo-stories-share');

        $link = function_exists('bp_activity_get_permalink') ? bp_activity_get_permalink((int) $activity_id) : '';

        $label = __('Share to Story', 'koopo');
        $icon = self::get_share_icon_markup($label);

        $buttons['koopo_share_to_story'] = [
            'id' => 'koopo_share_to_story',
            'position' => 45,
            'component' => 'activity',
            'must_be_logged_in' => true,
            'button_element' => 'button',
            'button_attr' => [
                'type' => 'button',
                'class' => 'button koopo-share-to-story koopo-share-icon-btn bp-secondary-action',
                'title' => $label,
                'aria-label' => $label,
                'data-img' => 'auto',
                'data-link' => $link,
                'data-title' => 'Shared Activity',
                'data-activity-id' => (int) $activity_id,
            ],
            'link_text' => $icon . '<span class="screen-reader-text">' . esc_html($label) . '</span>',
        ];

        return $buttons;
    }

    public static function add_activity_entry_button($buttons, $activity_id)
    {
        if (!is_user_logged_in())
            return $buttons;
        if (get_option('koopo_stories_share_enable_activity', '1') !== '1')
            return $buttons;
        if (!$activity_id)
            return $buttons;

        if (class_exists('Koopo_Stories_Module')) {
            Koopo_Stories_Module::instance()->enqueue_assets();
        }
        wp_enqueue_script('koopo-stories-share');

        $link = function_exists('bp_activity_get_permalink') ? bp_activity_get_permalink((int) $activity_id) : '';

        $label = __('Share to Story', 'koopo');
        $icon = self::get_share_icon_markup($label);

        $buttons['koopo_share_to_story'] = [
            'id' => 'koopo_share_to_story',
            'position' => 45,
            'component' => 'activity',
            'link_text' => $icon . '<span class="screen-reader-text">' . esc_html($label) . '</span>',
            'link_title' => esc_html($label),
            'link_class' => 'button bp-secondary-action koopo-share-to-story koopo-share-icon-btn',
            'link_href' => 'javascript:void(0)',
            'link_rel' => 'nofollow',
            'button_attr' => [
                'aria-label' => $label,
                'data-activity-id' => (int) $activity_id,
                'data-activity-link' => $link,
                'data-img' => 'auto',
                'data-link' => $link,
                'data-title' => 'Shared Activity',
            ],
        ];

        return $buttons;
    }

    public static function register_scripts()
    {
        if (!is_user_logged_in()) {
            return;
        }
        $base_ver = defined('KOOPO_STORIES_VER') ? KOOPO_STORIES_VER : '1.0.0';
        $share_js = KOOPO_STORIES_PATH . 'assets/stories-share.js';
        $ss_js = KOOPO_STORIES_PATH . 'assets/stories-super-socializer.js';
        $share_ver = file_exists($share_js) ? (string) filemtime($share_js) : $base_ver;
        $ss_ver = file_exists($ss_js) ? (string) filemtime($ss_js) : $base_ver;

        wp_register_script(
            'koopo-stories-share',
            plugins_url('assets/stories-share.js', KOOPO_STORIES_PATH . 'koopo-stories.php'),
            ['koopo-stories'], // Depends on main stories script
            $share_ver,
            true
        );
        wp_localize_script('koopo-stories-share', 'KoopoStoriesShare', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('koopo_stories_share'),
        ]);
        wp_register_script(
            'koopo-stories-super-socializer',
            plugins_url('assets/stories-super-socializer.js', KOOPO_STORIES_PATH . 'koopo-stories.php'),
            ['koopo-stories-share'],
            $ss_ver,
            true
        );
    }

    public static function enqueue_super_socializer_integration(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        if (!function_exists('the_champ_social_sharing_enabled')) {
            return;
        }

        $sharing_enabled = the_champ_social_sharing_enabled();
        if (!$sharing_enabled) {
            return;
        }

        $opts = get_option('the_champ_sharing', []);
        $ss_standard_enabled = isset($opts['hor_enable']) && $opts['hor_enable'];
        $ss_floating_enabled = isset($opts['vertical_enable']) && $opts['vertical_enable'];

        $use_standard = get_option('koopo_stories_share_super_socializer_standard', '1') === '1';
        $use_floating = get_option('koopo_stories_share_super_socializer_floating', '1') === '1';

        if ((!$use_standard || !$ss_standard_enabled) && (!$use_floating || !$ss_floating_enabled)) {
            return;
        }

        if (class_exists('Koopo_Stories_Module')) {
            $module = Koopo_Stories_Module::instance();
            // Ensure handles are registered before enqueueing to avoid race conditions on wp_enqueue_scripts.
            $module->register_assets();
            $module->enqueue_assets();
        }

        wp_enqueue_script('koopo-stories-share');
        wp_enqueue_script('koopo-stories-super-socializer');

        $icon_url = self::get_share_icon_url();
        wp_localize_script('koopo-stories-super-socializer', 'KoopoStoriesSuperSocializer', [
            'iconUrl' => $icon_url,
            'enableStandard' => $use_standard && $ss_standard_enabled,
            'enableFloating' => $use_floating && $ss_floating_enabled,
        ]);
    }

    public static function append_share_button($content)
    {
        // Only on singular posts of specific types
        // "post types ... blog post, reel, photo, product, event, place"
        // 'reel' might be a CPT. 'photo' might be CPT. 'product' is WooCommerce.

        if (!is_singular())
            return $content;
        if (!is_user_logged_in())
            return $content; // Only logged in users create stories

        global $post;
        if (!$post)
            return $content;

        // Configuration: which post types allowed?
        // Defaulting to: post, product, gd_event, gd_place, attachment (as requested previously)
        $default_types = ['post', 'product', 'gd_event', 'gd_place', 'attachment'];
        $configured_types = get_option('koopo_stories_share_enabled_types', $default_types);

        // Ensure it is an array
        if (!is_array($configured_types)) {
            $configured_types = $default_types;
        }

        // Apply filter for dev overrides
        $allowed_types = apply_filters('koopo_stories_share_post_types', $configured_types);

        if (!in_array($post->post_type, $allowed_types, true)) {
            return $content;
        }

        // Get featured image
        $img_url = '';
        if (has_post_thumbnail($post)) {
            $img_url = get_the_post_thumbnail_url($post, 'large');
        } elseif ($post->post_type === 'attachment' && wp_attachment_is_image($post)) {
            $img_url = wp_get_attachment_url($post->ID);
        }

        // If no image, maybe don't show button? Or use a placeholder?
        // For now, require image.
        if (!$img_url)
            return $content;

        // Enqueue the script only when button is rendered
        if (class_exists('Koopo_Stories_Module')) {
            Koopo_Stories_Module::instance()->enqueue_assets();
        }
        wp_enqueue_script('koopo-stories-share');

        $button_text = __('Share to Story', 'koopo');
        $icon = self::get_share_icon_markup($button_text);
        $link = get_permalink($post);
        $title = get_the_title($post);

        // Styling: simple button, maybe match theme.
        // We'll add a wrapper class.
        $btn = sprintf(
            '<div class="koopo-share-wrapper" style="margin-top: 20px; margin-bottom: 20px;">
                <button type="button" class="koopo-share-to-story koopo-share-icon-btn button" data-img="%s" data-link="%s" data-title="%s" data-type="%s" aria-label="%s">
                    %s
                </button>
            </div>',
            esc_url($img_url),
            esc_url($link),
            esc_attr($title),
            esc_attr($post->post_type),
            esc_attr($button_text),
            $icon
        );

        // Prepend button to content
        return $btn . $content;
    }

    public static function ajax_get_activity_media(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Unauthorized', 'koopo')], 401);
        }

        check_ajax_referer('koopo_stories_share', 'nonce');

        $activity_id = isset($_POST['activity_id']) ? absint($_POST['activity_id']) : 0;
        if ($activity_id <= 0) {
            wp_send_json_error(['message' => __('Invalid activity id', 'koopo')], 400);
        }

        $activity = class_exists('BP_Activity_Activity') ? new BP_Activity_Activity($activity_id) : null;
        if (empty($activity) || empty($activity->id)) {
            wp_send_json_error(['message' => __('Activity not found', 'koopo')], 404);
        }

        if (function_exists('bp_activity_user_can_read') && !bp_activity_user_can_read($activity, get_current_user_id())) {
            wp_send_json_error(['message' => __('You do not have access to this activity', 'koopo')], 403);
        }

        $media = self::resolve_activity_media((int) $activity->id);
        if (empty($media['url'])) {
            wp_send_json_error(['message' => __('No media found for this activity', 'koopo')], 404);
        }

        wp_send_json_success($media);
    }

    public static function ajax_get_activity_media_file(): void
    {
        if (!is_user_logged_in()) {
            status_header(401);
            exit;
        }

        check_ajax_referer('koopo_stories_share', 'nonce');

        $activity_id = isset($_REQUEST['activity_id']) ? absint($_REQUEST['activity_id']) : 0;
        if ($activity_id <= 0) {
            status_header(400);
            exit;
        }

        $activity = class_exists('BP_Activity_Activity') ? new BP_Activity_Activity($activity_id) : null;
        if (empty($activity) || empty($activity->id)) {
            status_header(404);
            exit;
        }

        if (function_exists('bp_activity_user_can_read') && !bp_activity_user_can_read($activity, get_current_user_id())) {
            status_header(403);
            exit;
        }

        $media = self::resolve_activity_media((int) $activity->id);
        if (empty($media['url'])) {
            status_header(404);
            exit;
        }

        $mime = !empty($media['mime']) ? (string) $media['mime'] : 'application/octet-stream';

        if (!empty($media['attachment_id'])) {
            $path = get_attached_file((int) $media['attachment_id']);
            if ($path && file_exists($path) && is_readable($path)) {
                nocache_headers();
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($path));
                header('X-Koopo-Stories-Proxy: 1');
                readfile($path);
                exit;
            }
        }

        $resp = wp_remote_get($media['url'], ['timeout' => 20]);
        if (is_wp_error($resp)) {
            status_header(502);
            exit;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            status_header($code ?: 502);
            exit;
        }
        $body = wp_remote_retrieve_body($resp);
        if ($body === '') {
            status_header(404);
            exit;
        }
        $resp_mime = (string) wp_remote_retrieve_header($resp, 'content-type');
        if ($resp_mime) {
            $mime = $resp_mime;
        }
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($body));
        header('X-Koopo-Stories-Proxy: 1');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function ajax_share_story_to_activity(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Unauthorized', 'koopo')], 401);
        }

        check_ajax_referer('koopo_stories_share', 'nonce');

        if (!function_exists('bp_activity_post_update') && !function_exists('bp_activity_add')) {
            wp_send_json_error(['message' => __('BuddyBoss activity not available.', 'koopo')], 400);
        }

        $story_id = isset($_POST['story_id']) ? absint($_POST['story_id']) : 0;
        if ($story_id <= 0) {
            wp_send_json_error(['message' => __('Invalid story.', 'koopo')], 400);
        }

        if (class_exists('Koopo_Stories_Permissions') && !Koopo_Stories_Permissions::can_view_story($story_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot share this story.', 'koopo')], 403);
        }

        if (function_exists('bp_activity_user_can_post') && !bp_activity_user_can_post()) {
            wp_send_json_error(['message' => __('Posting to activity is disabled.', 'koopo')], 403);
        }

        // Use provided item_id if valid, otherwise fall back to first item
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if ($item_id > 0) {
            // Verify this item belongs to the story
            $item_story_id = (int) get_post_meta($item_id, 'story_id', true);
            if ($item_story_id !== $story_id) {
                // Item doesn't belong to this story, fall back to first item
                $item_id = self::get_first_story_item_id($story_id);
            }
        } else {
            $item_id = self::get_first_story_item_id($story_id);
        }
        $activity_link = self::get_activity_story_link($story_id, $item_id);
        $author_id = (int) get_post_field('post_author', $story_id);
        $author_name = $author_id ? (get_userdata($author_id)->display_name ?? '') : '';

        // Get cover media (works for both images and videos)
        $cover_media = $item_id ? Koopo_Stories_Utils::get_story_cover_media($item_id, 'medium') : ['url' => '', 'type' => 'image', 'thumb' => ''];
        $media_type = $cover_media['type'];
        $thumb = $cover_media['thumb'];
        $media_url = $cover_media['url'];

        $item_payload = $item_id ? Koopo_Stories_Utils::build_story_item_payload($item_id, false) : null;
        $stickers = ($item_id && class_exists('Koopo_Stories_Stickers')) ? Koopo_Stories_Stickers::get_stickers($item_id) : [];
        $stickers_json = $stickers ? wp_json_encode($stickers) : '';
        $attachment_id = $item_id ? (int) get_post_meta($item_id, 'attachment_id', true) : 0;
        $media_html = '';
        if (is_array($item_payload) && !empty($item_payload['src'])) {
            $media_src = esc_url($item_payload['src']);
            if ($media_type === 'video') {
                $poster = $thumb ? ' poster="' . esc_url($thumb) . '"' : '';
                $media_tag = '<video controls playsinline muted preload="metadata"' . $poster . '><source src="' . $media_src . '" /></video>';
            } else {
                $media_tag = '<img src="' . $media_src . '" alt="" />';
            }
            $media_html = '<div class="koopo-story-share-media" data-stickers="' . esc_attr($stickers_json) . '" data-media-type="' . esc_attr($media_type) . '">'
                . '<a href="' . esc_url($activity_link) . '" class="koopo-story-open" data-koopo-story="' . esc_attr((string) $story_id) . '" data-koopo-item="' . esc_attr((string) $item_id) . '">'
                . $media_tag
                . '</a></div>';
        } elseif ($media_url) {
            // Fallback: use full media URL (covers videos without thumbnails)
            if ($media_type === 'video') {
                $media_tag = '<video controls playsinline muted preload="metadata"><source src="' . esc_url($media_url) . '" /></video>';
            } else {
                $media_tag = '<img src="' . esc_url($media_url) . '" alt="" />';
            }
            $media_html = '<div class="koopo-story-share-media" data-stickers="' . esc_attr($stickers_json) . '" data-media-type="' . esc_attr($media_type) . '">'
                . '<a href="' . esc_url($activity_link) . '" class="koopo-story-open" data-koopo-story="' . esc_attr((string) $story_id) . '" data-koopo-item="' . esc_attr((string) $item_id) . '">'
                . $media_tag
                . '</a></div>';
        }
        $content = self::ensure_shared_content(esc_html__('Shared a story', 'koopo'));
        $privacy = get_post_meta($story_id, 'privacy', true);
        if (function_exists('bp_activity_get_visibility_levels')) {
            $levels = bp_activity_get_visibility_levels();
            if (is_array($levels) && isset($levels[$privacy])) {
                // ok
            } else {
                $privacy = 'public';
            }
        } else {
            $privacy = 'public';
        }

        $args = [
            'content'   => $content,
            'component' => 'activity',
            'type'      => 'activity_update',
            'user_id'   => get_current_user_id(),
            'privacy'   => $privacy,
            'status'    => function_exists('bb_get_activity_published_status') ? bb_get_activity_published_status() : 'published',
            'error_type'=> 'wp_error',
        ];

        $activity_id = function_exists('bp_activity_post_update')
            ? bp_activity_post_update($args)
            : bp_activity_add($args);

        if (is_wp_error($activity_id) || !$activity_id) {
            $msg = is_wp_error($activity_id) ? $activity_id->get_error_message() : __('Failed to share story to activity.', 'koopo');
            wp_send_json_error(['message' => $msg], 400);
        }

        if (function_exists('bp_activity_update_meta')) {
            bp_activity_update_meta((int) $activity_id, 'koopo_story_id', $story_id);
            bp_activity_update_meta((int) $activity_id, 'koopo_story_link', $activity_link);
            bp_activity_update_meta((int) $activity_id, 'koopo_story_item_id', $item_id);
            bp_activity_update_meta((int) $activity_id, 'koopo_story_media_type', $media_type);
            if ($author_name) {
                bp_activity_update_meta((int) $activity_id, 'koopo_story_author', $author_name);
            }
            if (!empty($media_html)) {
                bp_activity_update_meta((int) $activity_id, 'koopo_story_has_media', 1);
            }

            // Determine snapshot: for images use thumbnail, for videos use full URL
            $snapshot = '';
            if ($media_type === 'video') {
                // For videos, use the full video URL (browser will show first frame)
                $snapshot = $media_url;
            } else {
                // For images, prefer thumbnail, fallback to full URL
                if ($thumb) {
                    $snapshot = $thumb;
                } elseif ($attachment_id) {
                    $img = wp_get_attachment_image_url($attachment_id, 'medium');
                    if (is_string($img) && $img !== '') {
                        $snapshot = $img;
                    }
                }
                if (!$snapshot && $media_url) {
                    $snapshot = $media_url;
                }
            }

            if ($snapshot) {
                bp_activity_update_meta((int) $activity_id, 'koopo_story_snapshot', $snapshot);
            }
            if ($thumb) {
                bp_activity_update_meta((int) $activity_id, 'koopo_story_thumb', $thumb);
            }
        }

        wp_send_json_success([
            'activity_id' => (int) $activity_id,
            'link' => $activity_link,
        ]);
    }

    public static function render_activity_story_card($content, $activity) {
        if (!is_object($activity) || empty($activity->id)) {
            return $content;
        }
        if (strpos((string) $content, 'koopo-story-share-card') !== false) {
            return $content;
        }
        $story_id = function_exists('bp_activity_get_meta') ? (int) bp_activity_get_meta($activity->id, 'koopo_story_id', true) : 0;
        if ($story_id <= 0) {
            return $content;
        }
        $link = function_exists('bp_activity_get_meta') ? (string) bp_activity_get_meta($activity->id, 'koopo_story_link', true) : '';
        $item_id = function_exists('bp_activity_get_meta') ? (int) bp_activity_get_meta($activity->id, 'koopo_story_item_id', true) : 0;
        $author_name = function_exists('bp_activity_get_meta') ? (string) bp_activity_get_meta($activity->id, 'koopo_story_author', true) : '';
        $snapshot = function_exists('bp_activity_get_meta') ? (string) bp_activity_get_meta($activity->id, 'koopo_story_snapshot', true) : '';
        $thumb = function_exists('bp_activity_get_meta') ? (string) bp_activity_get_meta($activity->id, 'koopo_story_thumb', true) : '';
        $media_type = function_exists('bp_activity_get_meta') ? (string) bp_activity_get_meta($activity->id, 'koopo_story_media_type', true) : '';

        if (!$item_id && $story_id) {
            $item_id = self::get_first_story_item_id($story_id);
        }

        // If media type not stored, try to determine from item
        if (!$media_type && $item_id) {
            $cover_media = Koopo_Stories_Utils::get_story_cover_media($item_id, 'medium');
            $media_type = $cover_media['type'];
            if (!$snapshot && $cover_media['url']) {
                $snapshot = $cover_media['url'];
            }
            if (!$thumb && $cover_media['thumb']) {
                $thumb = $cover_media['thumb'];
            }
        }

        // Fallback snapshot logic for legacy posts
        if (!$snapshot) {
            $attachment_id = $item_id ? (int) get_post_meta($item_id, 'attachment_id', true) : 0;
            if ($attachment_id) {
                // For images, try to get thumbnail
                if ($media_type !== 'video') {
                    $img = wp_get_attachment_image_url($attachment_id, 'medium');
                    if (is_string($img) && $img !== '') {
                        $snapshot = $img;
                    }
                }
                // For videos or if thumbnail failed, use full URL
                if (!$snapshot) {
                    $full_url = wp_get_attachment_url($attachment_id);
                    if (is_string($full_url) && $full_url !== '') {
                        $snapshot = $full_url;
                    }
                }
            }
            if (!$snapshot) {
                $item_payload = ($item_id && class_exists('Koopo_Stories_Utils')) ? Koopo_Stories_Utils::build_story_item_payload($item_id, false) : null;
                if (is_array($item_payload) && !empty($item_payload['src'])) {
                    $snapshot = $item_payload['src'];
                    if (!$media_type) {
                        $media_type = $item_payload['type'] ?? 'image';
                    }
                }
            }
        }

        if (!$thumb && $item_id && $media_type !== 'video') {
            $thumb = Koopo_Stories_Utils::get_story_cover_thumb($item_id, 'medium');
        }

        if (!$link) {
            $link = self::get_activity_story_link($story_id, $item_id);
        }

        // Build media HTML based on type
        $media = '';
        $media_src = $snapshot ?: $thumb;
        if ($media_src) {
            if ($media_type === 'video') {
                // Render video element - browser will show first frame as preview
                $poster_attr = $thumb ? ' poster="' . esc_url($thumb) . '"' : '';
                $media_tag = '<video controls playsinline muted preload="metadata"' . $poster_attr . '><source src="' . esc_url($media_src) . '" /></video>';
            } else {
                // Render image element
                $media_tag = '<img src="' . esc_url($media_src) . '" alt="" />';
            }
            $media = '<div class="koopo-story-share-media" data-media-type="' . esc_attr($media_type ?: 'image') . '">'
                . '<a href="' . esc_url($link) . '" class="koopo-story-open" data-koopo-story="' . esc_attr((string) $story_id) . '" data-koopo-item="' . esc_attr((string) $item_id) . '">'
                . $media_tag
                . '</a></div>';
        }

        $title = esc_html__('Shared a story', 'koopo') . ($author_name ? ' ' . esc_html__('by', 'koopo') . ' ' . esc_html($author_name) : '');
        $card = '<div class="koopo-story-share-card">'
            . '<div class="koopo-story-share-meta">'
            . '<div class="koopo-story-share-title">' . $title . '</div>'
            . '<div class="koopo-story-share-link"><a href="' . esc_url($link) . '" class="koopo-story-open" data-koopo-story="' . esc_attr((string) $story_id) . '" data-koopo-item="' . esc_attr((string) $item_id) . '">' . esc_html__('View story', 'koopo') . '</a></div>'
            . '</div>'
            . $media
            . '</div>';

        return $content . $card;
    }

    private static function ensure_shared_content(string $content): string
    {
        $trim = trim(wp_strip_all_tags($content));
        return $trim === '' ? esc_html__('Shared a story', 'koopo') : $content;
    }

    private static function get_story_cover_thumb(int $story_id): string
    {
        if ($story_id <= 0) return '';
        $item_id = self::get_first_story_item_id($story_id);
        if ($item_id <= 0) return '';
        // Use the new cover media function which handles videos
        $cover = Koopo_Stories_Utils::get_story_cover_media($item_id, 'medium');
        // Return thumb if available, otherwise return full URL for videos
        return $cover['thumb'] ?: $cover['url'];
    }

    private static function get_first_story_item_id(int $story_id): int
    {
        if ($story_id <= 0) {
            return 0;
        }
        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => 'story_id',
                    'value' => (string) $story_id,
                    'compare' => '=',
                ],
            ],
        ]);
        return !empty($items[0]) ? (int) $items[0] : 0;
    }

    private static function get_activity_story_link(int $story_id, int $item_id = 0): string
    {
        $base = '';
        if (function_exists('bp_get_activity_directory_permalink')) {
            $base = bp_get_activity_directory_permalink();
        } elseif (function_exists('bp_get_activity_root_slug')) {
            $slug = trim((string) bp_get_activity_root_slug(), '/');
            if ($slug !== '') {
                $base = home_url('/' . $slug . '/');
            }
        }
        if (!$base) {
            $base = home_url('/activity/');
        }
        $base = trailingslashit($base);
        $args = [
            'koopo_story' => (string) $story_id,
        ];
        if ($item_id > 0) {
            $args['koopo_story_item'] = (string) $item_id;
        }
        return add_query_arg($args, $base);
    }

    private static function resolve_activity_media(int $activity_id): array
    {
        $attachment_id = 0;
        $url = '';
        $kind = '';
        $mime = '';

        if (class_exists('BP_Media')) {
            $attachment_id = (int) BP_Media::get_activity_attachment_id($activity_id);
        }

        if (!$attachment_id && class_exists('BP_Video')) {
            $attachment_id = (int) BP_Video::get_activity_attachment_id($activity_id);
        }

        if (!$attachment_id) {
            $media_ids = bp_activity_get_meta($activity_id, 'bp_media_ids', true);
            if (empty($media_ids)) {
                $media_ids = bp_activity_get_meta($activity_id, 'bp_media_id', true);
            }
            $media_ids = self::normalize_ids($media_ids);
            if (!empty($media_ids) && function_exists('bp_media_get_specific')) {
                $media_data = bp_media_get_specific([
                    'media_ids' => array_slice($media_ids, 0, 1),
                    'per_page' => 1,
                ]);
                if (!empty($media_data['medias'][0]->attachment_id)) {
                    $attachment_id = (int) $media_data['medias'][0]->attachment_id;
                }
            }
        }

        if (!$attachment_id) {
            $video_ids = bp_activity_get_meta($activity_id, 'bp_video_ids', true);
            if (empty($video_ids)) {
                $video_ids = bp_activity_get_meta($activity_id, 'bp_video_id', true);
            }
            if (empty($video_ids) && class_exists('BP_Video')) {
                $video_ids = BP_Video::get_activity_video_id($activity_id);
            }
            $video_ids = self::normalize_ids($video_ids);
            if (!empty($video_ids) && function_exists('bp_video_get_specific')) {
                $video_data = bp_video_get_specific([
                    'video_ids' => array_slice($video_ids, 0, 1),
                    'per_page' => 1,
                ]);
                if (!empty($video_data['videos'][0]->attachment_id)) {
                    $attachment_id = (int) $video_data['videos'][0]->attachment_id;
                }
            }
        }

        if ($attachment_id > 0) {
            $url = wp_get_attachment_url($attachment_id);
            $mime = (string) get_post_mime_type($attachment_id);
            if (strpos($mime, 'video/') === 0) {
                $kind = 'video';
            } elseif (strpos($mime, 'image/') === 0) {
                $kind = 'image';
            }
        }

        if (!$url) {
            $activity = class_exists('BP_Activity_Activity') ? new BP_Activity_Activity($activity_id) : null;
            $content = $activity && !empty($activity->content) ? (string) $activity->content : '';
            if ($content && preg_match('/<(img|video|source)[^>]+src=[\"\']([^\"\']+)[\"\']/i', $content, $match)) {
                $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
                $kind = (strtolower($match[1]) === 'img') ? 'image' : 'video';
            }
        }

        if (!$kind && $url) {
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            $kind = in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv'], true) ? 'video' : 'image';
        }

        return [
            'url' => $url ? esc_url_raw($url) : '',
            'kind' => $kind ?: '',
            'mime' => $mime,
            'attachment_id' => (int) $attachment_id,
            'activity_id' => $activity_id,
            'permalink' => function_exists('bp_activity_get_permalink') ? bp_activity_get_permalink($activity_id) : '',
        ];
    }

    private static function normalize_ids($ids): array
    {
        if (is_string($ids)) {
            return array_values(array_filter(array_map('absint', explode(',', $ids))));
        }
        if (is_numeric($ids)) {
            return [absint($ids)];
        }
        if (is_array($ids)) {
            return array_values(array_filter(array_map('absint', $ids)));
        }
        return [];
    }
}
