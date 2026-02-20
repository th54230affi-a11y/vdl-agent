<?php
/**
 * VDL Agent Links Management
 *
 * CRUD pour les liens sponsorisés VDL
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Links {

    /**
     * List all VDL links
     */
    public static function list_links($request) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(10, (int) $request->get_param('per_page') ?: 50));
        $offset = ($page - 1) * $per_page;

        // Filters
        $post_id = $request->get_param('post_id');
        $link_rel = $request->get_param('rel');
        $search = $request->get_param('search');

        $where = array('1=1');
        $values = array();

        if ($post_id) {
            $where[] = 'post_id = %d';
            $values[] = $post_id;
        }

        if ($link_rel) {
            $where[] = 'link_rel = %s';
            $values[] = $link_rel;
        }

        if ($search) {
            $where[] = '(link_url LIKE %s OR link_anchor LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($search) . '%';
            $values[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $where_sql = implode(' AND ', $where);

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM $stats_table WHERE $where_sql";
        $total = $wpdb->get_var($values ? $wpdb->prepare($count_sql, $values) : $count_sql);

        // Get links
        $sql = "SELECT * FROM $stats_table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        $links = $wpdb->get_results($wpdb->prepare($sql, $values));

        // Enrich with post data
        foreach ($links as &$link) {
            $link->post_title = get_the_title($link->post_id);
            $link->post_url = get_permalink($link->post_id);
            $link->ctr = $link->impressions > 0 ? round(($link->clicks / $link->impressions) * 100, 2) : 0;
        }

        return rest_ensure_response(array(
            'success' => true,
            'links'   => $links,
            'pagination' => array(
                'page'       => $page,
                'per_page'   => $per_page,
                'total'      => (int) $total,
                'total_pages' => ceil($total / $per_page),
            ),
        ));
    }

    /**
     * Get a single link
     */
    public static function get_link($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $stats_table WHERE id = %d",
            $id
        ));

        if (!$link) {
            return new WP_Error('not_found', __('Link not found', 'vdl-agent'), array('status' => 404));
        }

        // Enrich
        $link->post_title = get_the_title($link->post_id);
        $link->post_url = get_permalink($link->post_id);
        $link->ctr = $link->impressions > 0 ? round(($link->clicks / $link->impressions) * 100, 2) : 0;

        // Get click history
        $clicks_table = $wpdb->prefix . 'vdl_clicks_log';
        $link->recent_clicks = $wpdb->get_results($wpdb->prepare(
            "SELECT clicked_at, referer FROM $clicks_table WHERE link_id = %d ORDER BY clicked_at DESC LIMIT 10",
            $id
        ));

        return rest_ensure_response(array(
            'success' => true,
            'link'    => $link,
        ));
    }

    /**
     * Add a new link
     */
    public static function add_link($request) {
        global $wpdb;

        $post_id = (int) $request->get_param('post_id');
        $link_url = esc_url_raw($request->get_param('url'));
        $link_anchor = sanitize_text_field($request->get_param('anchor'));
        $link_rel = sanitize_text_field($request->get_param('rel')) ?: 'dofollow';

        // Validate
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', __('Invalid post ID', 'vdl-agent'), array('status' => 400));
        }

        if (empty($link_url)) {
            return new WP_Error('missing_url', __('Link URL is required', 'vdl-agent'), array('status' => 400));
        }

        if (empty($link_anchor)) {
            return new WP_Error('missing_anchor', __('Link anchor text is required', 'vdl-agent'), array('status' => 400));
        }

        // Validate rel attribute
        $allowed_rels = array('dofollow', 'nofollow', 'sponsored', 'ugc', 'nofollow sponsored');
        if (!in_array($link_rel, $allowed_rels)) {
            $link_rel = 'dofollow';
        }

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        // Check for duplicate
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $stats_table WHERE post_id = %d AND link_url = %s AND link_anchor = %s",
            $post_id,
            $link_url,
            $link_anchor
        ));

        if ($existing) {
            return new WP_Error('duplicate', __('This link already exists for this post', 'vdl-agent'), array('status' => 409));
        }

        // Insert
        $result = $wpdb->insert(
            $stats_table,
            array(
                'post_id'     => $post_id,
                'link_url'    => $link_url,
                'link_anchor' => $link_anchor,
                'link_rel'    => $link_rel,
                'clicks'      => 0,
                'impressions' => 0,
                'created_at'  => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%d', '%d', '%s')
        );

        if (!$result) {
            return new WP_Error('insert_error', __('Could not add link', 'vdl-agent'), array('status' => 500));
        }

        $link_id = $wpdb->insert_id;

        // Also update post meta for the theme to use
        self::update_post_vdl_meta($post_id);

        return rest_ensure_response(array(
            'success' => true,
            'link_id' => $link_id,
            'message' => __('Link added successfully', 'vdl-agent'),
        ));
    }

    /**
     * Update a link
     */
    public static function update_link($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        // Check if exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $stats_table WHERE id = %d",
            $id
        ));

        if (!$existing) {
            return new WP_Error('not_found', __('Link not found', 'vdl-agent'), array('status' => 404));
        }

        // Build update data
        $data = array();
        $formats = array();

        $link_url = $request->get_param('url');
        if ($link_url !== null) {
            $data['link_url'] = esc_url_raw($link_url);
            $formats[] = '%s';
        }

        $link_anchor = $request->get_param('anchor');
        if ($link_anchor !== null) {
            $data['link_anchor'] = sanitize_text_field($link_anchor);
            $formats[] = '%s';
        }

        $link_rel = $request->get_param('rel');
        if ($link_rel !== null) {
            $allowed_rels = array('dofollow', 'nofollow', 'sponsored', 'ugc', 'nofollow sponsored');
            if (in_array($link_rel, $allowed_rels)) {
                $data['link_rel'] = $link_rel;
                $formats[] = '%s';
            }
        }

        if (empty($data)) {
            return new WP_Error('no_changes', __('No changes provided', 'vdl-agent'), array('status' => 400));
        }

        $data['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $wpdb->update(
            $stats_table,
            $data,
            array('id' => $id),
            $formats,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('update_error', __('Could not update link', 'vdl-agent'), array('status' => 500));
        }

        // Update post meta
        self::update_post_vdl_meta($existing->post_id);

        return rest_ensure_response(array(
            'success' => true,
            'link_id' => $id,
            'message' => __('Link updated successfully', 'vdl-agent'),
        ));
    }

    /**
     * Delete a link
     */
    public static function delete_link($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $stats_table = $wpdb->prefix . 'vdl_link_stats';
        $clicks_table = $wpdb->prefix . 'vdl_clicks_log';

        // Check if exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id FROM $stats_table WHERE id = %d",
            $id
        ));

        if (!$existing) {
            return new WP_Error('not_found', __('Link not found', 'vdl-agent'), array('status' => 404));
        }

        // Delete click logs
        $wpdb->delete($clicks_table, array('link_id' => $id), array('%d'));

        // Delete link
        $result = $wpdb->delete($stats_table, array('id' => $id), array('%d'));

        if (!$result) {
            return new WP_Error('delete_error', __('Could not delete link', 'vdl-agent'), array('status' => 500));
        }

        // Update post meta
        self::update_post_vdl_meta($existing->post_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Link deleted successfully', 'vdl-agent'),
        ));
    }

    /**
     * Bulk actions
     */
    public static function bulk_action($request) {
        global $wpdb;

        $action = $request->get_param('action');
        $ids = $request->get_param('ids');

        if (empty($action) || empty($ids) || !is_array($ids)) {
            return new WP_Error('invalid_params', __('Action and IDs are required', 'vdl-agent'), array('status' => 400));
        }

        $stats_table = $wpdb->prefix . 'vdl_link_stats';
        $ids = array_map('intval', $ids);
        $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));

        $affected = 0;

        switch ($action) {
            case 'delete':
                // Delete click logs
                $clicks_table = $wpdb->prefix . 'vdl_clicks_log';
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $clicks_table WHERE link_id IN ($ids_placeholder)",
                    ...$ids
                ));

                // Delete links
                $affected = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $stats_table WHERE id IN ($ids_placeholder)",
                    ...$ids
                ));
                break;

            case 'set_nofollow':
                $affected = $wpdb->query($wpdb->prepare(
                    "UPDATE $stats_table SET link_rel = 'nofollow', updated_at = %s WHERE id IN ($ids_placeholder)",
                    current_time('mysql'),
                    ...$ids
                ));
                break;

            case 'set_dofollow':
                $affected = $wpdb->query($wpdb->prepare(
                    "UPDATE $stats_table SET link_rel = 'dofollow', updated_at = %s WHERE id IN ($ids_placeholder)",
                    current_time('mysql'),
                    ...$ids
                ));
                break;

            case 'set_sponsored':
                $affected = $wpdb->query($wpdb->prepare(
                    "UPDATE $stats_table SET link_rel = 'sponsored', updated_at = %s WHERE id IN ($ids_placeholder)",
                    current_time('mysql'),
                    ...$ids
                ));
                break;

            case 'reset_clicks':
                $affected = $wpdb->query($wpdb->prepare(
                    "UPDATE $stats_table SET clicks = 0, updated_at = %s WHERE id IN ($ids_placeholder)",
                    current_time('mysql'),
                    ...$ids
                ));
                break;

            default:
                return new WP_Error('invalid_action', __('Invalid bulk action', 'vdl-agent'), array('status' => 400));
        }

        return rest_ensure_response(array(
            'success'  => true,
            'action'   => $action,
            'affected' => $affected,
            'message'  => sprintf(__('%d link(s) affected', 'vdl-agent'), $affected),
        ));
    }

    /**
     * Update post VDL meta (for theme compatibility)
     */
    private static function update_post_vdl_meta($post_id) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        // Get all links for this post
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT link_url, link_anchor, link_rel FROM $stats_table WHERE post_id = %d",
            $post_id
        ), ARRAY_A);

        // Update post meta
        if (!empty($links)) {
            update_post_meta($post_id, '_vdl_sponsored_links', $links);
            update_post_meta($post_id, '_vdl_is_sponsored', '1');
        } else {
            delete_post_meta($post_id, '_vdl_sponsored_links');
            delete_post_meta($post_id, '_vdl_is_sponsored');
        }
    }
}
