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
     * Mapping des meta keys SEO par plugin.
     * Priorité : VDL own > Rank Math > Yoast > All in One SEO > SEOPress
     */
    private static $seo_meta_keys = array(
        'seo_title' => array(
            '_vdl_seo_title',
            'rank_math_title',
            '_yoast_wpseo_title',
            '_aioseo_title',
            '_seopress_titles_title',
        ),
        'meta_description' => array(
            '_vdl_meta_description',
            'rank_math_description',
            '_yoast_wpseo_metadesc',
            '_aioseo_description',
            '_seopress_titles_desc',
        ),
        'focus_keyword' => array(
            '_vdl_focus_keyword',
            'rank_math_focus_keyword',
            '_yoast_wpseo_focuskw',
            '_aioseo_keyphrases',
            '_seopress_analysis_target_kw',
        ),
        'canonical_url' => array(
            '_vdl_canonical_url',
            'rank_math_canonical_url',
            '_yoast_wpseo_canonical',
            '_aioseo_canonical_url',
            '_seopress_robots_canonical',
        ),
        'noindex' => array(
            '_vdl_noindex',
            'rank_math_robots',
            '_yoast_wpseo_meta-robots-noindex',
        ),
        'nofollow' => array(
            '_vdl_nofollow',
            'rank_math_robots',
            '_yoast_wpseo_meta-robots-nofollow',
        ),
        'og_title' => array(
            '_vdl_og_title',
            'rank_math_facebook_title',
            '_yoast_wpseo_opengraph-title',
        ),
        'og_description' => array(
            '_vdl_og_description',
            'rank_math_facebook_description',
            '_yoast_wpseo_opengraph-description',
        ),
        'og_image' => array(
            '_vdl_og_image',
            'rank_math_facebook_image',
            '_yoast_wpseo_opengraph-image',
        ),
        'twitter_title' => array(
            '_vdl_twitter_title',
            'rank_math_twitter_title',
            '_yoast_wpseo_twitter-title',
        ),
        'twitter_description' => array(
            '_vdl_twitter_description',
            'rank_math_twitter_description',
            '_yoast_wpseo_twitter-description',
        ),
    );

    /**
     * Get SEO meta value with fallback across multiple SEO plugins.
     * Returns the first non-empty value found.
     *
     * @param int    $post_id  Post ID
     * @param string $field    Field name (seo_title, meta_description, etc.)
     * @return string
     */
    private static function get_seo_meta($post_id, $field) {
        if (!isset(self::$seo_meta_keys[$field])) {
            return '';
        }

        foreach (self::$seo_meta_keys[$field] as $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (!empty($value)) {
                // Rank Math stores robots as serialized array, handle noindex/nofollow
                if ($meta_key === 'rank_math_robots' && is_array($value)) {
                    if ($field === 'noindex') {
                        return in_array('noindex', $value) ? '1' : '';
                    }
                    if ($field === 'nofollow') {
                        return in_array('nofollow', $value) ? '1' : '';
                    }
                }
                return $value;
            }
        }

        return '';
    }

    /**
     * Detect which SEO plugin is active
     *
     * @return string Plugin slug (rank_math, yoast, aioseo, seopress, vdl, none)
     */
    private static function detect_seo_plugin() {
        if (defined('RANK_MATH_VERSION')) return 'rank_math';
        if (defined('WPSEO_VERSION')) return 'yoast';
        if (defined('AIOSEO_VERSION')) return 'aioseo';
        if (defined('SEOPRESS_VERSION')) return 'seopress';
        return 'vdl';
    }

    /**
     * Get the primary meta_key for description based on active SEO plugin
     *
     * @return string meta_key to use in SQL queries
     */
    private static function get_desc_meta_key() {
        $plugin = self::detect_seo_plugin();
        switch ($plugin) {
            case 'rank_math': return 'rank_math_description';
            case 'yoast':     return '_yoast_wpseo_metadesc';
            case 'aioseo':    return '_aioseo_description';
            case 'seopress':  return '_seopress_titles_desc';
            default:          return '_vdl_meta_description';
        }
    }

    /**
     * Get the primary meta_key for SEO title based on active SEO plugin
     *
     * @return string meta_key to use in SQL queries
     */
    private static function get_title_meta_key() {
        $plugin = self::detect_seo_plugin();
        switch ($plugin) {
            case 'rank_math': return 'rank_math_title';
            case 'yoast':     return '_yoast_wpseo_title';
            case 'aioseo':    return '_aioseo_title';
            case 'seopress':  return '_seopress_titles_title';
            default:          return '_vdl_seo_title';
        }
    }

    /**
     * Get SEO status overview
     */
    public static function get_status($request) {
        global $wpdb;

        $desc_key = self::get_desc_meta_key();
        $title_key = self::get_title_meta_key();

        // Count posts without meta description
        $posts_without_desc = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ", $desc_key));

        // Count posts without title optimization
        $posts_without_title = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ", $title_key));

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
        $recent_issues = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_type, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_thumbnail_id'
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND ((pm1.meta_value IS NULL OR pm1.meta_value = '') OR (pm2.meta_value IS NULL OR pm2.meta_value = ''))
            ORDER BY p.post_date DESC
            LIMIT 10
        ", $desc_key));

        return rest_ensure_response(array(
            'success'    => true,
            'score'      => $score,
            'seo_plugin' => self::detect_seo_plugin(),
            'stats'      => array(
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
        $seo_title = self::get_seo_meta($post_id, 'seo_title') ?: $post->post_title;
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
        $meta_desc = self::get_seo_meta($post_id, 'meta_description');
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
            'seo_title'           => self::get_seo_meta($post_id, 'seo_title'),
            'meta_description'    => self::get_seo_meta($post_id, 'meta_description'),
            'focus_keyword'       => self::get_seo_meta($post_id, 'focus_keyword'),
            'canonical_url'       => self::get_seo_meta($post_id, 'canonical_url'),
            'noindex'             => self::get_seo_meta($post_id, 'noindex'),
            'nofollow'            => self::get_seo_meta($post_id, 'nofollow'),
            'og_title'            => self::get_seo_meta($post_id, 'og_title'),
            'og_description'      => self::get_seo_meta($post_id, 'og_description'),
            'og_image'            => self::get_seo_meta($post_id, 'og_image'),
            'twitter_title'       => self::get_seo_meta($post_id, 'twitter_title'),
            'twitter_description' => self::get_seo_meta($post_id, 'twitter_description'),
            'source_plugin'       => self::detect_seo_plugin(),
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
     * Debug: dump all SEO-related post_meta for a given post.
     * Temporary endpoint to diagnose meta key issues.
     */
    public static function debug_meta($request) {
        global $wpdb;

        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }

        // Get ALL post meta for this post
        $all_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key",
            $post_id
        ));

        // Filter to SEO-related keys
        $seo_keywords = array('rank_math', 'yoast', 'wpseo', 'aioseo', 'seopress', '_vdl_', 'seo', 'meta_desc', 'focus', 'canonical', 'noindex', 'nofollow', 'og_', 'twitter_');
        $seo_meta = array();
        $all_keys = array();

        foreach ($all_meta as $row) {
            $all_keys[] = $row->meta_key;
            foreach ($seo_keywords as $keyword) {
                if (stripos($row->meta_key, $keyword) !== false) {
                    $value = $row->meta_value;
                    // Truncate long values
                    if (strlen($value) > 500) {
                        $value = substr($value, 0, 500) . '... [truncated]';
                    }
                    $seo_meta[$row->meta_key] = $value;
                    break;
                }
            }
        }

        // Also try get_post_meta for the specific keys we use
        $our_reads = array();
        foreach (self::$seo_meta_keys as $field => $keys) {
            foreach ($keys as $meta_key) {
                $val = get_post_meta($post_id, $meta_key, true);
                if ($val !== '' && $val !== false && $val !== null) {
                    $our_reads[$field . ' (' . $meta_key . ')'] = is_array($val) ? json_encode($val) : (string) $val;
                }
            }
        }

        return rest_ensure_response(array(
            'success'          => true,
            'post_id'          => $post_id,
            'title'            => $post->post_title,
            'detected_plugin'  => self::detect_seo_plugin(),
            'seo_related_meta' => $seo_meta,
            'our_fallback_reads' => $our_reads,
            'total_meta_keys'  => count($all_keys),
            'all_meta_keys'    => $all_keys,
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
            $meta_desc = self::get_seo_meta($post->ID, 'meta_description');
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
