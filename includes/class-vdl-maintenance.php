<?php
/**
 * VDL Agent Maintenance
 *
 * Gestion des plugins, cache et mises à jour
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Maintenance {

    /**
     * List all plugins
     */
    public static function list_plugins($request) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        $plugins = array();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugins[] = array(
                'file'        => $plugin_file,
                'name'        => $plugin_data['Name'],
                'version'     => $plugin_data['Version'],
                'author'      => $plugin_data['Author'],
                'description' => $plugin_data['Description'],
                'active'      => in_array($plugin_file, $active_plugins),
                'slug'        => dirname($plugin_file),
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'plugins' => $plugins,
            'count'   => count($plugins),
            'active'  => count($active_plugins),
        ));
    }

    /**
     * List available updates
     */
    public static function list_updates($request) {
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        // Force check for updates
        wp_update_plugins();

        $update_plugins = get_site_transient('update_plugins');
        $updates = array();

        if (isset($update_plugins->response) && is_array($update_plugins->response)) {
            foreach ($update_plugins->response as $plugin_file => $plugin_data) {
                $plugin_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                $updates[] = array(
                    'file'            => $plugin_file,
                    'name'            => $plugin_info['Name'],
                    'current_version' => $plugin_info['Version'],
                    'new_version'     => $plugin_data->new_version,
                    'slug'            => $plugin_data->slug,
                    'package'         => isset($plugin_data->package) ? $plugin_data->package : null,
                );
            }
        }

        // Check theme updates
        wp_update_themes();
        $update_themes = get_site_transient('update_themes');
        $theme_updates = array();

        if (isset($update_themes->response) && is_array($update_themes->response)) {
            foreach ($update_themes->response as $theme_slug => $theme_data) {
                $theme = wp_get_theme($theme_slug);
                $theme_updates[] = array(
                    'slug'            => $theme_slug,
                    'name'            => $theme->get('Name'),
                    'current_version' => $theme->get('Version'),
                    'new_version'     => $theme_data['new_version'],
                );
            }
        }

        // Check core updates
        wp_version_check();
        $core_updates = get_site_transient('update_core');
        $core_update = null;

        if (isset($core_updates->updates[0]) && $core_updates->updates[0]->response === 'upgrade') {
            $core_update = array(
                'current_version' => get_bloginfo('version'),
                'new_version'     => $core_updates->updates[0]->current,
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'plugins' => $updates,
            'themes'  => $theme_updates,
            'core'    => $core_update,
            'summary' => array(
                'plugins' => count($updates),
                'themes'  => count($theme_updates),
                'core'    => $core_update ? 1 : 0,
            ),
        ));
    }

    /**
     * Update a plugin
     */
    public static function update_plugin($request) {
        $slug = $request->get_param('slug');

        if (empty($slug)) {
            return new WP_Error('missing_slug', __('Plugin slug is required', 'vdl-agent'), array('status' => 400));
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Find the plugin file
        $all_plugins = get_plugins();
        $plugin_file = null;

        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug || $file === $slug . '.php') {
                $plugin_file = $file;
                break;
            }
        }

        if (!$plugin_file) {
            return new WP_Error('plugin_not_found', __('Plugin not found', 'vdl-agent'), array('status' => 404));
        }

        // Check if update is available
        $update_plugins = get_site_transient('update_plugins');
        if (!isset($update_plugins->response[$plugin_file])) {
            return new WP_Error('no_update', __('No update available for this plugin', 'vdl-agent'), array('status' => 400));
        }

        // Include required files
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        // Silent upgrader skin
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Remember if plugin was active before update
        $was_active = is_plugin_active($plugin_file);

        // Perform the update
        $result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return new WP_Error('update_failed', $result->get_error_message(), array('status' => 500));
        }

        if ($result === false) {
            return new WP_Error('update_failed', __('Plugin update failed', 'vdl-agent'), array('status' => 500));
        }

        // Force re-activation if plugin was active before update.
        // WordPress deactivates plugins during upgrade. We must reactivate
        // explicitly and flush all caches to ensure it persists.
        $reactivated = false;
        if ($was_active) {
            $reactivated = self::force_activate_plugin($plugin_file);
        }

        // Get new version from the freshly installed files
        $new_plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);

        return rest_ensure_response(array(
            'success'     => true,
            'plugin'      => $slug,
            'new_version' => $new_plugin_data['Version'],
            'reactivated' => $reactivated,
            'active'      => is_plugin_active($plugin_file),
            'message'     => sprintf(__('Plugin %s updated to version %s', 'vdl-agent'), $new_plugin_data['Name'], $new_plugin_data['Version']),
        ));
    }

    /**
     * Force-activate a plugin with multiple fallback strategies.
     *
     * Strategy 1: WordPress activate_plugin() with cache flush
     * Strategy 2: Direct database write into active_plugins option
     *
     * @param string $plugin_file Plugin basename (e.g. "vdl-agent/vdl-agent.php")
     * @return bool True if plugin is active after all attempts
     */
    private static function force_activate_plugin($plugin_file) {
        // Flush object cache so is_plugin_active reads from DB
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('alloptions', 'options');

        // Strategy 1: Use WordPress activate_plugin()
        if (!is_plugin_active($plugin_file)) {
            $activated = activate_plugin($plugin_file);
            // activate_plugin returns null on success, WP_Error on failure
            if (is_wp_error($activated)) {
                error_log('[VDL Agent] activate_plugin() failed: ' . $activated->get_error_message());
            }
        }

        // Flush cache again after activation attempt
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('alloptions', 'options');

        // Check if it worked
        if (is_plugin_active($plugin_file)) {
            return true;
        }

        // Strategy 2: Direct database write
        error_log('[VDL Agent] activate_plugin() did not persist, falling back to direct DB write');
        $active_plugins = get_option('active_plugins', array());

        if (!in_array($plugin_file, $active_plugins)) {
            $active_plugins[] = $plugin_file;
            sort($active_plugins);
            update_option('active_plugins', $active_plugins);
        }

        // Final flush and check
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('alloptions', 'options');

        $is_active = in_array($plugin_file, get_option('active_plugins', array()));
        if ($is_active) {
            error_log('[VDL Agent] Plugin reactivated via direct DB write');
        } else {
            error_log('[VDL Agent] CRITICAL: Plugin reactivation failed even with direct DB write');
        }

        return $is_active;
    }

    /**
     * Activate a plugin by slug
     */
    public static function activate_plugin($request) {
        $slug = $request->get_param('slug');

        if (empty($slug)) {
            return new WP_Error('missing_slug', __('Plugin slug is required', 'vdl-agent'), array('status' => 400));
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Find the plugin file
        $all_plugins = get_plugins();
        $plugin_file = null;

        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug || $file === $slug . '.php') {
                $plugin_file = $file;
                break;
            }
        }

        if (!$plugin_file) {
            return new WP_Error('plugin_not_found', __('Plugin not found', 'vdl-agent'), array('status' => 404));
        }

        // Check if already active
        if (is_plugin_active($plugin_file)) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            return rest_ensure_response(array(
                'success' => true,
                'plugin'  => $slug,
                'active'  => true,
                'message' => sprintf(__('Plugin %s is already active', 'vdl-agent'), $plugin_data['Name']),
            ));
        }

        // Force activate with cache flush
        $activated = self::force_activate_plugin($plugin_file);
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);

        if ($activated) {
            return rest_ensure_response(array(
                'success' => true,
                'plugin'  => $slug,
                'active'  => true,
                'version' => $plugin_data['Version'],
                'message' => sprintf(__('Plugin %s activated successfully', 'vdl-agent'), $plugin_data['Name']),
            ));
        }

        return new WP_Error('activation_failed', __('Plugin activation failed', 'vdl-agent'), array('status' => 500));
    }

    /**
     * Purge all caches
     */
    public static function purge_cache($request) {
        $purged = array();

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $purged[] = 'WP Super Cache';
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $purged[] = 'W3 Total Cache';
        }

        // WP Fastest Cache
        if (class_exists('WpFastestCache')) {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache(true);
            $purged[] = 'WP Fastest Cache';
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $purged[] = 'LiteSpeed Cache';
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            if (function_exists('rocket_clean_minify')) {
                rocket_clean_minify();
            }
            $purged[] = 'WP Rocket';
        }

        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
            $purged[] = 'Autoptimize';
        }

        // Elementor
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            $purged[] = 'Elementor';
        }

        // Object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $purged[] = 'Object Cache';
        }

        // Clear transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
        $purged[] = 'Transients';

        return rest_ensure_response(array(
            'success' => true,
            'purged'  => $purged,
            'message' => sprintf(__('%d cache(s) purged successfully', 'vdl-agent'), count($purged)),
        ));
    }

    /**
     * Regenerate Elementor CSS
     */
    public static function regenerate_elementor_css($request) {
        if (!class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_not_active', __('Elementor is not active', 'vdl-agent'), array('status' => 400));
        }

        // Clear Elementor cache
        \Elementor\Plugin::$instance->files_manager->clear_cache();

        // Regenerate CSS for all posts
        $elementor_posts = get_posts(array(
            'post_type'      => array('post', 'page', 'elementor_library'),
            'posts_per_page' => -1,
            'meta_key'       => '_elementor_edit_mode',
            'meta_value'     => 'builder',
            'fields'         => 'ids',
        ));

        $regenerated = 0;
        foreach ($elementor_posts as $post_id) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($document) {
                $document->save_template_type();
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                $regenerated++;
            }
        }

        return rest_ensure_response(array(
            'success'     => true,
            'regenerated' => $regenerated,
            'message'     => sprintf(__('Elementor CSS regenerated for %d posts', 'vdl-agent'), $regenerated),
        ));
    }

    /**
     * Get system info
     */
    public static function get_system_info($request) {
        global $wpdb;

        // PHP info
        $php_info = array(
            'version'         => phpversion(),
            'memory_limit'    => ini_get('memory_limit'),
            'max_execution'   => ini_get('max_execution_time'),
            'upload_max'      => ini_get('upload_max_filesize'),
            'post_max'        => ini_get('post_max_size'),
            'extensions'      => get_loaded_extensions(),
        );

        // WordPress info
        $wp_info = array(
            'version'         => get_bloginfo('version'),
            'multisite'       => is_multisite(),
            'debug_mode'      => WP_DEBUG,
            'memory_limit'    => WP_MEMORY_LIMIT,
            'max_memory'      => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : 'Not set',
            'permalink'       => get_option('permalink_structure'),
            'timezone'        => wp_timezone_string(),
        );

        // Database info
        $db_info = array(
            'version'         => $wpdb->db_version(),
            'prefix'          => $wpdb->prefix,
            'charset'         => $wpdb->charset,
            'collate'         => $wpdb->collate,
        );

        // Server info
        $server_info = array(
            'software'        => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
            'php_sapi'        => php_sapi_name(),
            'os'              => PHP_OS,
        );

        // Disk space
        $uploads_dir = wp_upload_dir();
        $disk_info = array(
            'uploads_path'    => $uploads_dir['basedir'],
            'free_space'      => function_exists('disk_free_space') ? size_format(disk_free_space($uploads_dir['basedir'])) : 'N/A',
            'total_space'     => function_exists('disk_total_space') ? size_format(disk_total_space($uploads_dir['basedir'])) : 'N/A',
        );

        return rest_ensure_response(array(
            'success' => true,
            'php'     => $php_info,
            'wp'      => $wp_info,
            'db'      => $db_info,
            'server'  => $server_info,
            'disk'    => $disk_info,
        ));
    }
}
