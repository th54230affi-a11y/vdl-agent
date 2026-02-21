<?php
/**
 * Plugin Name: VDL Agent
 * Plugin URI: https://github.com/th54230affi-a11y/vdl-agent
 * Description: Agent API pour la gestion à distance des sites VDL (Vente De Liens) - Stats, Liens, Thème, Maintenance
 * Version: 1.4.2
 * Author: VDL Tech
 * Author URI: https://vdl-tech.fr
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vdl-agent
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('VDL_AGENT_VERSION', '1.4.2');
define('VDL_AGENT_PATH', plugin_dir_path(__FILE__));
define('VDL_AGENT_URL', plugin_dir_url(__FILE__));
define('VDL_AGENT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VDL_AGENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VDL_AGENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
final class VDL_Agent {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-auth.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-api.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-theme.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-stats.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-links.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-maintenance.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-seo.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-content.php';
        require_once VDL_AGENT_PLUGIN_DIR . 'includes/class-vdl-updater.php';

        // Admin
        if (is_admin()) {
            require_once VDL_AGENT_PLUGIN_DIR . 'admin/class-vdl-admin.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize API
        add_action('rest_api_init', array('VDL_API', 'register_routes'));

        // Initialize Admin
        if (is_admin()) {
            add_action('admin_menu', array('VDL_Admin', 'add_menu_pages'));
            add_action('admin_init', array('VDL_Admin', 'register_settings'));
            add_action('admin_enqueue_scripts', array('VDL_Admin', 'enqueue_assets'));
        }

        // Load translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // GitHub auto-updater
        new VDL_Updater(VDL_AGENT_PLUGIN_BASENAME, VDL_AGENT_VERSION);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Generate API key if not exists
        if (!get_option('vdl_agent_api_key')) {
            update_option('vdl_agent_api_key', wp_generate_password(32, false));
        }

        // Generate confirm token if not exists
        if (!get_option('vdl_agent_confirm_token')) {
            update_option('vdl_agent_confirm_token', wp_generate_password(16, false));
        }

        // Create stats table if needed (uses theme's table)
        $this->maybe_create_tables();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Maybe create database tables
     */
    private function maybe_create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // VDL Link Stats table (if not exists from theme)
        $table_name = $wpdb->prefix . 'vdl_link_stats';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                link_url varchar(500) NOT NULL,
                link_anchor varchar(255) NOT NULL,
                link_rel varchar(50) DEFAULT 'dofollow',
                clicks int(11) DEFAULT 0,
                impressions int(11) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY post_id (post_id),
                KEY link_url (link_url(191))
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        // VDL Clicks Log table
        $log_table = $wpdb->prefix . 'vdl_clicks_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") != $log_table) {
            $sql = "CREATE TABLE $log_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                link_id bigint(20) NOT NULL,
                post_id bigint(20) NOT NULL,
                clicked_at datetime DEFAULT CURRENT_TIMESTAMP,
                ip_address varchar(45) DEFAULT NULL,
                user_agent varchar(500) DEFAULT NULL,
                referer varchar(500) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY link_id (link_id),
                KEY post_id (post_id),
                KEY clicked_at (clicked_at)
            ) $charset_collate;";

            dbDelta($sql);
        }
    }

    /**
     * Load textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'vdl-agent',
            false,
            dirname(VDL_AGENT_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Get API Key
     */
    public static function get_api_key() {
        return get_option('vdl_agent_api_key', '');
    }

    /**
     * Get Confirm Token
     */
    public static function get_confirm_token() {
        return get_option('vdl_agent_confirm_token', '');
    }
}

/**
 * Initialize plugin
 */
function vdl_agent_init() {
    return VDL_Agent::get_instance();
}

// Run
vdl_agent_init();
