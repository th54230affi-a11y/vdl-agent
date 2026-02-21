<?php
/**
 * VDL Agent — GitHub Auto-Updater
 *
 * Vérifie les releases GitHub pour mettre à jour le plugin automatiquement
 * via le système natif de mise à jour WordPress.
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Updater {

    /**
     * GitHub repository (owner/repo)
     */
    private $github_repo = 'th54230affi-a11y/vdl-agent';

    /**
     * Plugin basename (vdl-agent/vdl-agent.php)
     */
    private $plugin_basename;

    /**
     * Current plugin version
     */
    private $current_version;

    /**
     * GitHub API response cache (transient)
     */
    private $transient_key = 'vdl_agent_github_update';

    /**
     * Cache duration in seconds (12 hours)
     */
    private $cache_duration = 43200;

    /**
     * Constructor
     *
     * @param string $plugin_basename Plugin basename (plugin_basename(__FILE__) from main file)
     * @param string $current_version Current plugin version
     */
    public function __construct($plugin_basename, $current_version) {
        $this->plugin_basename = $plugin_basename;
        $this->current_version = $current_version;

        $this->init_hooks();
    }

    /**
     * Register WordPress hooks
     */
    private function init_hooks() {
        // Check for updates when WordPress checks plugins
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));

        // Provide plugin info for the update details modal
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);

        // NOTE: Pas de filtre upgrader_source_selection (fix_source_dir).
        // Le ZIP attache a la release contient deja le bon dossier "vdl-agent/".
        // On ne touche JAMAIS aux noms de dossiers pendant l'update.

        // Clean up after update
        add_action('upgrader_process_complete', array($this, 'after_update'), 10, 2);

        // Add "Check for updates" link on plugins page
        add_filter('plugin_action_links_' . $this->plugin_basename, array($this, 'add_action_links'));
    }

    /**
     * Check GitHub for a new release
     *
     * @param object $transient WordPress update transient
     * @return object Modified transient
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        $remote_version = ltrim($release['tag_name'], 'v');

        if (version_compare($this->current_version, $remote_version, '<')) {
            $download_url = $this->get_download_url($release);

            if ($download_url) {
                $transient->response[$this->plugin_basename] = (object) array(
                    'slug'        => dirname($this->plugin_basename),
                    'plugin'      => $this->plugin_basename,
                    'new_version' => $remote_version,
                    'url'         => $release['html_url'],
                    'package'     => $download_url,
                    'icons'       => array(),
                    'banners'     => array(),
                    'tested'      => '6.7',
                    'requires'    => '5.8',
                    'requires_php'=> '8.0',
                );
            }
        }

        return $transient;
    }

    /**
     * Provide plugin information for the update details modal
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $current_slug = dirname($this->plugin_basename);
        if (!isset($args->slug) || $args->slug !== $current_slug) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $remote_version = ltrim($release['tag_name'], 'v');

        $plugin_info = (object) array(
            'name'            => 'VDL Agent',
            'slug'            => dirname($this->plugin_basename),
            'version'         => $remote_version,
            'author'          => '<a href="https://vdl-tech.fr">VDL Tech</a>',
            'author_profile'  => 'https://vdl-tech.fr',
            'homepage'        => 'https://github.com/' . $this->github_repo,
            'requires'        => '5.8',
            'requires_php'    => '7.4',
            'tested'          => '6.7',
            'downloaded'      => 0,
            'last_updated'    => $release['published_at'],
            'sections'        => array(
                'description'  => 'Agent API pour la gestion à distance des sites VDL (Vente De Liens) — Stats, Liens, Thème, SEO, Maintenance.',
                'changelog'    => $this->format_changelog($release['body']),
                'installation' => 'Le plugin se met à jour automatiquement via GitHub Releases.',
            ),
            'download_link'   => $this->get_download_url($release),
        );

        return $plugin_info;
    }

    /**
     * Clean up transient cache after update and ensure plugin stays activated.
     *
     * WordPress deactivates the plugin during upgrade (removes from active_plugins).
     * The object cache may still hold the old value, so activate_plugin() alone
     * can silently fail. We flush caches aggressively and fall back to a direct
     * database write if needed.
     *
     * @param object $upgrader
     * @param array $options
     */
    public function after_update($upgrader, $options) {
        if (
            $options['action'] === 'update' &&
            $options['type'] === 'plugin' &&
            isset($options['plugins'])
        ) {
            foreach ($options['plugins'] as $plugin) {
                if ($plugin === $this->plugin_basename) {
                    delete_transient($this->transient_key);

                    if (!function_exists('is_plugin_active')) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }

                    // Flush object cache so we read the real DB state
                    wp_cache_delete('active_plugins', 'options');
                    wp_cache_delete('alloptions', 'options');

                    // Strategy 1: WordPress activate_plugin()
                    if (!is_plugin_active($this->plugin_basename)) {
                        $result = activate_plugin($this->plugin_basename);
                        if (is_wp_error($result)) {
                            error_log('[VDL Updater] activate_plugin() failed: ' . $result->get_error_message());
                        }
                    }

                    // Flush again and verify
                    wp_cache_delete('active_plugins', 'options');
                    wp_cache_delete('alloptions', 'options');

                    if (!is_plugin_active($this->plugin_basename)) {
                        // Strategy 2: Direct database write
                        error_log('[VDL Updater] activate_plugin() did not persist, falling back to direct DB write');
                        $active_plugins = get_option('active_plugins', array());
                        if (!in_array($this->plugin_basename, $active_plugins)) {
                            $active_plugins[] = $this->plugin_basename;
                            sort($active_plugins);
                            update_option('active_plugins', $active_plugins);
                        }

                        wp_cache_delete('active_plugins', 'options');
                        wp_cache_delete('alloptions', 'options');

                        $still_inactive = !in_array(
                            $this->plugin_basename,
                            get_option('active_plugins', array())
                        );
                        if ($still_inactive) {
                            error_log('[VDL Updater] CRITICAL: Plugin reactivation failed even with direct DB write');
                        } else {
                            error_log('[VDL Updater] Plugin reactivated via direct DB write');
                        }
                    }

                    break;
                }
            }
        }
    }

    /**
     * Add action links on the plugins page
     *
     * @param array $links
     * @return array
     */
    public function add_action_links($links) {
        $check_link = '<a href="' . esc_url(admin_url('plugins.php?vdl_check_update=1')) . '">Vérifier MàJ</a>';
        array_unshift($links, $check_link);

        // Handle manual update check
        if (isset($_GET['vdl_check_update']) && $_GET['vdl_check_update'] === '1') {
            delete_transient($this->transient_key);
            delete_site_transient('update_plugins');
        }

        return $links;
    }

    /**
     * Get latest release from GitHub API (cached)
     *
     * @return array|false Release data or false on failure
     */
    private function get_latest_release() {
        $cached = get_transient($this->transient_key);

        if ($cached !== false) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->github_repo
        );

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'VDL-Agent-Updater/' . $this->current_version,
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $release = json_decode($body, true);

        if (empty($release) || !isset($release['tag_name'])) {
            return false;
        }

        // Cache the result
        set_transient($this->transient_key, $release, $this->cache_duration);

        return $release;
    }

    /**
     * Get the ZIP download URL from a release
     *
     * IMPORTANT: On utilise UNIQUEMENT le ZIP attache a la release (vdl-agent.zip).
     * Ce ZIP contient le dossier "vdl-agent/" correctement nomme.
     * On n'utilise PAS le zipball GitHub (qui a un nom de dossier imprevisible).
     *
     * @param array $release GitHub release data
     * @return string|false Download URL or false
     */
    private function get_download_url($release) {
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (
                    !empty($asset['browser_download_url']) &&
                    (
                        (isset($asset['content_type']) && $asset['content_type'] === 'application/zip') ||
                        (isset($asset['name']) && substr($asset['name'], -4) === '.zip')
                    )
                ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Pas de ZIP attache = pas d'update possible
        // On ne fallback PAS sur zipball_url (causerait un mauvais nom de dossier)
        return false;
    }

    /**
     * Format release body as HTML changelog
     *
     * @param string $body Release body (markdown)
     * @return string HTML
     */
    private function format_changelog($body) {
        if (empty($body)) {
            return '<p>Pas de notes de version.</p>';
        }

        // Basic markdown → HTML conversion
        $html = esc_html($body);
        $html = nl2br($html);

        // Convert **bold** to <strong>
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // Convert - list items to <li>
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        if (strpos($html, '<li>') !== false) {
            $html = '<ul>' . $html . '</ul>';
        }

        return $html;
    }

    /**
     * Force check for updates (can be called programmatically)
     *
     * @return array|false Latest release data
     */
    public function force_check() {
        delete_transient($this->transient_key);
        return $this->get_latest_release();
    }
}
