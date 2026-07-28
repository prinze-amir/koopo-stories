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
            'only_me' => $only_me,
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
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
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
                $q_public['meta_query'][] = [
                    'key' => 'privacy',
                    'value' => 'public',
                    'compare' => '=',
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

        $story_ids = array_values(array_unique(array_map(function($p){ return (int) $p->ID; }, $stories)));
        if ( empty($story_ids) ) {
            $payload = [
                'api_version' => Koopo_Stories_REST::API_VERSION,
                'stories' => [],
            ];
            $cache_ttl = Koopo_Stories_Utils::get_cache_ttl('feed', 60);
            set_transient($cache_key, $payload, $cache_ttl);
            return new WP_REST_Response($payload, 200);
        }

        update_meta_cache('post', $story_ids);

        $items_by_story = [];
        $first_item_by_story = [];
        foreach ( self::get_story_item_rows($story_ids, 'ASC') as $row ) {
            $sid = (int) ($row['story_id'] ?? 0);
            $item_id = (int) ($row['item_id'] ?? 0);
            if ( $sid <= 0 || $item_id <= 0 ) {
                continue;
            }
            if ( ! isset($items_by_story[$sid]) ) {
                $items_by_story[$sid] = [];
            }
            $items_by_story[$sid][] = $item_id;
            if ( ! isset($first_item_by_story[$sid]) ) {
                $first_item_by_story[$sid] = $item_id;
            }
        }

        if ( ! empty($first_item_by_story) ) {
            update_meta_cache('post', array_values($first_item_by_story));
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
            foreach ( $items as $item_id ) {
                $grouped[$author_id]['all_items'][] = (int) $item_id;
            }

            // Update last_updated if this story is more recent
            $story_updated = get_post_modified_time(DATE_ATOM, true, $sid);
            if ( empty($grouped[$author_id]['last_updated']) || $story_updated > $grouped[$author_id]['last_updated'] ) {
                $grouped[$author_id]['last_updated'] = $story_updated;
            }

            // Set cover thumb from first item if not set
            if ( empty($grouped[$author_id]['cover_thumb']) ) {
                $first_item_id = (int) ($first_item_by_story[$sid] ?? 0);
                $thumb = Koopo_Stories_Utils::get_story_cover_thumb($first_item_id, 'thumbnail');
                if ( $thumb ) $grouped[$author_id]['cover_thumb'] = $thumb;
            }

            // Update privacy if this story is more restrictive
            $privacy = Koopo_Stories_Utils::normalize_privacy(get_post_meta($sid, 'privacy', true));
            $grouped[$author_id]['privacy'] = Koopo_Stories_Utils::pick_more_restrictive_privacy(
                $grouped[$author_id]['privacy'],
                $privacy
            );
        }

        // Calculate unseen counts for each author's grouped stories.
        // Use a single seen lookup for all grouped items to avoid N queries by author.
        $all_group_item_ids = [];
        foreach ( $grouped as $data ) {
            if ( ! empty($data['all_items']) ) {
                foreach ( $data['all_items'] as $item_id ) {
                    $all_group_item_ids[] = (int) $item_id;
                }
            }
        }
        $all_group_item_ids = array_values(array_unique(array_map('intval', $all_group_item_ids)));
        $seen_map_all = empty($all_group_item_ids)
            ? []
            : Koopo_Stories_Views_Table::has_seen_any($all_group_item_ids, $user_id);

        $out = [];
        foreach ( $grouped as $author_id => $data ) {
            $all_items = $data['all_items'];

            $has_unseen = false;
            $unseen_count = 0;
            $first_unseen_item_id = 0;
            foreach ($all_items as $iid) {
                if ( empty($seen_map_all[(int)$iid]) ) {
                    $has_unseen = true;
                    $unseen_count++;
                    if ( $first_unseen_item_id === 0 ) {
                        $first_unseen_item_id = (int) $iid;
                    }
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
                'first_unseen_item_id' => $first_unseen_item_id > 0 ? $first_unseen_item_id : null,
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
        $offset = ($page - 1) * $limit;

        $cache_key = Koopo_Stories_Utils::build_archive_cache_key($user_id, [
            'limit' => $limit,
            'page' => $page,
            'compact' => $compact,
        ]);
        $cached = get_transient($cache_key);
        if ( is_array($cached) ) {
            return new WP_REST_Response($cached, 200);
        }

        $total = self::count_archived_items($user_id);
        $rows = $total > 0 ? self::get_archived_item_rows($user_id, $limit, $offset) : [];
        $item_ids = array_values(array_unique(array_map(function( $row ) {
            return (int) ($row['item_id'] ?? 0);
        }, $rows)));

        if ( ! empty($item_ids) ) {
            update_meta_cache('post', $item_ids);
        }

        $view_counts = Koopo_Stories_Views_Table::get_view_counts($item_ids);
        $out = [];
        foreach ( $rows as $row ) {
            $item_id = (int) ($row['item_id'] ?? 0);
            $sid = (int) ($row['story_id'] ?? 0);
            if ( $item_id <= 0 || $sid <= 0 ) {
                continue;
            }

            $item_payload = Koopo_Stories_Utils::build_story_item_payload($item_id, false);
            if ( ! is_array($item_payload) || empty($item_payload['src']) ) {
                continue;
            }

            $out[] = [
                'story_id' => $sid,
                'item_id' => $item_id,
                'is_archive_item' => true,
                'author' => Koopo_Stories_Utils::get_author_payload((int) ($row['author_id'] ?? $user_id), 96, true),
                'cover_thumb' => Koopo_Stories_Utils::get_story_cover_thumb($item_id, 'thumbnail'),
                'item_type' => $item_payload['type'] ?? 'image',
                'item_src' => $item_payload['src'] ?? '',
                'last_updated' => self::format_rfc3339_datetime(
                    (string) ($row['post_modified_gmt'] ?? ''),
                    (string) ($row['post_modified'] ?? '')
                ),
                'created_at' => self::format_rfc3339_datetime(
                    (string) ($row['post_date_gmt'] ?? ''),
                    (string) ($row['post_date'] ?? '')
                ),
                'has_unseen' => false,
                'unseen_count' => 0,
                'items_count' => 1,
                'privacy' => Koopo_Stories_REST::normalize_privacy((string) ($row['privacy'] ?? '')),
                'view_count' => (int) ($view_counts[$item_id] ?? 0),
                'is_archived' => true,
            ];
        }

        $payload = [
            'api_version' => Koopo_Stories_REST::API_VERSION,
            'stories' => $out,
            'has_more' => ($offset + count($rows)) < $total,
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

    private static function get_story_item_rows( array $story_ids, string $order = 'ASC' ) : array {
        global $wpdb;

        $story_ids = array_values(array_unique(array_filter(array_map('intval', $story_ids))));
        if ( empty($story_ids) ) {
            return [];
        }

        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $placeholders = implode(',', array_fill(0, count($story_ids), '%d'));
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID AS item_id, CAST(pm_story.meta_value AS UNSIGNED) AS story_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_story
                ON pm_story.post_id = p.ID
               AND pm_story.meta_key = 'story_id'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND CAST(pm_story.meta_value AS UNSIGNED) IN ({$placeholders})
             ORDER BY p.post_date {$order}, p.ID {$order}",
            array_merge([ Koopo_Stories_Module::CPT_ITEM ], $story_ids)
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private static function get_archived_item_rows( int $user_id, int $limit, int $offset ) : array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT
                i.ID AS item_id,
                i.post_date,
                i.post_date_gmt,
                i.post_modified,
                i.post_modified_gmt,
                s.ID AS story_id,
                s.post_author AS author_id,
                pm_privacy.meta_value AS privacy
             FROM {$wpdb->posts} i
             INNER JOIN {$wpdb->postmeta} pm_story
                ON pm_story.post_id = i.ID
               AND pm_story.meta_key = 'story_id'
             INNER JOIN {$wpdb->posts} s
                ON s.ID = CAST(pm_story.meta_value AS UNSIGNED)
             INNER JOIN {$wpdb->postmeta} pm_archived
                ON pm_archived.post_id = s.ID
               AND pm_archived.meta_key = 'is_archived'
               AND pm_archived.meta_value = '1'
             LEFT JOIN {$wpdb->postmeta} pm_privacy
                ON pm_privacy.post_id = s.ID
               AND pm_privacy.meta_key = 'privacy'
             WHERE i.post_type = %s
               AND i.post_status = 'publish'
               AND s.post_type = %s
               AND s.post_author = %d
             ORDER BY i.post_date DESC, i.ID DESC
             LIMIT %d OFFSET %d",
            Koopo_Stories_Module::CPT_ITEM,
            Koopo_Stories_Module::CPT_STORY,
            $user_id,
            $limit,
            $offset
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private static function count_archived_items( int $user_id ) : int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT i.ID)
             FROM {$wpdb->posts} i
             INNER JOIN {$wpdb->postmeta} pm_story
                ON pm_story.post_id = i.ID
               AND pm_story.meta_key = 'story_id'
             INNER JOIN {$wpdb->posts} s
                ON s.ID = CAST(pm_story.meta_value AS UNSIGNED)
             INNER JOIN {$wpdb->postmeta} pm_archived
                ON pm_archived.post_id = s.ID
               AND pm_archived.meta_key = 'is_archived'
               AND pm_archived.meta_value = '1'
             WHERE i.post_type = %s
               AND i.post_status = 'publish'
               AND s.post_type = %s
               AND s.post_author = %d",
            Koopo_Stories_Module::CPT_ITEM,
            Koopo_Stories_Module::CPT_STORY,
            $user_id
        ) );
    }

    private static function format_rfc3339_datetime( string $gmt, string $local ) : string {
        $gmt = trim($gmt);
        if ( $gmt !== '' && $gmt !== '0000-00-00 00:00:00' ) {
            return mysql_to_rfc3339($gmt);
        }

        $local = trim($local);
        if ( $local !== '' && $local !== '0000-00-00 00:00:00' ) {
            return mysql_to_rfc3339( get_gmt_from_date($local) );
        }

        return '';
    }
}
