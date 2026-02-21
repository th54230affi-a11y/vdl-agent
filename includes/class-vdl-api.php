<?php
/**
 * VDL Agent API
 *
 * Enregistrement et gestion des routes API REST
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'vdl/v1';

    /**
     * Register all API routes
     */
    public static function register_routes() {
        // Check if IP is blocked
        if (VDL_Auth::is_ip_blocked()) {
            return;
        }

        // ===================
        // HEALTH & CONFIG
        // ===================
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'health_check'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/config', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'get_config'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/identity', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'get_identity'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        // ===================
        // THEME ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/theme/files', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Theme', 'list_files'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/theme/file', array(
            array(
                'methods'             => 'GET',
                'callback'            => array('VDL_Theme', 'read_file'),
                'permission_callback' => array('VDL_Auth', 'check_api_key'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array('VDL_Theme', 'write_file'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/theme/search-replace', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Theme', 'search_replace'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        register_rest_route(self::NAMESPACE, '/theme/backups', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Theme', 'list_backups'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/theme/backup/(?P<id>[a-zA-Z0-9_-]+)/restore', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Theme', 'restore_backup'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        register_rest_route(self::NAMESPACE, '/theme/deploy', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Theme', 'deploy_theme'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        // ===================
        // STATS ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/stats/overview', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Stats', 'get_overview'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/stats/links', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Stats', 'get_links_stats'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/stats/articles', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Stats', 'get_articles_stats'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/stats/period', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Stats', 'get_period_stats'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/stats/export', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Stats', 'export_csv'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        // ===================
        // LINKS ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/links', array(
            array(
                'methods'             => 'GET',
                'callback'            => array('VDL_Links', 'list_links'),
                'permission_callback' => array('VDL_Auth', 'check_api_key'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array('VDL_Links', 'add_link'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/links/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array('VDL_Links', 'get_link'),
                'permission_callback' => array('VDL_Auth', 'check_api_key'),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array('VDL_Links', 'update_link'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array('VDL_Links', 'delete_link'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/links/bulk', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Links', 'bulk_action'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        // ===================
        // SEO ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/seo/status', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_SEO', 'get_status'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/seo/audit', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_SEO', 'audit_page'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/seo/debug-meta/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_SEO', 'debug_meta'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/seo/meta/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array('VDL_SEO', 'get_meta'),
                'permission_callback' => array('VDL_Auth', 'check_api_key'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array('VDL_SEO', 'update_meta'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
        ));

        // ===================
        // MAINTENANCE ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/plugins', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Maintenance', 'list_plugins'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/plugins/updates', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Maintenance', 'list_updates'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        register_rest_route(self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9-]+)/update', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Maintenance', 'update_plugin'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        register_rest_route(self::NAMESPACE, '/cache/purge', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Maintenance', 'purge_cache'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        // ===================
        // CONTENT ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/posts', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Content', 'list_posts'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        // Routes fixes AVANT la route avec regex
        register_rest_route(self::NAMESPACE, '/posts/search-replace', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Content', 'search_replace_content'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        register_rest_route(self::NAMESPACE, '/posts/bulk-delete', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Content', 'bulk_delete'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        // Route avec paramètre ID (après les routes fixes)
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array('VDL_Content', 'get_post'),
                'permission_callback' => array('VDL_Auth', 'check_api_key'),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array('VDL_Content', 'update_post'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array('VDL_Content', 'delete_post'),
                'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/categories', array(
            'methods'             => 'GET',
            'callback'            => array('VDL_Content', 'list_categories'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        // ===================
        // WEBHOOK ENDPOINTS
        // ===================
        register_rest_route(self::NAMESPACE, '/webhook/wisewand', array(
            'methods'             => 'POST',
            'callback'            => array('VDL_Webhook', 'handle_wisewand'),
            'permission_callback' => array('VDL_Webhook', 'check_webhook_auth'),
        ));
    }

    /**
     * Health check endpoint
     */
    public static function health_check($request) {
        global $wpdb;

        $theme = wp_get_theme();
        $child_theme = $theme->parent() ? $theme : null;

        // Get update counts
        $update_plugins = get_site_transient('update_plugins');
        $plugin_updates = isset($update_plugins->response) ? count($update_plugins->response) : 0;

        $update_themes = get_site_transient('update_themes');
        $theme_updates = isset($update_themes->response) ? count($update_themes->response) : 0;

        // Get VDL stats
        $stats_table = $wpdb->prefix . 'vdl_link_stats';
        $total_links = $wpdb->get_var("SELECT COUNT(*) FROM $stats_table");
        $total_clicks = $wpdb->get_var("SELECT SUM(clicks) FROM $stats_table");

        return rest_ensure_response(array(
            'success' => true,
            'status'  => 'healthy',
            'site'    => array(
                'name'    => get_bloginfo('name'),
                'url'     => home_url(),
                'wp'      => get_bloginfo('version'),
                'php'     => phpversion(),
            ),
            'agent'   => array(
                'version' => VDL_AGENT_VERSION,
            ),
            'theme'   => array(
                'name'        => $theme->get('Name'),
                'version'     => $theme->get('Version'),
                'child_theme' => $child_theme ? $child_theme->get('Name') : null,
            ),
            'updates' => array(
                'plugins' => $plugin_updates,
                'themes'  => $theme_updates,
            ),
            'vdl'     => array(
                'total_links'  => (int) $total_links,
                'total_clicks' => (int) $total_clicks,
            ),
            'timestamp' => current_time('c'),
        ));
    }

    /**
     * Get configuration
     */
    public static function get_config($request) {
        return rest_ensure_response(array(
            'success' => true,
            'config'  => array(
                'site_url'        => home_url(),
                'admin_email'     => get_option('admin_email'),
                'timezone'        => wp_timezone_string(),
                'date_format'     => get_option('date_format'),
                'time_format'     => get_option('time_format'),
                'posts_per_page'  => get_option('posts_per_page'),
                'permalink'       => get_option('permalink_structure'),
                'active_plugins'  => get_option('active_plugins'),
            ),
        ));
    }

    /**
     * Get site identity
     */
    public static function get_identity($request) {
        $theme = wp_get_theme();

        return rest_ensure_response(array(
            'success'  => true,
            'identity' => array(
                'name'        => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'url'         => home_url(),
                'admin_url'   => admin_url(),
                'theme'       => $theme->get('Name'),
                'language'    => get_bloginfo('language'),
            ),
        ));
    }
}
