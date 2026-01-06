<?php
/**
 * Plugin Name: Koopo Stories
 * Plugin URI: http://www.docs.koopoonline.com/
 * Description: Custom blocks and shortcodes for advance features.
 * Version: 2.0
 * Author: Plu2oprinze
 * Author URI: http://www.koopoonline.com
 */

define( 'KOOPO_STORIES_PATH', plugin_dir_path( __FILE__ ) );


// Ensure Stories are enabled by default on fresh installs (can be disabled in Stories Settings).
register_activation_hook(__FILE__, function () {
    if ( get_option('koopo_enable_stories', null) === null ) {
        add_option('koopo_enable_stories', '1');
    }

    // Install database tables for Stories feature
    // Load the module first to ensure all dependencies are available
    if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-module.php' ) ) {
        require_once KOOPO_STORIES_PATH . 'includes/class-stories-module.php';

        // Now load and install each table class
        if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-views-table.php' ) ) {
            require_once KOOPO_STORIES_PATH . 'includes/class-stories-views-table.php';
            Koopo_Stories_Views_Table::install();
        }
        if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-close-friends.php' ) ) {
            require_once KOOPO_STORIES_PATH . 'includes/class-stories-close-friends.php';
            Koopo_Stories_Close_Friends::install();
        }
        if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-reactions.php' ) ) {
            require_once KOOPO_STORIES_PATH . 'includes/class-stories-reactions.php';
            Koopo_Stories_Reactions::install();
        }
        if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-replies.php' ) ) {
            require_once KOOPO_STORIES_PATH . 'includes/class-stories-replies.php';
            Koopo_Stories_Replies::install();
        }
        if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-reports.php' ) ) {
            require_once KOOPO_STORIES_PATH . 'includes/class-stories-reports.php';
            Koopo_Stories_Reports::install();
        }
        if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-stickers.php' ) ) {
            require_once KOOPO_STORIES_PATH . 'includes/class-stories-stickers.php';
            Koopo_Stories_Stickers::install();
            Koopo_Stories_Stickers::install_poll_votes_table();
        }
    }
});


add_action( 'plugins_loaded', 'koopo_stories_load_textdomain' );

function koopo_stories_load_textdomain() {
	load_plugin_textdomain( 'koopo-stories', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

// Stories (BuddyBoss) — bootstrap on plugins_loaded so BuddyBoss/BuddyPress APIs are available.
add_action( 'plugins_loaded', function () {
    // Define Stories version constant
    if ( ! defined('KOOPO_STORIES_VER') ) {
        define('KOOPO_STORIES_VER', '1.0.0');
    }

    // Load Stories module directly
    if ( file_exists( KOOPO_STORIES_PATH . 'includes/class-stories-module.php' ) ) {
        require_once KOOPO_STORIES_PATH . 'includes/class-stories-module.php';

        // Initialize the Stories module
        if ( class_exists( 'Koopo_Stories_Module' ) ) {
            Koopo_Stories_Module::instance()->init();
        }
    }
}, 20 );
