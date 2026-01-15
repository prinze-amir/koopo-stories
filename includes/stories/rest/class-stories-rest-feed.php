<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST_Feed {

    public static function get_feed( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $compact = $req->get_param('compact') === '1' || $req->get_param('mobile') === '1';

        $limit = max(1, min(50, intval($req->get_param('limit'))));
        $scope = $req->get_param('scope');
        $scope = in_array($scope, ['friends','following','all'], true) ? $scope : 'friends';
        $only_me = $req->get_param('only_me');
        $only_me = ($only_me === '1' || $only_me === 1 || $only_me === true);

        $exclude_me = intval($req->get_param('exclude_me')) === 1;
        $order = $req->get_param('order');
        $order = in_array($order, ['unseen_first','recent_activity'], true) ? $order : 'unseen_first';

        $cache_key = Koopo_Stories_Utils::build_feed_cache_key($user_id, [
            'scope' => $scope,
            'exclude_me' => $exclude_me,
            'order' => $order,
            'limit' => $limit,
            'compact' => $compact,
        ]);
        $cached = get_transient($cache_key);
        if ( is_array($cached) ) {
            return new WP_REST_Response($cached, 200);
        }

        // Resolve which authors we should include for this scope
        $author_ids = [];
        if ( ! $only_me ) {
            if ( $scope === 'friends' ) {
                $author_ids = Koopo_Stories_Permissions::friend_ids($user_id);
            } elseif ( $scope === 'following' ) {
                $author_ids = Koopo_Stories_Permissions::following_ids($user_id);
            }

            if ( $scope !== 'all' ) {
                // include self by default unless excluded
                if ( ! $exclude_me ) {
                    $author_ids[] = $user_id;
                }
                $author_ids = array_values(array_unique(array_filter(array_map('intval', $author_ids))));
            }
        }

        // Query a bit more if we plan to sort by unseen-first, so we can fill the limit after sorting
        $query_limit = $limit;
        if ( $order === 'unseen_first' ) {
            $query_limit = min(200, max($limit, $limit * 4));
        }

        $expiry_clause = [
            'relation' => 'OR',
            [
                'key' => 'expires_at',
                'value' => time(),
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
            [
                'key' => 'expires_at',
                'compare' => 'NOT EXISTS',
            ],
        ];

        $q = [
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'publish',
            'posts_per_page' => $query_limit,
            'orderby' => ($order === 'recent_activity' || $order === 'unseen_first') ? 'modified' : 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'AND',
                $expiry_clause,
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
        ];

        if ( $only_me ) {
            $q['author__in'] = [ $user_id ];
        } elseif ( $scope !== 'all' && ! empty($author_ids) ) {
            $q['author__in'] = $author_ids;
        }

        // Fetch stories for the requested scope.
        // For friends/following scopes, we ALSO include public stories from everyone so that
        // users who set privacy=public can be discovered outside of connections.
        $stories = [];
        if ( $only_me ) {
            $stories = get_posts($q);
        } else {
            $stories_scoped = [];
            if ( $scope === 'all' || ! empty($author_ids) ) {
                $stories_scoped = get_posts($q);
            }

            $stories_public = [];
            if ( $scope !== 'all' ) {
                // Build a nested meta_query: ( (expires_at > now OR expires_at NOT EXISTS) AND privacy = public )
                $q_public = $q;
                unset($q_public['author__in']);
                $q_public['meta_query'] = [
                    'relation' => 'AND',
                    $expiry_clause,
                    [
                        'key' => 'privacy',
                        'value' => 'public',
                        'compare' => '=',
                    ],
                ];

                // Respect exclude_me for the public pass too.
                if ( $exclude_me ) {
                    $q_public['author__not_in'] = [ $user_id ];
                }

                $stories_public = get_posts($q_public);
            }

            // Merge + de-dupe by story ID.
            $by_id = [];
            foreach ( $stories_scoped as $p ) {
                $by_id[(int) $p->ID] = $p;
            }
            foreach ( $stories_public as $p ) {
                $by_id[(int) $p->ID] = $p;
            }
            $stories = array_values($by_id);
        }

        // Ensure consistent ordering after merge (DESC).
        usort($stories, function($a, $b) use ($order) {
            $ta = ($order === 'recent_activity' || $order === 'unseen_first')
                ? strtotime($a->post_modified_gmt ?: $a->post_modified)
                : strtotime($a->post_date_gmt ?: $a->post_date);
            $tb = ($order === 'recent_activity' || $order === 'unseen_first')
                ? strtotime($b->post_modified_gmt ?: $b->post_modified)
                : strtotime($b->post_date_gmt ?: $b->post_date);
            if ( $ta === $tb ) return 0;
            return ($ta > $tb) ? -1 : 1;
        });

        // Preload all items for these stories in a single query to avoid per-story lookups.
        $story_ids = array_map(function($p){ return (int) $p->ID; }, $stories);
        $items_by_story = [];
        if ( ! empty($story_ids) ) {
            $items_all = get_posts([
                'post_type' => Koopo_Stories_Module::CPT_ITEM,
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'story_id',
                        'value' => $story_ids,
                        'compare' => 'IN',
                    ],
                ],
                'orderby' => 'date',
                'order' => 'ASC',
            ]);

            foreach ( $items_all as $item_id ) {
                $sid = (int) get_post_meta($item_id, 'story_id', true);
                if ( $sid <= 0 ) continue;
                if ( ! isset($items_by_story[$sid]) ) $items_by_story[$sid] = [];
                $items_by_story[$sid][] = (int) $item_id;
            }
        }

        // Group stories by author_id
        $grouped = [];
        foreach ( $stories as $story ) {
            $sid = (int) $story->ID;

            // If privacy is connections-only, enforce it (for 'all' scope too)
            if ( ! Koopo_Stories_Permissions::can_view_story($sid, $user_id) ) {
                continue;
            }

            $items = $items_by_story[$sid] ?? [];

            $items_count = is_array($items) ? count($items) : 0;
            if ( $items_count === 0 ) continue;

            $author_id = (int) $story->post_author;

            // Initialize author entry if not exists
            if ( ! isset($grouped[$author_id]) ) {
                $grouped[$author_id] = [
                    'story_id' => $sid, // Use first story ID as main
                    'story_ids' => [],
                    'author' => Koopo_Stories_Utils::get_author_payload($author_id, 96, true),
                    'cover_thumb' => '',
                    'last_updated' => '',
                    'has_unseen' => false,
                    'unseen_count' => 0,
                    'items_count' => 0,
                    'all_items' => [],
                    'privacy' => 'public',
                ];
            }

            // Add this story's data to the author group
            $grouped[$author_id]['story_ids'][] = $sid;
            $grouped[$author_id]['items_count'] += $items_count;
            $grouped[$author_id]['all_items'] = array_merge($grouped[$author_id]['all_items'], $items);

            // Update last_updated if this story is more recent
            $story_updated = get_post_modified_time(DATE_ATOM, true, $sid);
            if ( empty($grouped[$author_id]['last_updated']) || $story_updated > $grouped[$author_id]['last_updated'] ) {
                $grouped[$author_id]['last_updated'] = $story_updated;
            }

            // Set cover thumb from first item if not set
            if ( empty($grouped[$author_id]['cover_thumb']) && !empty($items) ) {
                $first_item_id = (int) $items[0];
                $thumb = Koopo_Stories_Utils::get_story_cover_thumb($first_item_id, 'thumbnail');
                if ( $thumb ) $grouped[$author_id]['cover_thumb'] = $thumb;
            }

            // Update privacy if this story is more restrictive
            $privacy = get_post_meta($sid, 'privacy', true);
            $grouped[$author_id]['privacy'] = Koopo_Stories_Utils::pick_more_restrictive_privacy(
                $grouped[$author_id]['privacy'],
                $privacy
            );
        }

        // Calculate unseen counts for each author's grouped stories
        $out = [];
        foreach ( $grouped as $author_id => $data ) {
            $all_items = $data['all_items'];
            $seen_map = Koopo_Stories_Views_Table::has_seen_any($all_items, $user_id);

            $has_unseen = false;
            $unseen_count = 0;
            foreach ($all_items as $iid) {
                if ( empty($seen_map[(int)$iid]) ) {
                    $has_unseen = true;
                    $unseen_count++;
                }
            }

            $out[] = [
                'story_id' => $data['story_id'],
                'story_ids' => $data['story_ids'],
                'author' => $data['author'],
                'cover_thumb' => $data['cover_thumb'],
                'last_updated' => $data['last_updated'],
                'has_unseen' => $has_unseen,
                'unseen_count' => $unseen_count,
                'items_count' => $data['items_count'],
                'privacy' => $data['privacy'],
            ];
        }

        if ( $order === 'unseen_first' ) {
            usort($out, function($a, $b){
                if ( (int)$a['has_unseen'] !== (int)$b['has_unseen'] ) {
                    return ((int)$b['has_unseen']) <=> ((int)$a['has_unseen']);
                }
                return strcmp($b['last_updated'], $a['last_updated']);
            });
        } else {
            usort($out, function($a, $b){
                return strcmp($b['last_updated'], $a['last_updated']);
            });
        }

        $out = array_slice($out, 0, $limit);

        $payload = [
            'api_version' => Koopo_Stories_REST::API_VERSION,
            'stories' => array_values($out),
        ];
        if ( $compact ) {
            foreach ( $payload['stories'] as &$row ) {
                unset($row['author']['profile_url']);
            }
        }
        $cache_ttl = Koopo_Stories_Utils::get_cache_ttl('feed', 60);
        set_transient($cache_key, $payload, $cache_ttl);
        return new WP_REST_Response($payload, 200);
    }

    public static function get_archived_stories( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $limit = max(1, min(50, intval($req->get_param('limit') ?: 20)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $compact = $req->get_param('compact') === '1' || $req->get_param('mobile') === '1';

        $cache_key = Koopo_Stories_Utils::build_archive_cache_key($user_id, [
            'limit' => $limit,
            'page' => $page,
            'compact' => $compact,
        ]);
        $cached = get_transient($cache_key);
        if ( is_array($cached) ) {
            return new WP_REST_Response($cached, 200);
        }

        $stories = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'any',
            'author' => $user_id,
            'posts_per_page' => $limit,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => 'is_archived',
                    'value' => 1,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        $out = [];
        foreach ( $stories as $story ) {
            $sid = (int) $story->ID;
            $items = get_posts([
                'post_type' => Koopo_Stories_Module::CPT_ITEM,
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'meta_key' => 'story_id',
                'meta_value' => $sid,
                'orderby' => 'date',
                'order' => 'ASC',
            ]);

            $items_count = is_array($items) ? count($items) : 0;
            if ( $items_count === 0 ) continue;

            $author_id = (int) $story->post_author;
            $cover_thumb = '';
            if ( ! empty($items) ) {
                $first_item_id = (int) $items[0];
                $cover_thumb = Koopo_Stories_Utils::get_story_cover_thumb($first_item_id, 'thumbnail');
            }

            $privacy = Koopo_Stories_REST::normalize_privacy(get_post_meta($sid, 'privacy', true));

            $out[] = [
                'story_id' => $sid,
                'story_ids' => [ $sid ],
                'author' => Koopo_Stories_Utils::get_author_payload($author_id, 96, true),
                'cover_thumb' => $cover_thumb,
                'last_updated' => get_post_modified_time(DATE_ATOM, true, $sid),
                'created_at' => mysql_to_rfc3339( get_gmt_from_date($story->post_date) ),
                'has_unseen' => false,
                'unseen_count' => 0,
                'items_count' => $items_count,
                'privacy' => $privacy,
                'view_count' => Koopo_Stories_Views_Table::get_story_view_count($items),
                'is_archived' => true,
            ];
        }

        $has_more = count($out) === $limit;

        $payload = [
            'api_version' => Koopo_Stories_REST::API_VERSION,
            'stories' => $out,
            'has_more' => $has_more,
            'page' => $page,
        ];

        if ( $compact ) {
            foreach ( $payload['stories'] as &$row ) {
                unset($row['author']['profile_url']);
            }
        }

        $cache_ttl = Koopo_Stories_Utils::get_cache_ttl('archive', 60);
        set_transient($cache_key, $payload, $cache_ttl);
        return new WP_REST_Response($payload, 200);
    }
}
