<?php
/**
 * VDL Agent SEO
 *
 * Gestion SEO, audit et meta tags
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_SEO {

    /**
     * Get SEO status overview
     */
    public static function get_status($request) {
        global $wpdb;

        // Count posts without meta description
        $posts_without_desc = $wpdb->get_var("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vdl_meta_description'
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");

        // Count posts without title optimization
        $posts_without_title = $wpdb->get_var("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vdl_seo_title'
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");

        // Count posts without featured image
        $posts_without_image = $wpdb->get_var("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");

        // Total published content
        $total_posts = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
            AND post_type IN ('post', 'page')
        ");

        // Calculate score
        $issues = (int) $posts_without_desc + (int) $posts_without_title + (int) $posts_without_image;
        $max_issues = (int) $total_posts * 3;
        $score = $max_issues > 0 ? round((1 - ($issues / $max_issues)) * 100) : 100;

        // Get recent issues
        $recent_issues = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_type, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_vdl_meta_description'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_thumbnail_id'
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND ((pm1.meta_value IS NULL OR pm1.meta_value = '') OR (pm2.meta_value IS NULL OR pm2.meta_value = ''))
            ORDER BY p.post_date DESC
            LIMIT 10
        ");

        return rest_ensure_response(array(
            'success' => true,
            'score'   => $score,
            'stats'   => array(
                'total_content'       => (int) $total_posts,
                'missing_description' => (int) $posts_without_desc,
                'missing_seo_title'   => (int) $posts_without_title,
                'missing_image'       => (int) $posts_without_image,
            ),
            'recent_issues' => $recent_issues,
        ));
    }

    /**
     * Audit a specific page
     */
    public static function audit_page($request) {
        $url = $request->get_param('url');
        $post_id = $request->get_param('post_id');

        if (!$url && !$post_id) {
            return new WP_Error('missing_param', __('URL or post_id is required', 'vdl-agent'), array('status' => 400));
        }

        // Get post by URL or ID
        if ($post_id) {
            $post = get_post($post_id);
        } else {
            $post_id = url_to_postid($url);
            $post = get_post($post_id);
        }

        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found', 'vdl-agent'), array('status' => 404));
        }

        $issues = array();
        $warnings = array();
        $passed = array();

        // Title analysis
        $seo_title = get_post_meta($post_id, '_vdl_seo_title', true) ?: $post->post_title;
        $title_length = mb_strlen($seo_title);

        if ($title_length < 30) {
            $issues[] = array(
                'type'    => 'title_too_short',
                'message' => __('Title is too short (less than 30 characters)', 'vdl-agent'),
                'current' => $title_length,
                'target'  => '50-60',
            );
        } elseif ($title_length > 60) {
            $warnings[] = array(
                'type'    => 'title_too_long',
                'message' => __('Title is too long (more than 60 characters)', 'vdl-agent'),
                'current' => $title_length,
                'target'  => '50-60',
            );
        } else {
            $passed[] = 'title_length';
        }

        // Meta description
        $meta_desc = get_post_meta($post_id, '_vdl_meta_description', true);
        if (empty($meta_desc)) {
            $issues[] = array(
                'type'    => 'missing_meta_description',
                'message' => __('Meta description is missing', 'vdl-agent'),
            );
        } else {
            $desc_length = mb_strlen($meta_desc);
            if ($desc_length < 120) {
                $warnings[] = array(
                    'type'    => 'meta_description_short',
                    'message' => __('Meta description is short', 'vdl-agent'),
                    'current' => $desc_length,
                    'target'  => '150-160',
                );
            } elseif ($desc_length > 160) {
                $warnings[] = array(
                    'type'    => 'meta_description_long',
                    'message' => __('Meta description is too long', 'vdl-agent'),
                    'current' => $desc_length,
                    'target'  => '150-160',
                );
            } else {
                $passed[] = 'meta_description';
            }
        }

        // Featured image
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            $issues[] = array(
                'type'    => 'missing_featured_image',
                'message' => __('Featured image is missing', 'vdl-agent'),
            );
        } else {
            // Check image alt text
            $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
            if (empty($image_alt)) {
                $warnings[] = array(
                    'type'    => 'missing_image_alt',
                    'message' => __('Featured image has no alt text', 'vdl-agent'),
                );
            } else {
                $passed[] = 'featured_image';
            }
        }

        // Content analysis
        $content = wp_strip_all_tags($post->post_content);
        $word_count = str_word_count($content);

        if ($word_count < 300) {
            $warnings[] = array(
                'type'    => 'thin_content',
                'message' => __('Content is thin (less than 300 words)', 'vdl-agent'),
                'current' => $word_count,
                'target'  => '300+',
            );
        } else {
            $passed[] = 'content_length';
        }

        // Check headings structure
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/i', $post->post_content, $headings);
        $h1_count = 0;
        $h2_count = 0;

        foreach ($headings[1] as $level) {
            if ($level == '1') $h1_count++;
            if ($level == '2') $h2_count++;
        }

        if ($h1_count > 1) {
            $warnings[] = array(
                'type'    => 'multiple_h1',
                'message' => __('Multiple H1 tags found in content', 'vdl-agent'),
                'current' => $h1_count,
            );
        }

        if ($h2_count < 2 && $word_count > 500) {
            $warnings[] = array(
                'type'    => 'missing_h2',
                'message' => __('Content lacks subheadings (H2)', 'vdl-agent'),
                'current' => $h2_count,
                'target'  => '2+',
            );
        }

        // Internal links
        preg_match_all('/<a[^>]+href=["\']' . preg_quote(home_url(), '/') . '[^"\']*["\'][^>]*>/i', $post->post_content, $internal_links);
        $internal_link_count = count($internal_links[0]);

        if ($internal_link_count < 2 && $word_count > 500) {
            $warnings[] = array(
                'type'    => 'few_internal_links',
                'message' => __('Few internal links', 'vdl-agent'),
                'current' => $internal_link_count,
                'target'  => '2+',
            );
        } else {
            $passed[] = 'internal_links';
        }

        // Calculate score
        $total_checks = count($issues) + count($warnings) + count($passed);
        $score = $total_checks > 0 ? round((count($passed) / $total_checks) * 100) : 0;

        return rest_ensure_response(array(
            'success'  => true,
            'post_id'  => $post_id,
            'url'      => get_permalink($post_id),
            'title'    => $post->post_title,
            'score'    => $score,
            'issues'   => $issues,
            'warnings' => $warnings,
            'passed'   => $passed,
            'meta'     => array(
                'word_count'     => $word_count,
                'title_length'   => $title_length,
                'desc_length'    => $meta_desc ? mb_strlen($meta_desc) : 0,
                'internal_links' => $internal_link_count,
                'h2_count'       => $h2_count,
            ),
        ));
    }

    /**
     * Get meta data for a post
     */
    public static function get_meta($request) {
        $post_id = (int) $request->get_param('id');

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found', 'vdl-agent'), array('status' => 404));
        }

        $meta = array(
            'seo_title'        => get_post_meta($post_id, '_vdl_seo_title', true),
            'meta_description' => get_post_meta($post_id, '_vdl_meta_description', true),
            'focus_keyword'    => get_post_meta($post_id, '_vdl_focus_keyword', true),
            'canonical_url'    => get_post_meta($post_id, '_vdl_canonical_url', true),
            'noindex'          => get_post_meta($post_id, '_vdl_noindex', true),
            'nofollow'         => get_post_meta($post_id, '_vdl_nofollow', true),
            'og_title'         => get_post_meta($post_id, '_vdl_og_title', true),
            'og_description'   => get_post_meta($post_id, '_vdl_og_description', true),
            'og_image'         => get_post_meta($post_id, '_vdl_og_image', true),
            'twitter_title'    => get_post_meta($post_id, '_vdl_twitter_title', true),
            'twitter_description' => get_post_meta($post_id, '_vdl_twitter_description', true),
        );

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'title'   => $post->post_title,
            'url'     => get_permalink($post_id),
            'meta'    => $meta,
        ));
    }

    /**
     * Update meta data for a post
     */
    public static function update_meta($request) {
        $post_id = (int) $request->get_param('id');

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found', 'vdl-agent'), array('status' => 404));
        }

        $allowed_meta = array(
            'seo_title'        => '_vdl_seo_title',
            'meta_description' => '_vdl_meta_description',
            'focus_keyword'    => '_vdl_focus_keyword',
            'canonical_url'    => '_vdl_canonical_url',
            'noindex'          => '_vdl_noindex',
            'nofollow'         => '_vdl_nofollow',
            'og_title'         => '_vdl_og_title',
            'og_description'   => '_vdl_og_description',
            'og_image'         => '_vdl_og_image',
            'twitter_title'    => '_vdl_twitter_title',
            'twitter_description' => '_vdl_twitter_description',
        );

        $updated = array();

        foreach ($allowed_meta as $param => $meta_key) {
            $value = $request->get_param($param);
            if ($value !== null) {
                if ($param === 'canonical_url' && !empty($value)) {
                    $value = esc_url_raw($value);
                } elseif ($param === 'og_image' && !empty($value)) {
                    $value = esc_url_raw($value);
                } elseif (in_array($param, array('noindex', 'nofollow'))) {
                    $value = $value ? '1' : '';
                } else {
                    $value = sanitize_text_field($value);
                }

                update_post_meta($post_id, $meta_key, $value);
                $updated[$param] = $value;
            }
        }

        if (empty($updated)) {
            return new WP_Error('no_changes', __('No meta data provided', 'vdl-agent'), array('status' => 400));
        }

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'updated' => $updated,
            'message' => __('SEO meta updated successfully', 'vdl-agent'),
        ));
    }

    /**
     * Bulk audit posts
     */
    public static function bulk_audit($request) {
        $post_type = $request->get_param('post_type') ?: 'post';
        $limit = min(50, max(10, (int) $request->get_param('limit') ?: 20));

        $posts = get_posts(array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));

        $results = array();

        foreach ($posts as $post) {
            // Quick audit
            $issues = 0;
            $warnings = 0;

            // Check meta description
            $meta_desc = get_post_meta($post->ID, '_vdl_meta_description', true);
            if (empty($meta_desc)) $issues++;

            // Check featured image
            if (!has_post_thumbnail($post->ID)) $issues++;

            // Check content length
            $word_count = str_word_count(wp_strip_all_tags($post->post_content));
            if ($word_count < 300) $warnings++;

            // Check title length
            $title_length = mb_strlen($post->post_title);
            if ($title_length < 30 || $title_length > 60) $warnings++;

            $results[] = array(
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'url'        => get_permalink($post->ID),
                'date'       => $post->post_date,
                'issues'     => $issues,
                'warnings'   => $warnings,
                'word_count' => $word_count,
            );
        }

        // Sort by issues (most first)
        usort($results, function($a, $b) {
            $a_score = $a['issues'] * 10 + $a['warnings'];
            $b_score = $b['issues'] * 10 + $b['warnings'];
            return $b_score - $a_score;
        });

        return rest_ensure_response(array(
            'success' => true,
            'audited' => count($results),
            'results' => $results,
        ));
    }
}
