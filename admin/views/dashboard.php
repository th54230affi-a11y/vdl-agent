<?php
/**
 * VDL Agent Dashboard View
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

$stats = VDL_Admin::get_dashboard_stats();
?>

<div class="wrap vdl-admin">
    <h1><?php _e('VDL Agent Dashboard', 'vdl-agent'); ?></h1>

    <!-- Stats Cards -->
    <div class="vdl-stats-grid">
        <div class="vdl-stat-card">
            <div class="vdl-stat-icon">
                <span class="dashicons dashicons-admin-links"></span>
            </div>
            <div class="vdl-stat-content">
                <span class="vdl-stat-value"><?php echo number_format_i18n($stats['total_links']); ?></span>
                <span class="vdl-stat-label"><?php _e('Total Links', 'vdl-agent'); ?></span>
            </div>
        </div>

        <div class="vdl-stat-card">
            <div class="vdl-stat-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="vdl-stat-content">
                <span class="vdl-stat-value"><?php echo number_format_i18n($stats['total_clicks']); ?></span>
                <span class="vdl-stat-label"><?php _e('Total Clicks', 'vdl-agent'); ?></span>
            </div>
        </div>

        <div class="vdl-stat-card">
            <div class="vdl-stat-icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="vdl-stat-content">
                <span class="vdl-stat-value"><?php echo $stats['avg_ctr']; ?>%</span>
                <span class="vdl-stat-label"><?php _e('Average CTR', 'vdl-agent'); ?></span>
            </div>
        </div>

        <div class="vdl-stat-card">
            <div class="vdl-stat-icon">
                <span class="dashicons dashicons-admin-plugins"></span>
            </div>
            <div class="vdl-stat-content">
                <span class="vdl-stat-value"><?php echo VDL_AGENT_VERSION; ?></span>
                <span class="vdl-stat-label"><?php _e('Plugin Version', 'vdl-agent'); ?></span>
            </div>
        </div>
    </div>

    <div class="vdl-dashboard-grid">
        <!-- Top Links -->
        <div class="vdl-card">
            <h2><?php _e('Top Performing Links', 'vdl-agent'); ?></h2>
            <?php if (!empty($stats['top_links'])) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Anchor', 'vdl-agent'); ?></th>
                            <th><?php _e('URL', 'vdl-agent'); ?></th>
                            <th><?php _e('Clicks', 'vdl-agent'); ?></th>
                            <th><?php _e('CTR', 'vdl-agent'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['top_links'] as $link) :
                            $ctr = $link->impressions > 0 ? round(($link->clicks / $link->impressions) * 100, 2) : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($link->link_anchor); ?></strong></td>
                                <td>
                                    <a href="<?php echo esc_url($link->link_url); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html(wp_trim_words($link->link_url, 5)); ?>
                                    </a>
                                </td>
                                <td><?php echo number_format_i18n($link->clicks); ?></td>
                                <td><?php echo $ctr; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="vdl-no-data"><?php _e('No link data available yet.', 'vdl-agent'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Top Articles -->
        <div class="vdl-card">
            <h2><?php _e('Top Performing Articles', 'vdl-agent'); ?></h2>
            <?php if (!empty($stats['top_articles'])) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Article', 'vdl-agent'); ?></th>
                            <th><?php _e('Links', 'vdl-agent'); ?></th>
                            <th><?php _e('Total Clicks', 'vdl-agent'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['top_articles'] as $article) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($article->url); ?>" target="_blank">
                                        <?php echo esc_html($article->title); ?>
                                    </a>
                                </td>
                                <td><?php echo number_format_i18n($article->link_count); ?></td>
                                <td><?php echo number_format_i18n($article->total_clicks); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="vdl-no-data"><?php _e('No article data available yet.', 'vdl-agent'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity Chart -->
    <div class="vdl-card vdl-full-width">
        <h2><?php _e('Clicks (Last 7 Days)', 'vdl-agent'); ?></h2>
        <?php if (!empty($stats['recent'])) : ?>
            <div class="vdl-chart-container">
                <canvas id="vdl-clicks-chart"></canvas>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof Chart !== 'undefined') {
                    var ctx = document.getElementById('vdl-clicks-chart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode(array_column($stats['recent'], 'date')); ?>,
                            datasets: [{
                                label: '<?php _e('Clicks', 'vdl-agent'); ?>',
                                data: <?php echo json_encode(array_column($stats['recent'], 'clicks')); ?>,
                                borderColor: '#5B4CFF',
                                backgroundColor: 'rgba(91, 76, 255, 0.1)',
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
            });
            </script>
        <?php else : ?>
            <p class="vdl-no-data"><?php _e('No recent activity data available.', 'vdl-agent'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="vdl-card vdl-full-width">
        <h2><?php _e('Quick Actions', 'vdl-agent'); ?></h2>
        <div class="vdl-actions-grid">
            <a href="<?php echo admin_url('admin.php?page=vdl-agent-settings'); ?>" class="vdl-action-btn">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php _e('Settings', 'vdl-agent'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=vdl-agent-keys'); ?>" class="vdl-action-btn">
                <span class="dashicons dashicons-admin-network"></span>
                <?php _e('API Keys', 'vdl-agent'); ?>
            </a>
            <button type="button" class="vdl-action-btn" id="vdl-purge-cache">
                <span class="dashicons dashicons-trash"></span>
                <?php _e('Purge Cache', 'vdl-agent'); ?>
            </button>
            <a href="<?php echo rest_url('vdl/v1/health'); ?>" target="_blank" class="vdl-action-btn">
                <span class="dashicons dashicons-heart"></span>
                <?php _e('Health Check', 'vdl-agent'); ?>
            </a>
        </div>
    </div>

    <!-- System Info -->
    <div class="vdl-card vdl-full-width vdl-system-info">
        <h2><?php _e('System Information', 'vdl-agent'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php _e('WordPress Version', 'vdl-agent'); ?></th>
                <td><?php echo get_bloginfo('version'); ?></td>
            </tr>
            <tr>
                <th><?php _e('PHP Version', 'vdl-agent'); ?></th>
                <td><?php echo phpversion(); ?></td>
            </tr>
            <tr>
                <th><?php _e('Active Theme', 'vdl-agent'); ?></th>
                <td><?php echo wp_get_theme()->get('Name'); ?></td>
            </tr>
            <tr>
                <th><?php _e('REST API URL', 'vdl-agent'); ?></th>
                <td><code><?php echo rest_url('vdl/v1/'); ?></code></td>
            </tr>
            <tr>
                <th><?php _e('Plugin Version', 'vdl-agent'); ?></th>
                <td><?php echo VDL_AGENT_VERSION; ?></td>
            </tr>
        </table>
    </div>
</div>
