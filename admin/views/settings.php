<?php
/**
 * VDL Agent Settings View
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle form submission
if (isset($_POST['vdl_save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'vdl_settings_nonce')) {
    // Save allowed IPs
    $allowed_ips = sanitize_textarea_field($_POST['vdl_allowed_ips']);
    update_option('vdl_agent_allowed_ips', $allowed_ips);

    // Save rate limit
    $rate_limit = absint($_POST['vdl_rate_limit']);
    if ($rate_limit < 10) $rate_limit = 10;
    if ($rate_limit > 1000) $rate_limit = 1000;
    update_option('vdl_agent_rate_limit', $rate_limit);

    add_settings_error('vdl_agent', 'settings_saved', __('Settings saved successfully', 'vdl-agent'), 'success');
}

$allowed_ips = get_option('vdl_agent_allowed_ips', '');
$rate_limit = get_option('vdl_agent_rate_limit', 100);
?>

<div class="wrap vdl-admin">
    <h1><?php _e('VDL Agent Settings', 'vdl-agent'); ?></h1>

    <?php settings_errors('vdl_agent'); ?>

    <form method="post" action="">
        <?php wp_nonce_field('vdl_settings_nonce'); ?>

        <!-- Security Settings -->
        <div class="vdl-card">
            <h2><?php _e('Security Settings', 'vdl-agent'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="vdl_allowed_ips"><?php _e('Allowed IP Addresses', 'vdl-agent'); ?></label>
                    </th>
                    <td>
                        <textarea name="vdl_allowed_ips" id="vdl_allowed_ips" rows="5" class="large-text code"><?php echo esc_textarea($allowed_ips); ?></textarea>
                        <p class="description">
                            <?php _e('Enter one IP address per line. Leave empty to allow all IPs (not recommended for production).', 'vdl-agent'); ?>
                        </p>
                        <p class="description">
                            <?php printf(__('Your current IP: %s', 'vdl-agent'), '<code>' . esc_html($_SERVER['REMOTE_ADDR']) . '</code>'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="vdl_rate_limit"><?php _e('Rate Limit (requests/minute)', 'vdl-agent'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="vdl_rate_limit" id="vdl_rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="10" max="1000" class="small-text">
                        <p class="description">
                            <?php _e('Maximum number of API requests allowed per minute per IP. Default: 100', 'vdl-agent'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Protected Files -->
        <div class="vdl-card">
            <h2><?php _e('Protected Files', 'vdl-agent'); ?></h2>
            <p class="description">
                <?php _e('The following files cannot be read or modified via the API for security reasons:', 'vdl-agent'); ?>
            </p>
            <ul class="vdl-protected-list">
                <li><code>wp-config.php</code></li>
                <li><code>.htaccess</code></li>
                <li><code>*.php</code> <?php _e('files outside theme directory', 'vdl-agent'); ?></li>
            </ul>
        </div>

        <!-- Theme Settings -->
        <div class="vdl-card">
            <h2><?php _e('Theme Management', 'vdl-agent'); ?></h2>

            <?php
            $theme = wp_get_theme();
            $child_theme = $theme->parent() ? $theme : null;
            $parent_theme = $child_theme ? wp_get_theme($theme->get_template()) : null;
            ?>

            <table class="form-table">
                <tr>
                    <th><?php _e('Active Theme', 'vdl-agent'); ?></th>
                    <td>
                        <strong><?php echo esc_html($theme->get('Name')); ?></strong>
                        (v<?php echo esc_html($theme->get('Version')); ?>)
                    </td>
                </tr>
                <?php if ($child_theme) : ?>
                <tr>
                    <th><?php _e('Parent Theme', 'vdl-agent'); ?></th>
                    <td>
                        <?php echo esc_html($parent_theme->get('Name')); ?>
                        (v<?php echo esc_html($parent_theme->get('Version')); ?>)
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php _e('Theme Directory', 'vdl-agent'); ?></th>
                    <td><code><?php echo esc_html($theme->get_stylesheet_directory()); ?></code></td>
                </tr>
            </table>

            <p class="description">
                <?php _e('The API can read and write files in the active theme directory. Backups are created automatically before any modification.', 'vdl-agent'); ?>
            </p>
        </div>

        <!-- Backup Settings -->
        <div class="vdl-card">
            <h2><?php _e('Backup Settings', 'vdl-agent'); ?></h2>

            <?php
            $upload_dir = wp_upload_dir();
            $backup_dir = $upload_dir['basedir'] . '/vdl-backups';
            $backup_count = 0;
            $backup_size = 0;

            if (is_dir($backup_dir)) {
                $files = glob($backup_dir . '/*');
                $backup_count = count($files);
                foreach ($files as $file) {
                    $backup_size += filesize($file);
                }
            }
            ?>

            <table class="form-table">
                <tr>
                    <th><?php _e('Backup Directory', 'vdl-agent'); ?></th>
                    <td><code><?php echo esc_html($backup_dir); ?></code></td>
                </tr>
                <tr>
                    <th><?php _e('Backup Count', 'vdl-agent'); ?></th>
                    <td><?php echo number_format_i18n($backup_count); ?> <?php _e('files', 'vdl-agent'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Backup Size', 'vdl-agent'); ?></th>
                    <td><?php echo size_format($backup_size); ?></td>
                </tr>
            </table>

            <?php if ($backup_count > 0) : ?>
            <p>
                <button type="button" class="button" id="vdl-clear-old-backups">
                    <?php _e('Clear Backups Older Than 30 Days', 'vdl-agent'); ?>
                </button>
            </p>
            <?php endif; ?>
        </div>

        <!-- Submit -->
        <p class="submit">
            <input type="submit" name="vdl_save_settings" class="button-primary" value="<?php _e('Save Settings', 'vdl-agent'); ?>">
        </p>
    </form>

    <!-- Debug Info -->
    <div class="vdl-card">
        <h2><?php _e('Debug Information', 'vdl-agent'); ?></h2>
        <p>
            <button type="button" class="button" id="vdl-test-api">
                <?php _e('Test API Connection', 'vdl-agent'); ?>
            </button>
        </p>
        <div id="vdl-api-test-result" style="display: none; margin-top: 15px;">
            <pre class="vdl-code-block"></pre>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Test API button
    $('#vdl-test-api').on('click', function() {
        var $btn = $(this);
        var $result = $('#vdl-api-test-result');

        $btn.prop('disabled', true).text('<?php _e('Testing...', 'vdl-agent'); ?>');

        $.ajax({
            url: '<?php echo rest_url('vdl/v1/health'); ?>',
            method: 'GET',
            headers: {
                'Authorization': 'Bearer <?php echo esc_js(get_option('vdl_agent_api_key')); ?>'
            },
            success: function(data) {
                $result.show().find('pre').text(JSON.stringify(data, null, 2));
            },
            error: function(xhr) {
                $result.show().find('pre').text('Error: ' + xhr.status + ' ' + xhr.statusText);
            },
            complete: function() {
                $btn.prop('disabled', false).text('<?php _e('Test API Connection', 'vdl-agent'); ?>');
            }
        });
    });

    // Clear old backups
    $('#vdl-clear-old-backups').on('click', function() {
        if (!confirm('<?php _e('Are you sure you want to delete old backups?', 'vdl-agent'); ?>')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php _e('Clearing...', 'vdl-agent'); ?>');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'vdl_clear_old_backups',
                nonce: '<?php echo wp_create_nonce('vdl_clear_backups'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || '<?php _e('Error clearing backups', 'vdl-agent'); ?>');
                }
            },
            error: function() {
                alert('<?php _e('Error clearing backups', 'vdl-agent'); ?>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('<?php _e('Clear Backups Older Than 30 Days', 'vdl-agent'); ?>');
            }
        });
    });
});
</script>
