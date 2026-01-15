<?php
/**
 * Koopo Stories Module (Bootstrap)
 */
if ( ! defined('ABSPATH') ) exit;

final class Koopo_Stories_Module {

    const OPTION_ENABLE = 'koopo_enable_stories';
    const CPT_STORY = 'koopo_story';
    const CPT_ITEM  = 'koopo_story_item';
    const VIEWS_TABLE = 'koopo_story_views';
    const REST_NS = 'koopo/v1';

    private static $instance = null;
    private $assets_enqueued = false;

    public static function instance() : self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){}

    public function init() : void {
        $enabled = (get_option(self::OPTION_ENABLE, '1') === '1');
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-cpt.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-views-table.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-close-friends.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-close-friends-ui.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-utils.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-notifications.php';
        require_once KOOPO_STORIES_PATH . 'includes/engagement/class-stories-reactions.php';
        require_once KOOPO_STORIES_PATH . 'includes/engagement/class-stories-replies.php';
        require_once KOOPO_STORIES_PATH . 'includes/engagement/class-stories-reports.php';
        require_once KOOPO_STORIES_PATH . 'includes/engagement/class-stories-stickers.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-permissions.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/rest/class-stories-rest.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/rest/class-stories-rest-feed.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/rest/class-stories-rest-story.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/rest/class-stories-rest-engagement.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/rest/class-stories-rest-stickers.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-cleanup.php';
        require_once KOOPO_STORIES_PATH . 'includes/stories/class-stories-widget.php';
        require_once KOOPO_STORIES_PATH . 'includes/admin/class-stories-admin.php';

        // Admin UI (menu + settings)
        if ( is_admin() ) {
            Koopo_Stories_Admin::init();
        }

        Koopo_Stories_Notifications::init();
        add_action('bp_init', [ 'Koopo_Stories_Notifications', 'init' ], 20);

        // CPTs + meta
        add_action('init', [ 'Koopo_Stories_CPT', 'register' ]);

        // REST (only when enabled)
        if ( $enabled ) {
            add_action('rest_api_init', [ 'Koopo_Stories_REST', 'register_routes' ]);
        }

        // Note: Database table installation is handled by the activation hook in koopo-stories.php
        // to ensure proper loading order and avoid issues with nested activation hooks

        // Ensure database tables exist (fallback for existing installations)
        if ( method_exists( $this, 'maybe_create_tables' ) ) {
            $this->maybe_create_tables();
        }

        // Cron cleanup (only when enabled)
        if ( $enabled ) {
            add_action('koopo_stories_cleanup', [ 'Koopo_Stories_Cleanup', 'run' ]);
            add_action('wp', [ $this, 'maybe_schedule_cleanup' ]);
        }

        // BuddyBoss activity tray (only when enabled)
        if ( $enabled ) {
            add_action('bp_after_activity_post_form', [ $this, 'render_activity_tray' ]);
        }

        // Widget + shortcode
        add_action('widgets_init', [ 'Koopo_Stories_Widget', 'register' ]);
        add_shortcode('koopo_stories_widget', [ $this, 'shortcode_widget' ]);
        add_shortcode('koopo_stories_archive', [ $this, 'shortcode_archive' ]);

        // Close Friends UI
        Koopo_Stories_Close_Friends_UI::init();

        // Assets registration
        add_action('wp_enqueue_scripts', [ $this, 'register_assets' ]);
    }

    public function maybe_create_tables() : void {
        global $wpdb;

        // Check if stickers table exists
        $stickers_table = $wpdb->prefix . 'koopo_story_stickers';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stickers_table}'") === $stickers_table;

        if ( ! $table_exists ) {
            // Tables don't exist, create them
            Koopo_Stories_Views_Table::install();
            Koopo_Stories_Close_Friends::install();
            Koopo_Stories_Reactions::install();
            Koopo_Stories_Replies::install();
            Koopo_Stories_Reports::install();
            Koopo_Stories_Stickers::install();
            Koopo_Stories_Stickers::install_poll_votes_table();
        }
    }

    public function maybe_schedule_cleanup() : void {
        if ( ! wp_next_scheduled('koopo_stories_cleanup') ) {
            wp_schedule_event( time() + 300, 'hourly', 'koopo_stories_cleanup' );
        }
    }

    public function register_assets() : void {
        $ver = defined('KOOPO_STORIES_VER') ? KOOPO_STORIES_VER : '1.0.0';
        $css_path = KOOPO_STORIES_PATH . 'assets/stories.css';
        $js_path = KOOPO_STORIES_PATH . 'assets/stories.js';
        $viewer_js_path = KOOPO_STORIES_PATH . 'assets/stories-viewer.js';
        $composer_js_path = KOOPO_STORIES_PATH . 'assets/stories-composer.js';
        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : $ver;
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : $ver;
        $viewer_js_ver = file_exists($viewer_js_path) ? (string) filemtime($viewer_js_path) : $ver;
        $composer_js_ver = file_exists($composer_js_path) ? (string) filemtime($composer_js_path) : $ver;
        wp_register_style(
            'koopo-stories',
            plugins_url('assets/stories.css', KOOPO_STORIES_PATH . 'koopo-stories.php'),
            [],
            $css_ver
        );
        wp_register_script(
            'koopo-stories',
            plugins_url('assets/stories.js', KOOPO_STORIES_PATH . 'koopo-stories.php'),
            [],
            $js_ver,
            true
        );
        if ( ! defined('KOOPO_STORIES_VIEWER_JS') ) {
            define('KOOPO_STORIES_VIEWER_JS', add_query_arg('ver', $viewer_js_ver, plugins_url('assets/stories-viewer.js', KOOPO_STORIES_PATH . 'koopo-stories.php')));
        }
        if ( ! defined('KOOPO_STORIES_COMPOSER_JS') ) {
            define('KOOPO_STORIES_COMPOSER_JS', add_query_arg('ver', $composer_js_ver, plugins_url('assets/stories-composer.js', KOOPO_STORIES_PATH . 'koopo-stories.php')));
        }
    }

    public function enqueue_assets() : void {
        if ( $this->assets_enqueued ) return;
        $this->assets_enqueued = true;

        wp_enqueue_style('koopo-stories');
        wp_enqueue_script('koopo-stories');

        $current_user_id = get_current_user_id();
        wp_localize_script('koopo-stories', 'KoopoStories', [
            'restUrl' => esc_url_raw( rest_url( self::REST_NS . '/stories' ) ),
            'nonce'   => wp_create_nonce('wp_rest'),
            'me'      => $current_user_id,
            'meAvatar' => get_avatar_url($current_user_id, [ 'size' => 96 ]),
            'viewerSrc' => defined('KOOPO_STORIES_VIEWER_JS') ? KOOPO_STORIES_VIEWER_JS : '',
            'composerSrc' => defined('KOOPO_STORIES_COMPOSER_JS') ? KOOPO_STORIES_COMPOSER_JS : '',
        ]);

        wp_localize_script('koopo-stories', 'KoopoStoriesI18n', [
            'archive_empty' => __('Archive empty', 'koopo'),
            'archive_load_failed' => __('Failed to load archived stories', 'koopo'),
            'story_settings' => __('Story settings', 'koopo'),
            'privacy_label' => __('Privacy', 'koopo'),
            'close' => __('Close', 'koopo'),
            'save' => __('Save', 'koopo'),
            'hide_users_title' => __('Hide from specific users', 'koopo'),
            'search_username' => __('Search by username', 'koopo'),
            'add_hidden' => __('Add to hidden list', 'koopo'),
            'no_hidden_users' => __('No hidden users yet.', 'koopo'),
            'remove' => __('Remove', 'koopo'),
            'remove_hidden_failed' => __('Failed to remove user. Please try again.', 'koopo'),
            'select_user_hide' => __('Select a user to hide.', 'koopo'),
            'hide_user_failed' => __('Failed to hide user. Please try again.', 'koopo'),
            'delete_story' => __('Delete story', 'koopo'),
            'archive_story' => __('Archive story', 'koopo'),
            'unarchive_story' => __('Unarchive story', 'koopo'),
        ]);
    }

    public function render_activity_tray() : void {
        if ( ! is_user_logged_in() ) return;
        $this->enqueue_assets();
        echo '<div class="koopo-stories koopo-stories--tray" data-scope="friends" data-limit="20"></div>';
    }

    public function shortcode_widget( $atts = [] ) : string {
        if ( ! is_user_logged_in() ) return '';
        if ( get_option(self::OPTION_ENABLE, '1') !== '1' ) return '';

        $atts = shortcode_atts([
            'title' => '',
            'limit' => intval(get_option('koopo_stories_default_limit', 10)),
            'scope' => get_option('koopo_stories_default_scope', 'friends'), // friends|following|all
            'layout' => get_option('koopo_stories_default_layout', 'horizontal'), // horizontal|vertical
            'exclude_me' => get_option('koopo_stories_default_exclude_me', '0'),
            'order' => get_option('koopo_stories_default_order', 'unseen_first'), // unseen_first|recent_activity
            'show_uploader' => get_option('koopo_stories_default_show_uploader', '1'),
            'show_unseen_badge' => get_option('koopo_stories_default_show_unseen_badge', '1'),
        ], $atts, 'koopo_stories_widget');

        $limit = max(1, min(50, intval($atts['limit'])));
        $scope = in_array($atts['scope'], ['friends','following','all'], true) ? $atts['scope'] : 'friends';
        $layout = in_array($atts['layout'], ['horizontal','vertical'], true) ? $atts['layout'] : 'horizontal';
        $exclude_me = ($atts['exclude_me'] === '1' || $atts['exclude_me'] === 'true');
        $order = in_array($atts['order'], ['unseen_first','recent_activity'], true) ? $atts['order'] : 'unseen_first';

        $show_uploader = ($atts['show_uploader'] === '1' || $atts['show_uploader'] === 'true');
        $show_badge = ($atts['show_unseen_badge'] === '1' || $atts['show_unseen_badge'] === 'true');

        $classes = 'koopo-stories';
        if ( $layout === 'vertical' ) $classes .= ' koopo-stories--vertical';

        $this->enqueue_assets();

        ob_start();
        if ( ! empty($atts['title']) ) {
            echo '<h3 class="koopo-stories__title">' . esc_html($atts['title']) . '</h3>';
        }
        printf(
            '<div class="%s" data-limit="%d" data-scope="%s" data-layout="%s" data-exclude-me="%s" data-order="%s" data-show-uploader="%s" data-show-unseen-badge="%s"><div class="koopo-stories__loader"><div class="koopo-stories__spinner"></div></div></div>',
            esc_attr($classes),
            esc_attr($limit),
            esc_attr($scope),
            esc_attr($layout),
            esc_attr($exclude_me ? '1' : '0'),
            esc_attr($order),
            esc_attr($show_uploader ? '1' : '0'),
            esc_attr($show_badge ? '1' : '0')
        );
        return ob_get_clean();
    }

    public function shortcode_archive( $atts = [] ) : string {
        if ( ! is_user_logged_in() ) return '';
        if ( get_option(self::OPTION_ENABLE, '1') !== '1' ) return '';

        $atts = shortcode_atts([
            'title' => '',
            'limit' => 20,
            'layout' => get_option('koopo_stories_default_layout', 'horizontal'),
        ], $atts, 'koopo_stories_archive');

        $limit = max(1, min(50, intval($atts['limit'])));
        $layout = in_array($atts['layout'], ['horizontal','vertical'], true) ? $atts['layout'] : 'horizontal';

        $classes = 'koopo-stories';
        if ( $layout === 'vertical' ) $classes .= ' koopo-stories--vertical';
        $classes .= ' koopo-stories--archive';

        $this->enqueue_assets();

        ob_start();
        if ( ! empty($atts['title']) ) {
            echo '<h3 class="koopo-stories__title">' . esc_html($atts['title']) . '</h3>';
        }
        printf(
            '<div class="%s" data-archive="1" data-limit="%d" data-layout="%s"><div class="koopo-stories__loader"><div class="koopo-stories__spinner"></div></div></div>',
            esc_attr($classes),
            esc_attr($limit),
            esc_attr($layout)
        );
        return ob_get_clean();
    }

}
