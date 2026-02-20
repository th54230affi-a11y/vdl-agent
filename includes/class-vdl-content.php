<?php
/**
 * VDL Agent Content Management
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Content {

    /**
     * List posts/pages
     */
    public static function list_posts($request) {
        $post_type = 'post';
        $type_param = $request->get_param('type');
        if (!empty($type_param)) {
            $post_type = $type_param;
        }

        $status = 'any';
        $status_param = $request->get_param('status');
        if (!empty($status_param)) {
            $status = $status_param;
        }

        $per_page = 50;
        $per_page_param = $request->get_param('per_page');
        if (!empty($per_page_param)) {
            $per_page = intval($per_page_param);
        }
        if ($per_page > 100) {
            $per_page = 100;
        }

        $page = 1;
        $page_param = $request->get_param('page');
        if (!empty($page_param)) {
            $page = intval($page_param);
        }

        $args = array(
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $search = $request->get_param('search');
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $posts = array();

        foreach ($query->posts as $post) {
            $posts[] = array(
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'status'   => $post->post_status,
                'type'     => $post->post_type,
                'date'     => $post->post_date,
                'url'      => get_permalink($post->ID),
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'posts'   => $posts,
            'total'   => $query->found_posts,
            'pages'   => $query->max_num_pages,
            'current' => $page,
        ));
    }

    /**
     * Get single post
     */
    public static function get_post($request) {
        $id = intval($request['id']);
        $post = get_post($id);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found', array('status' => 404));
        }

        return rest_ensure_response(array(
            'success' => true,
            'post'    => array(
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'slug'    => $post->post_name,
                'status'  => $post->post_status,
                'type'    => $post->post_type,
                'date'    => $post->post_date,
                'url'     => get_permalink($post->ID),
            ),
        ));
    }

    /**
     * Update post
     */
    public static function update_post($request) {
        $id = intval($request['id']);
        $post = get_post($id);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found', array('status' => 404));
        }

        $update_data = array('ID' => $id);

        $title = $request->get_param('title');
        if ($title !== null) {
            $update_data['post_title'] = sanitize_text_field($title);
        }

        $content = $request->get_param('content');
        if ($content !== null) {
            $update_data['post_content'] = wp_kses_post($content);
        }

        $status = $request->get_param('status');
        if ($status !== null) {
            $update_data['post_status'] = $status;
        }

        $result = wp_update_post($update_data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Post updated',
        ));
    }

    /**
     * Delete post
     */
    public static function delete_post($request) {
        $id = intval($request['id']);
        $force = $request->get_param('force');
        $force_delete = ($force === 'true' || $force === true);

        $post = get_post($id);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found', array('status' => 404));
        }

        if ($force_delete) {
            $result = wp_delete_post($id, true);
        } else {
            $result = wp_trash_post($id);
        }

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => $force_delete ? 'Deleted' : 'Trashed',
        ));
    }

    /**
     * Search and replace
     */
    public static function search_replace_content($request) {
        $search = $request->get_param('search');
        if (empty($search)) {
            return new WP_Error('invalid_params', 'Search string required', array('status' => 400));
        }

        $replace = $request->get_param('replace');
        if ($replace === null) {
            $replace = '';
        }

        $dry_run = $request->get_param('dry_run');
        $is_dry_run = ($dry_run === null || $dry_run === true || $dry_run === 'true');

        global $wpdb;

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type FROM {$wpdb->posts}
            WHERE (post_content LIKE %s OR post_title LIKE %s)
            AND post_status != 'auto-draft'",
            '%' . $wpdb->esc_like($search) . '%',
            '%' . $wpdb->esc_like($search) . '%'
        ));

        $affected = array();
        $count = 0;

        foreach ($posts as $p) {
            $tc = substr_count($p->post_title, $search);
            $cc = substr_count($p->post_content, $search);
            $total = $tc + $cc;

            if ($total > 0) {
                $affected[] = array(
                    'id'          => $p->ID,
                    'title'       => $p->post_title,
                    'occurrences' => $total,
                );
                $count = $count + $total;

                if (!$is_dry_run) {
                    wp_update_post(array(
                        'ID'           => $p->ID,
                        'post_title'   => str_replace($search, $replace, $p->post_title),
                        'post_content' => str_replace($search, $replace, $p->post_content),
                    ));
                }
            }
        }

        return rest_ensure_response(array(
            'success'     => true,
            'dry_run'     => $is_dry_run,
            'total_found' => $count,
            'affected'    => $affected,
        ));
    }

    /**
     * Bulk delete
     */
    public static function bulk_delete($request) {
        $ids = $request->get_param('ids');
        if (!is_array($ids) || empty($ids)) {
            return new WP_Error('invalid_params', 'IDs required', array('status' => 400));
        }

        $force = $request->get_param('force');
        $force_delete = ($force === true || $force === 'true');

        $deleted = array();
        foreach ($ids as $id) {
            $id = intval($id);
            if ($force_delete) {
                $r = wp_delete_post($id, true);
            } else {
                $r = wp_trash_post($id);
            }
            if ($r) {
                $deleted[] = $id;
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'deleted' => $deleted,
        ));
    }

    /**
     * List categories
     */
    public static function list_categories($request) {
        $cats = get_categories(array('hide_empty' => false));
        $result = array();

        foreach ($cats as $c) {
            $result[] = array(
                'id'    => $c->term_id,
                'name'  => $c->name,
                'slug'  => $c->slug,
                'count' => $c->count,
            );
        }

        return rest_ensure_response(array(
            'success'    => true,
            'categories' => $result,
        ));
    }
}
