<?php
/**
 * VDL Agent Admin
 *
 * Page d'administration et settings
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Admin {

    /**
     * Init admin hooks
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    /**
     * Add admin menu pages
     */
    public static function add_menu_pages() {
        // Main menu
        add_menu_page(
            __('VDL Agent', 'vdl-agent'),
            __('VDL Agent', 'vdl-agent'),
            'manage_options',
            'vdl-agent',
            array(__CLASS__, 'render_dashboard'),
            'dashicons-admin-links',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'vdl-agent',
            __('Dashboard', 'vdl-agent'),
            __('Dashboard', 'vdl-agent'),
            'manage_options',
            'vdl-agent',
            array(__CLASS__, 'render_dashboard')
        );

        // Settings submenu
        add_submenu_page(
            'vdl-agent',
            __('Settings', 'vdl-agent'),
            __('Settings', 'vdl-agent'),
            'manage_options',
            'vdl-agent-settings',
            array(__CLASS__, 'render_settings')
        );

        // API Keys submenu
        add_submenu_page(
            'vdl-agent',
            __('API Keys', 'vdl-agent'),
            __('API Keys', 'vdl-agent'),
            'manage_options',
            'vdl-agent-keys',
            array(__CLASS__, 'render_api_keys')
        );
    }

    /**
     * Register settings
     */
    public static function register_settings() {
        register_setting('vdl_agent_settings', 'vdl_agent_api_key');
        register_setting('vdl_agent_settings', 'vdl_agent_confirm_token');
        register_setting('vdl_agent_settings', 'vdl_agent_allowed_ips');
        register_setting('vdl_agent_settings', 'vdl_agent_rate_limit');
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if (strpos($hook, 'vdl-agent') === false) {
            return;
        }

        wp_enqueue_style(
            'vdl-agent-admin',
            VDL_AGENT_URL . 'admin/css/admin.css',
            array(),
            VDL_AGENT_VERSION
        );

        wp_enqueue_script(
            'vdl-agent-admin',
            VDL_AGENT_URL . 'assets/js/admin.js',
            array('jquery'),
            VDL_AGENT_VERSION,
            true
        );

        wp_localize_script('vdl-agent-admin', 'vdlAgent', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('vdl_agent_nonce'),
            'apiUrl'   => rest_url('vdl/v1/'),
            'strings'  => array(
                'confirm'  => __('Are you sure?', 'vdl-agent'),
                'copied'   => __('Copied!', 'vdl-agent'),
                'error'    => __('Error', 'vdl-agent'),
            ),
        ));
    }

    /**
     * Render dashboard page
     */
    public static function render_dashboard() {
        include VDL_AGENT_PATH . 'admin/views/dashboard.php';
    }

    /**
     * Render settings page
     */
    public static function render_settings() {
        include VDL_AGENT_PATH . 'admin/views/settings.php';
    }

    /**
     * Render API keys page
     */
    public static function render_api_keys() {
        // Generate keys if not exist
        $api_key = get_option('vdl_agent_api_key');
        $confirm_token = get_option('vdl_agent_confirm_token');

        if (empty($api_key)) {
            $api_key = self::generate_key();
            update_option('vdl_agent_api_key', $api_key);
        }

        if (empty($confirm_token)) {
            $confirm_token = self::generate_key();
            update_option('vdl_agent_confirm_token', $confirm_token);
        }

        $webhook_secret = VDL_Webhook::get_or_create_secret();

        // Handle regenerate
        if (isset($_POST['vdl_regenerate_webhook_secret']) && wp_verify_nonce($_POST['_wpnonce'], 'vdl_regenerate_keys')) {
            $webhook_secret = wp_generate_password(32, false);
            update_option('vdl_agent_webhook_secret', $webhook_secret);
            add_settings_error('vdl_agent', 'webhook_secret_regenerated', __('Webhook Secret regenerated', 'vdl-agent'), 'success');
        }

        if (isset($_POST['vdl_regenerate_api_key']) && wp_verify_nonce($_POST['_wpnonce'], 'vdl_regenerate_keys')) {
            $api_key = self::generate_key();
            update_option('vdl_agent_api_key', $api_key);
            add_settings_error('vdl_agent', 'api_key_regenerated', __('API Key regenerated', 'vdl-agent'), 'success');
        }

        if (isset($_POST['vdl_regenerate_confirm_token']) && wp_verify_nonce($_POST['_wpnonce'], 'vdl_regenerate_keys')) {
            $confirm_token = self::generate_key();
            update_option('vdl_agent_confirm_token', $confirm_token);
            add_settings_error('vdl_agent', 'confirm_token_regenerated', __('Confirm Token regenerated', 'vdl-agent'), 'success');
        }
        ?>
        <div class="wrap vdl-admin">
            <h1><?php _e('VDL Agent - API Keys', 'vdl-agent'); ?></h1>

            <?php settings_errors('vdl_agent'); ?>

            <div class="vdl-card">
                <h2><?php _e('API Authentication', 'vdl-agent'); ?></h2>
                <p><?php _e('Use these keys to authenticate API requests from Claude Code or other tools.', 'vdl-agent'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'vdl-agent'); ?></th>
                        <td>
                            <div class="vdl-key-field">
                                <input type="text" readonly value="<?php echo esc_attr($api_key); ?>" class="regular-text code" id="vdl-api-key">
                                <button type="button" class="button vdl-copy-btn" data-target="vdl-api-key">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                            <p class="description"><?php _e('Used for read-only operations (GET requests)', 'vdl-agent'); ?></p>
                            <form method="post" style="margin-top: 10px;">
                                <?php wp_nonce_field('vdl_regenerate_keys'); ?>
                                <button type="submit" name="vdl_regenerate_api_key" class="button">
                                    <?php _e('Regenerate API Key', 'vdl-agent'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Confirm Token', 'vdl-agent'); ?></th>
                        <td>
                            <div class="vdl-key-field">
                                <input type="text" readonly value="<?php echo esc_attr($confirm_token); ?>" class="regular-text code" id="vdl-confirm-token">
                                <button type="button" class="button vdl-copy-btn" data-target="vdl-confirm-token">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                            <p class="description"><?php _e('Required for write operations (POST, PUT, DELETE)', 'vdl-agent'); ?></p>
                            <form method="post" style="margin-top: 10px;">
                                <?php wp_nonce_field('vdl_regenerate_keys'); ?>
                                <button type="submit" name="vdl_regenerate_confirm_token" class="button">
                                    <?php _e('Regenerate Confirm Token', 'vdl-agent'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Webhook Secret', 'vdl-agent'); ?></th>
                        <td>
                            <div class="vdl-key-field">
                                <input type="text" readonly value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text code" id="vdl-webhook-secret">
                                <button type="button" class="button vdl-copy-btn" data-target="vdl-webhook-secret">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                            <p class="description">
                                <?php _e('Used as password for WiseWand webhook connection.', 'vdl-agent'); ?><br>
                                <?php printf(__('Webhook URL: %s', 'vdl-agent'), '<code>' . esc_html(rest_url('vdl/v1/webhook/wisewand')) . '</code>'); ?>
                            </p>
                            <form method="post" style="margin-top: 10px;">
                                <?php wp_nonce_field('vdl_regenerate_keys'); ?>
                                <button type="submit" name="vdl_regenerate_webhook_secret" class="button">
                                    <?php _e('Regenerate Webhook Secret', 'vdl-agent'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="vdl-card">
                <h2><?php _e('Configuration Example', 'vdl-agent'); ?></h2>
                <p><?php _e('Add this to your MCP configuration (Claude Code):', 'vdl-agent'); ?></p>
                <pre class="vdl-code-block">{
  "mcpServers": {
    "vdl": {
      "command": "node",
      "args": ["path/to/mcp-vdl-agent/dist/index.js"],
      "env": {
        "VDL_SITES": "[{\"name\":\"<?php echo esc_js(get_bloginfo('name')); ?>\",\"url\":\"<?php echo esc_js(home_url()); ?>\",\"apiKey\":\"<?php echo esc_js($api_key); ?>\",\"confirmToken\":\"<?php echo esc_js($confirm_token); ?>\"}]"
      }
    }
  }
}</pre>
            </div>

            <div class="vdl-card">
                <h2><?php _e('API Endpoints', 'vdl-agent'); ?></h2>
                <p><?php _e('Base URL:', 'vdl-agent'); ?> <code><?php echo esc_html(rest_url('vdl/v1/')); ?></code></p>

                <h3><?php _e('Available Endpoints', 'vdl-agent'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Endpoint', 'vdl-agent'); ?></th>
                            <th><?php _e('Method', 'vdl-agent'); ?></th>
                            <th><?php _e('Description', 'vdl-agent'); ?></th>
                            <th><?php _e('Auth', 'vdl-agent'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/health</code></td>
                            <td>GET</td>
                            <td><?php _e('Health check', 'vdl-agent'); ?></td>
                            <td>API Key</td>
                        </tr>
                        <tr>
                            <td><code>/stats/overview</code></td>
                            <td>GET</td>
                            <td><?php _e('VDL stats overview', 'vdl-agent'); ?></td>
                            <td>API Key</td>
                        </tr>
                        <tr>
                            <td><code>/links</code></td>
                            <td>GET/POST</td>
                            <td><?php _e('List/Add VDL links', 'vdl-agent'); ?></td>
                            <td>API Key / Confirm</td>
                        </tr>
                        <tr>
                            <td><code>/theme/file</code></td>
                            <td>GET/POST</td>
                            <td><?php _e('Read/Write theme files', 'vdl-agent'); ?></td>
                            <td>API Key / Confirm</td>
                        </tr>
                        <tr>
                            <td><code>/plugins</code></td>
                            <td>GET</td>
                            <td><?php _e('List plugins', 'vdl-agent'); ?></td>
                            <td>API Key</td>
                        </tr>
                        <tr>
                            <td><code>/cache/purge</code></td>
                            <td>POST</td>
                            <td><?php _e('Purge cache', 'vdl-agent'); ?></td>
                            <td>API Key</td>
                        </tr>
                        <tr>
                            <td><code>/webhook/wisewand</code></td>
                            <td>POST</td>
                            <td><?php _e('WiseWand webhook — auto-sync SEO meta', 'vdl-agent'); ?></td>
                            <td>Webhook Secret</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Generate a secure random key
     */
    private static function generate_key() {
        return 'vdl_' . bin2hex(random_bytes(24));
    }

    /**
     * Get dashboard stats
     */
    public static function get_dashboard_stats() {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'vdl_link_stats';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$stats_table'") === $stats_table;

        if (!$table_exists) {
            return array(
                'total_links'  => 0,
                'total_clicks' => 0,
                'avg_ctr'      => 0,
                'top_links'    => array(),
                'top_articles' => array(),
                'recent'       => array(),
            );
        }

        // Total stats
        $totals = $wpdb->get_row("
            SELECT
                COUNT(*) as total_links,
                COALESCE(SUM(clicks), 0) as total_clicks,
                COALESCE(SUM(impressions), 0) as total_impressions
            FROM $stats_table
        ");

        $avg_ctr = $totals->total_impressions > 0
            ? round(($totals->total_clicks / $totals->total_impressions) * 100, 2)
            : 0;

        // Top links
        $top_links = $wpdb->get_results("
            SELECT link_url, link_anchor, clicks, impressions
            FROM $stats_table
            ORDER BY clicks DESC
            LIMIT 5
        ");

        // Top articles
        $top_articles = $wpdb->get_results("
            SELECT
                post_id,
                SUM(clicks) as total_clicks,
                COUNT(*) as link_count
            FROM $stats_table
            GROUP BY post_id
            ORDER BY total_clicks DESC
            LIMIT 5
        ");

        foreach ($top_articles as &$article) {
            $article->title = get_the_title($article->post_id);
            $article->url = get_permalink($article->post_id);
        }

        // Recent clicks (last 7 days)
        $clicks_table = $wpdb->prefix . 'vdl_clicks_log';
        $recent = array();

        if ($wpdb->get_var("SHOW TABLES LIKE '$clicks_table'") === $clicks_table) {
            $recent = $wpdb->get_results("
                SELECT DATE(clicked_at) as date, COUNT(*) as clicks
                FROM $clicks_table
                WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(clicked_at)
                ORDER BY date ASC
            ");
        }

        return array(
            'total_links'  => (int) $totals->total_links,
            'total_clicks' => (int) $totals->total_clicks,
            'avg_ctr'      => $avg_ctr,
            'top_links'    => $top_links,
            'top_articles' => $top_articles,
            'recent'       => $recent,
        );
    }
}
