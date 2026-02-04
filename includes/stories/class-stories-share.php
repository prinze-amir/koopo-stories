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
            '<a href="#" class="button koopo-share-to-story koopo-share-icon-btn bp-secondary-action" data-img="auto" data-link="%s" data-title="%s" title="%s" style="margin-left:5px;" aria-label="%s">%s</a>',
            esc_url($link),
            esc_attr('Shared Activity'),
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
}
