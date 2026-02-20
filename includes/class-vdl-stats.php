<?php
/**
 * VDL Agent Stats
 *
 * Gestion des statistiques VDL (clics, CTR, etc.)
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Stats {

    /**
     * Get stats overview
     */
    public static function get_overview($request) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';
        $clicks_table = $wpdb->prefix . 'vdl_clicks_log';

        // Period filter
        $period = $request->get_param('period') ?: '30days';
        $date_filter = self::get_date_filter($period);

        // Total links
        $total_links = $wpdb->get_var("SELECT COUNT(*) FROM $stats_table");

        // Total clicks
        $total_clicks = $wpdb->get_var("SELECT SUM(clicks) FROM $stats_table");

        // Clicks in period
        $period_clicks = 0;
        if ($date_filter) {
            $period_clicks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $clicks_table WHERE clicked_at >= %s",
                $date_filter
            ));
        }

        // Total impressions (articles with VDL links)
        $total_impressions = $wpdb->get_var("SELECT SUM(impressions) FROM $stats_table");

        // CTR calculation
        $ctr = 0;
        if ($total_impressions > 0) {
            $ctr = round(($total_clicks / $total_impressions) * 100, 2);
        }

        // Top performing links
        $top_links = $wpdb->get_results($wpdb->prepare(
            "SELECT link_url, link_anchor, clicks, post_id
             FROM $stats_table
             ORDER BY clicks DESC
             LIMIT %d",
            5
        ));

        // Top performing articles
        $top_articles = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, SUM(clicks) as total_clicks, COUNT(*) as link_count
             FROM $stats_table
             GROUP BY post_id
             ORDER BY total_clicks DESC
             LIMIT %d",
            5
        ));

        // Enrich with post titles
        foreach ($top_articles as &$article) {
            $article->post_title = get_the_title($article->post_id);
            $article->post_url = get_permalink($article->post_id);
        }

        // Daily clicks trend (last 30 days)
        $daily_trend = $wpdb->get_results(
            "SELECT DATE(clicked_at) as date, COUNT(*) as clicks
             FROM $clicks_table
             WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(clicked_at)
             ORDER BY date ASC"
        );

        return rest_ensure_response(array(
            'success' => true,
            'overview' => array(
                'total_links'       => (int) $total_links,
                'total_clicks'      => (int) $total_clicks,
                'period_clicks'     => (int) $period_clicks,
                'total_impressions' => (int) $total_impressions,
                'ctr'               => $ctr,
                'period'            => $period,
            ),
            'top_links'    => $top_links,
            'top_articles' => $top_articles,
            'daily_trend'  => $daily_trend,
        ));
    }

    /**
     * Get stats by link
     */
    public static function get_links_stats($request) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(10, (int) $request->get_param('per_page') ?: 20));
        $offset = ($page - 1) * $per_page;

        $order_by = $request->get_param('order_by') ?: 'clicks';
        $order = strtoupper($request->get_param('order') ?: 'DESC');

        // Validate order_by
        $allowed_order_by = array('clicks', 'impressions', 'created_at', 'link_url');
        if (!in_array($order_by, $allowed_order_by)) {
            $order_by = 'clicks';
        }

        if (!in_array($order, array('ASC', 'DESC'))) {
            $order = 'DESC';
        }

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $stats_table");

        // Get links
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT *,
                    CASE WHEN impressions > 0 THEN ROUND((clicks / impressions) * 100, 2) ELSE 0 END as ctr
             FROM $stats_table
             ORDER BY $order_by $order
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        // Enrich with post data
        foreach ($links as &$link) {
            $link->post_title = get_the_title($link->post_id);
            $link->post_url = get_permalink($link->post_id);
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
     * Get stats by article
     */
    public static function get_articles_stats($request) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(10, (int) $request->get_param('per_page') ?: 20));
        $offset = ($page - 1) * $per_page;

        // Get articles with VDL links
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id,
                    COUNT(*) as link_count,
                    SUM(clicks) as total_clicks,
                    SUM(impressions) as total_impressions,
                    CASE WHEN SUM(impressions) > 0
                         THEN ROUND((SUM(clicks) / SUM(impressions)) * 100, 2)
                         ELSE 0 END as ctr
             FROM $stats_table
             GROUP BY post_id
             ORDER BY total_clicks DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM $stats_table");

        // Enrich with post data
        foreach ($articles as &$article) {
            $article->post_title = get_the_title($article->post_id);
            $article->post_url = get_permalink($article->post_id);
            $article->post_date = get_the_date('c', $article->post_id);

            // Get links for this article
            $article->links = $wpdb->get_results($wpdb->prepare(
                "SELECT link_url, link_anchor, link_rel, clicks
                 FROM $stats_table
                 WHERE post_id = %d
                 ORDER BY clicks DESC",
                $article->post_id
            ));
        }

        return rest_ensure_response(array(
            'success'  => true,
            'articles' => $articles,
            'pagination' => array(
                'page'       => $page,
                'per_page'   => $per_page,
                'total'      => (int) $total,
                'total_pages' => ceil($total / $per_page),
            ),
        ));
    }

    /**
     * Get stats for a specific period
     */
    public static function get_period_stats($request) {
        global $wpdb;

        $clicks_table = $wpdb->prefix . 'vdl_clicks_log';
        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        if (empty($start_date) || empty($end_date)) {
            // Default to last 30 days
            $end_date = current_time('Y-m-d');
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }

        // Validate dates
        if (!self::validate_date($start_date) || !self::validate_date($end_date)) {
            return new WP_Error('invalid_date', __('Invalid date format. Use YYYY-MM-DD', 'vdl-agent'), array('status' => 400));
        }

        // Get daily stats
        $daily_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(clicked_at) as date, COUNT(*) as clicks
             FROM $clicks_table
             WHERE clicked_at >= %s AND clicked_at <= %s
             GROUP BY DATE(clicked_at)
             ORDER BY date ASC",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Get totals for period
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as clicks,
                    COUNT(DISTINCT link_id) as unique_links,
                    COUNT(DISTINCT post_id) as unique_articles
             FROM $clicks_table
             WHERE clicked_at >= %s AND clicked_at <= %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Top links for period
        $top_links = $wpdb->get_results($wpdb->prepare(
            "SELECT l.link_id, s.link_url, s.link_anchor, COUNT(*) as clicks
             FROM $clicks_table l
             JOIN $stats_table s ON l.link_id = s.id
             WHERE l.clicked_at >= %s AND l.clicked_at <= %s
             GROUP BY l.link_id
             ORDER BY clicks DESC
             LIMIT 10",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        return rest_ensure_response(array(
            'success' => true,
            'period' => array(
                'start_date' => $start_date,
                'end_date'   => $end_date,
            ),
            'totals'      => $totals,
            'daily_stats' => $daily_stats,
            'top_links'   => $top_links,
        ));
    }

    /**
     * Export stats as CSV
     */
    public static function export_csv($request) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        $type = $request->get_param('type') ?: 'links';

        if ($type === 'links') {
            $data = $wpdb->get_results(
                "SELECT s.*, p.post_title
                 FROM $stats_table s
                 LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 ORDER BY s.clicks DESC",
                ARRAY_A
            );

            $headers = array('ID', 'Post ID', 'Post Title', 'Link URL', 'Anchor', 'Rel', 'Clicks', 'Impressions', 'Created', 'Updated');

        } else {
            $data = $wpdb->get_results(
                "SELECT post_id, COUNT(*) as link_count, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions
                 FROM $stats_table
                 GROUP BY post_id
                 ORDER BY total_clicks DESC",
                ARRAY_A
            );

            foreach ($data as &$row) {
                $row['post_title'] = get_the_title($row['post_id']);
            }

            $headers = array('Post ID', 'Post Title', 'Link Count', 'Total Clicks', 'Total Impressions');
        }

        // Generate CSV content
        $csv_content = implode(';', $headers) . "\n";

        foreach ($data as $row) {
            $csv_content .= implode(';', array_values($row)) . "\n";
        }

        // Return as base64 encoded for API response
        return rest_ensure_response(array(
            'success'  => true,
            'filename' => 'vdl-stats-' . $type . '-' . date('Y-m-d') . '.csv',
            'content'  => base64_encode($csv_content),
            'rows'     => count($data),
        ));
    }

    /**
     * Get date filter based on period
     */
    private static function get_date_filter($period) {
        switch ($period) {
            case '7days':
                return date('Y-m-d H:i:s', strtotime('-7 days'));
            case '30days':
                return date('Y-m-d H:i:s', strtotime('-30 days'));
            case '90days':
                return date('Y-m-d H:i:s', strtotime('-90 days'));
            case 'year':
                return date('Y-m-d H:i:s', strtotime('-1 year'));
            case 'all':
            default:
                return null;
        }
    }

    /**
     * Validate date format
     */
    private static function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
