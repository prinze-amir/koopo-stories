<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_Notifications {
    private static $initialized = false;

    public static function init() : void {
        if ( self::$initialized ) return;
        if ( ! function_exists('bp_notifications_add_notification') ) return;
        self::$initialized = true;
        add_filter('bp_notifications_get_notifications_for_user', [__CLASS__, 'format_notifications'], 10, 9);
        add_filter('bb_notifications_get_notifications_for_user', [__CLASS__, 'format_notifications'], 10, 9);
        add_filter('bp_notifications_get_notification_description', [__CLASS__, 'format_notification_description'], 10, 2);
        add_filter('bb_notifications_get_notification_description', [__CLASS__, 'format_notification_description'], 10, 2);
        add_filter('bp_notifications_get_notification_string', [__CLASS__, 'format_notification_string'], 10, 9);
        add_filter('bb_notifications_get_notification_string', [__CLASS__, 'format_notification_string'], 10, 9);
        add_filter('bb_notifications_get_component_notification', [__CLASS__, 'format_component_notification'], 10, 9);
        add_filter('bp_notifications_get_registered_components', [__CLASS__, 'register_component']);
        add_filter('bb_notifications_get_registered_components', [__CLASS__, 'register_component']);
        add_action('bp_setup_globals', [__CLASS__, 'register_component_object'], 20);
    }

    public static function register_component( $components ) {
        if ( empty($components) || ! is_array($components) ) {
            $components = [];
        }
        if ( ! in_array('stories', $components, true) ) {
            $components[] = 'stories';
        }
        return $components;
    }

    public static function format_notifications($content, ...$args) {
        // BuddyPress signature: ($content, $user_id, $format, $action, $component, $notification_id, $item_id, $secondary_item_id, $total_items)
        // BuddyBoss signature: ($content, $action, $item_id, $secondary_item_id, $total_items, $format, $action_name, $component_name, $id, $screen)
        $action = '';
        $action_name = '';
        $component = '';
        $component_name = '';
        $format = 'string';
        $notification_id = 0;
        $item_id = 0;
        $secondary_item_id = 0;
        $text = '';

        if ( isset($args[1]) && is_string($args[1]) && in_array($args[1], ['string', 'array', 'object'], true) ) {
            // BuddyPress signature
            $format = $args[1] ?? 'string';
            $action = $args[2] ?? '';
            $component = $args[3] ?? '';
            $notification_id = (int) ($args[4] ?? 0);
            $item_id = (int) ($args[5] ?? 0);
            $secondary_item_id = (int) ($args[6] ?? 0);
            $component_name = $component;
            $action_name = $action;
        } elseif ( isset($args[4]) && is_string($args[4]) && in_array($args[4], ['string', 'array', 'object'], true) ) {
            // BuddyBoss plugin signature (component_action, item_id, secondary_item_id, total_items, format, component_action_name, component_name, id, screen)
            $action = $content;
            $item_id = (int) ($args[0] ?? 0);
            $secondary_item_id = (int) ($args[1] ?? 0);
            $format = $args[4] ?? 'string';
            $action_name = $args[5] ?? '';
            $component_name = $args[6] ?? '';
            $notification_id = (int) ($args[7] ?? 0);
            $component = $component_name;
        } else {
            $action = $args[0] ?? '';
            $item_id = (int) ($args[1] ?? 0);
            $secondary_item_id = (int) ($args[2] ?? 0);
            $format = $args[4] ?? 'string';
            $action_name = $args[5] ?? '';
            $component_name = $args[6] ?? '';
            $notification_id = (int) ($args[7] ?? 0);
            $component = $component_name;
        }

        $action_key = $action ?: $action_name;
        if ( ! in_array($action_key, ['story_reaction', 'story_reply'], true) && $component_name !== 'stories' && $component !== 'stories' ) {
            return $content;
        }

        $message = self::build_notification_message($action_key, $item_id, $secondary_item_id, $notification_id);
        if ( ! $message ) {
            return $content;
        }

        if ( $format === 'array' ) {
            return [
                'text' => $message['text'],
                'link' => $message['link'],
            ];
        }

        return $message['html'];
    }

    public static function format_notification_description($description, $notification) {
        if ( ! is_object($notification) && ! is_array($notification) ) {
            return $description;
        }

        $component = self::get_notification_field($notification, 'component_name');
        $action_key = self::get_notification_field($notification, 'component_action');
        if ( ! in_array($action_key, ['story_reaction', 'story_reply'], true) && $component !== 'stories' ) {
            return $description;
        }

        $story_id = (int) self::get_notification_field($notification, 'item_id');
        $actor_id = (int) self::get_notification_field($notification, 'secondary_item_id');
        $notification_id = (int) self::get_notification_field($notification, 'id');

        $message = self::build_notification_message($action_key, $story_id, $actor_id, $notification_id);
        if ( ! $message ) {
            return $description;
        }

        return $message['text'];
    }

    public static function format_notification_string($string, ...$args) {
        $component = '';
        $action_key = '';
        $item_id = 0;
        $secondary_item_id = 0;
        $notification_id = 0;
        $format = 'string';

        if ( isset($args[0]) && (is_object($args[0]) || is_array($args[0])) ) {
            $component = self::get_notification_field($args[0], 'component_name');
            $action_key = self::get_notification_field($args[0], 'component_action');
            $item_id = (int) self::get_notification_field($args[0], 'item_id');
            $secondary_item_id = (int) self::get_notification_field($args[0], 'secondary_item_id');
            $notification_id = (int) self::get_notification_field($args[0], 'id');
            $format = $args[2] ?? 'string';
        } else {
            $component = $args[0] ?? '';
            $action_key = $args[1] ?? '';
            $item_id = (int) ($args[2] ?? 0);
            $secondary_item_id = (int) ($args[3] ?? 0);
            $format = $args[5] ?? 'string';
            $notification_id = (int) ($args[7] ?? 0);
        }

        if ( ! in_array($action_key, ['story_reaction', 'story_reply'], true) && $component !== 'stories' ) {
            return $string;
        }

        $message = self::build_notification_message($action_key, $item_id, $secondary_item_id, $notification_id);
        if ( ! $message ) {
            return $string;
        }

        if ( $format === 'array' ) {
            return [
                'text' => $message['text'],
                'link' => $message['link'],
            ];
        }

        return $message['html'];
    }

    public static function format_component_notification($content, $item_id, $secondary_item_id, $total_items, $format, $component_action, $component_name, $notification_id, $screen) {
        $action_key = (string) $component_action;
        if ( $component_name !== 'stories' && ! in_array($action_key, ['story_reaction', 'story_reply'], true) ) {
            return $content;
        }

        $message = self::build_notification_message($action_key, (int) $item_id, (int) $secondary_item_id, (int) $notification_id);
        if ( ! $message ) {
            return $content;
        }

        if ( $format === 'array' || $format === 'object' ) {
            return [
                'text' => $message['text'],
                'link' => $message['link'],
            ];
        }
        return $message['html'];
    }

    public static function register_component_object() : void {
        if ( ! function_exists('buddypress') ) return;
        $bp = buddypress();
        if ( isset($bp->stories) && ! empty($bp->stories->notification_callback) ) {
            return;
        }
        if ( ! isset($bp->stories) || ! is_object($bp->stories) ) {
            $bp->stories = new stdClass();
        }
        $bp->stories->id = 'stories';
        $bp->stories->slug = 'stories';
        $bp->stories->notification_callback = [__CLASS__, 'notification_callback'];
    }

    public static function notification_callback($action, $item_id, $secondary_item_id, $total_items, $format, $action_name = '', $component_name = '', $notification_id = 0) {
        $action_key = $action_name ?: $action;
        $message = self::build_notification_message($action_key, (int) $item_id, (int) $secondary_item_id, (int) $notification_id);
        if ( ! $message ) {
            return is_array($format) ? [] : $action_key;
        }
        if ( $format === 'array' || $format === 'object' ) {
            return [
                'text' => $message['text'],
                'link' => $message['link'],
            ];
        }
        return $message['html'];
    }

    private static function get_notification_field($notification, string $key) {
        if ( is_object($notification) ) {
            return $notification->{$key} ?? '';
        }
        if ( is_array($notification) ) {
            return $notification[$key] ?? '';
        }
        return '';
    }

    private static function build_notification_message(string $action_key, int $story_id, int $actor_id, int $notification_id) : array {
        if ( ! in_array($action_key, ['story_reaction', 'story_reply'], true) ) {
            return [];
        }

        $actor = $actor_id ? get_user_by('id', $actor_id) : null;
        $actor_name = $actor ? $actor->display_name : __('Someone', 'koopo');
        $author_id = $story_id ? (int) get_post_field('post_author', $story_id) : 0;
        $link = self::get_story_link($story_id, $author_id);
        if ( ! $link ) $link = home_url('/');

        $reaction = '';
        if ( $notification_id && function_exists('bp_notifications_get_meta') ) {
            $reaction = (string) bp_notifications_get_meta($notification_id, 'reaction', true);
        }

        if ( $action_key === 'story_reaction' ) {
            $emoji = $reaction ? ' ' . $reaction : '';
            $text = sprintf(__('%s reacted%s to your story.', 'koopo'), $actor_name, $emoji);
        } else {
            $text = sprintf(__('%s replied to your story.', 'koopo'), $actor_name);
        }

        $cover = self::get_story_cover_image($story_id);
        $cover_html = '';
        if ( $cover ) {
            $cover_html = sprintf(
                ' <a href="%s" class="koopo-story-notification-cover"><img src="%s" alt="%s" style="width:36px;height:36px;border-radius:8px;object-fit:cover;vertical-align:middle;margin-left:6px;" /></a>',
                esc_url($link),
                esc_url($cover),
                esc_attr__('Story cover', 'koopo')
            );
        }

        return [
            'text' => $text,
            'link' => $link,
            'html' => sprintf('<a href="%s">%s</a>%s', esc_url($link), esc_html($text), $cover_html),
        ];
    }

    private static function get_story_link(int $story_id, int $author_id) : string {
        $link = '';
        if ( $author_id && function_exists('bp_core_get_user_domain') ) {
            $link = trailingslashit(bp_core_get_user_domain($author_id)) . 'activity/';
        }
        if ( empty($link) ) {
            $link = trailingslashit(home_url('/')) . 'activity/';
        }
        $link = add_query_arg('koopo_story', $story_id, $link);
        return $link;
    }

    private static function get_story_cover_image(int $story_id) : string {
        if ( $story_id <= 0 ) return '';
        if ( ! function_exists('get_posts') ) return '';

        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $item_id = isset($items[0]) ? (int) $items[0] : 0;
        if ( $item_id <= 0 ) return '';

        return Koopo_Stories_Utils::get_story_cover_thumb($item_id, 'thumbnail');
    }
}
