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
     * Plugin slug
     */
    private $plugin_slug = 'vdl-agent';

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

        // Rename extracted folder from GitHub zipball (owner-repo-hash → vdl-agent)
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);

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
                    'slug'        => $this->plugin_slug,
                    'plugin'      => $this->plugin_basename,
                    'new_version' => $remote_version,
                    'url'         => $release['html_url'],
                    'package'     => $download_url,
                    'icons'       => array(),
                    'banners'     => array(),
                    'tested'      => '6.7',
                    'requires'    => '5.8',
                    'requires_php'=> '7.4',
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

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $remote_version = ltrim($release['tag_name'], 'v');

        $plugin_info = (object) array(
            'name'            => 'VDL Agent',
            'slug'            => $this->plugin_slug,
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
     * Fix the source directory name after extracting GitHub zipball.
     *
     * GitHub zipballs extract to "owner-repo-hash/" but WordPress expects "vdl-agent/".
     * This filter renames the directory before WordPress moves it.
     *
     * @param string $source        File source location (extracted directory path)
     * @param string $remote_source Remote file source location
     * @param object $upgrader      WP_Upgrader instance
     * @param array  $hook_extra    Extra arguments passed to the upgrader
     * @return string|WP_Error Corrected source path or WP_Error
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        // Only act on our plugin
        if (
            !isset($hook_extra['plugin']) ||
            $hook_extra['plugin'] !== $this->plugin_basename
        ) {
            return $source;
        }

        global $wp_filesystem;

        // Expected correct directory name
        $correct_source = trailingslashit($remote_source) . $this->plugin_slug . '/';

        // If the source already has the right name, do nothing
        if ($source === $correct_source) {
            return $source;
        }

        // Rename the extracted directory
        if ($wp_filesystem->move($source, $correct_source, true)) {
            return $correct_source;
        }

        return new WP_Error(
            'vdl_updater_rename_failed',
            'Impossible de renommer le dossier du plugin après extraction.'
        );
    }

    /**
     * Clean up transient cache after update
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
     * Prefers a .zip asset if attached, otherwise uses GitHub's auto-generated zipball.
     *
     * @param array $release GitHub release data
     * @return string|false Download URL or false
     */
    private function get_download_url($release) {
        // Check for attached .zip asset first
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (
                    isset($asset['content_type']) &&
                    $asset['content_type'] === 'application/zip' &&
                    !empty($asset['browser_download_url'])
                ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fallback to GitHub's auto-generated source zipball
        if (!empty($release['zipball_url'])) {
            return $release['zipball_url'];
        }

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
