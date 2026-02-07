<?php
if (!defined('ABSPATH'))
    exit;

class Koopo_Stories_Profile
{

    public static function init()
    {
        add_action('bp_setup_nav', [__CLASS__, 'setup_nav'], 50);
        add_action('bp_setup_nav', [__CLASS__, 'setup_settings_nav'], 60); // Changed from bp_setup_options_nav
    }

    public static function setup_nav()
    {
        if (!is_user_logged_in())
            return;

        // Privacy: Only show "Stories" tab (Archive) to the profile owner (and admins)
        if (!bp_is_my_profile() && !current_user_can('manage_options')) {
            return;
        }

        // Add 'Stories' to the main profile navigation
        bp_core_new_nav_item([
            'name' => __('Stories', 'koopo'),
            'slug' => 'stories',
            'position' => 80,
            'screen_function' => [__CLASS__, 'screen_archive'],
            'default_subnav_slug' => 'archive',
            'item_css_id' => 'koopo-stories-profile-nav'
        ]);

        // Add subnav - Archive (Default)
        bp_core_new_subnav_item([
            'name' => __('Archive', 'koopo'),
            'slug' => 'archive',
            'parent_url' => bp_loggedin_user_domain() . 'stories/',
            'parent_slug' => 'stories',
            'screen_function' => [__CLASS__, 'screen_archive'],
            'position' => 10,
        ]);
    }

    public static function setup_settings_nav()
    {
        if (!is_user_logged_in())
            return;

        // Only valid for own profile
        if (!bp_is_my_profile())
            return;

        // Ensure we are using the correct parent slug for Settings
        $settings_slug = function_exists('bp_get_settings_slug') ? bp_get_settings_slug() : 'settings';

        // Add "Stories" sub-nav to the main "Settings" tab
        $args = [
            'name' => __('Stories', 'koopo'), // Changed from "Stories Settings" to "Stories" to match typical subnav style
            'slug' => 'stories',
            'parent_url' => bp_loggedin_user_domain() . $settings_slug . '/',
            'parent_slug' => $settings_slug,
            'screen_function' => [__CLASS__, 'screen_settings'],
            'position' => 40,
            'user_has_access' => bp_is_my_profile()
        ];
        bp_core_new_subnav_item($args);
    }

    /**
     * Screen function for Stories > Archive
     */
    public static function screen_archive()
    {
        add_action('bp_template_content', [__CLASS__, 'content_archive']);
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
    }

    /**
     * Content callback for Stories > Archive
     */
    public static function content_archive()
    {
        if (!is_user_logged_in())
            return;

        // Double check privacy
        if (!bp_is_my_profile() && !current_user_can('manage_options')) {
            echo '<div class="bp-feedback info"><span class="bp-icon" aria-hidden="true"></span><p>' . __('You can only view your own story archive.', 'koopo') . '</p></div>';
            return;
        }

        echo '<h4>' . __('Your Story Archive', 'koopo') . '</h4>';
        echo '<p>' . __('Stories disappear from your profile after 24 hours, but you can see them here.', 'koopo') . '</p>';

        // Render existing archive shortcode
        echo do_shortcode('[koopo_stories_archive]');
    }

    /**
     * Screen function for Settings > Stories
     */
    public static function screen_settings()
    {
        add_action('bp_template_content', [__CLASS__, 'content_settings']);
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
    }

    /**
     * Content callback for Settings > Stories
     */
    public static function content_settings()
    {
        if (!is_user_logged_in() || !bp_is_my_profile())
            return;

        echo '<h4>' . __('Stories Settings', 'koopo') . '</h4>';

        // Close Friends Manager (inline)
        if ( class_exists('Koopo_Stories_Close_Friends_UI') ) {
            echo '<div style="margin-top:20px;">';
            echo '<h5>' . __('Close Friends List', 'koopo') . '</h5>';
            echo '<p>' . __('Manage who is in your close friends list.', 'koopo') . '</p>';
            echo Koopo_Stories_Close_Friends_UI::shortcode_manager();
            echo '</div>';
        }

        // Hide All Stories Manager
        if ( class_exists('Koopo_Stories_Close_Friends_UI') ) {
            echo '<div style="margin-top:20px;">';
            echo do_shortcode('[koopo_hide_all_manager]');
            echo '</div>';
        }

        // Link to Archive
        $archive_link = bp_loggedin_user_domain() . 'stories/archive/';
        echo '<div style="margin-bottom:20px; padding:15px; background:#f9f9f9; border-radius:5px;">';
        echo '<strong>' . __('Story Archive', 'koopo') . '</strong><br>';
        echo __('View your past stories affecting your profile.', 'koopo') . ' <a href="' . esc_url($archive_link) . '" class="button small">' . __('View Archive', 'koopo') . '</a>';
        echo '</div>';

        
    }
}
