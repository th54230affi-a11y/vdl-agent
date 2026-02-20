<?php
/**
 * VDL Agent Theme Management
 *
 * Gestion des fichiers du thème enfant via API
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Theme {

    /**
     * Allowed file extensions
     */
    private static $allowed_extensions = array('css', 'php', 'js', 'json', 'txt', 'md', 'html');

    /**
     * Protected files (cannot be modified)
     */
    private static $protected_files = array('wp-config.php', '.htaccess');

    /**
     * Max file size (500 KB)
     */
    private static $max_file_size = 512000;

    /**
     * Backup directory
     */
    private static function get_backup_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/vdl-theme-backups/';
    }

    /**
     * Get child theme directory
     */
    private static function get_theme_dir() {
        $theme = wp_get_theme();

        // If active theme is a child theme
        if ($theme->parent()) {
            return get_stylesheet_directory();
        }

        // Otherwise use child theme based on parent
        $child_dir = get_stylesheet_directory();
        return $child_dir;
    }

    /**
     * Validate file path
     */
    private static function validate_file($file) {
        // Check for path traversal
        if (strpos($file, '..') !== false) {
            return new WP_Error('invalid_path', __('Invalid file path', 'vdl-agent'));
        }

        // Check protected files
        $basename = basename($file);
        if (in_array($basename, self::$protected_files)) {
            return new WP_Error('protected_file', __('This file is protected', 'vdl-agent'));
        }

        // Check extension
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, self::$allowed_extensions)) {
            return new WP_Error('invalid_extension', sprintf(
                __('File extension not allowed. Allowed: %s', 'vdl-agent'),
                implode(', ', self::$allowed_extensions)
            ));
        }

        return true;
    }

    /**
     * List theme files
     */
    public static function list_files($request) {
        $theme_dir = self::get_theme_dir();

        if (!is_dir($theme_dir)) {
            return new WP_Error('no_theme', __('Child theme directory not found', 'vdl-agent'), array('status' => 404));
        }

        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());

                if (in_array($extension, self::$allowed_extensions)) {
                    $relative_path = str_replace($theme_dir . '/', '', $file->getPathname());
                    $relative_path = str_replace($theme_dir . '\\', '', $relative_path);

                    $files[] = array(
                        'path'     => $relative_path,
                        'size'     => $file->getSize(),
                        'modified' => date('c', $file->getMTime()),
                        'type'     => $extension,
                    );
                }
            }
        }

        // Sort by path
        usort($files, function($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return rest_ensure_response(array(
            'success'   => true,
            'theme_dir' => basename($theme_dir),
            'files'     => $files,
            'count'     => count($files),
        ));
    }

    /**
     * Read a file
     */
    public static function read_file($request) {
        $file = $request->get_param('file');

        if (empty($file)) {
            return new WP_Error('missing_file', __('File parameter is required', 'vdl-agent'), array('status' => 400));
        }

        $validation = self::validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $theme_dir = self::get_theme_dir();
        $file_path = $theme_dir . '/' . $file;

        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found', 'vdl-agent'), array('status' => 404));
        }

        $content = file_get_contents($file_path);

        if ($content === false) {
            return new WP_Error('read_error', __('Could not read file', 'vdl-agent'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'file'    => $file,
            'content' => $content,
            'size'    => filesize($file_path),
            'modified' => date('c', filemtime($file_path)),
        ));
    }

    /**
     * Write a file
     */
    public static function write_file($request) {
        $file = $request->get_param('file');
        $content = $request->get_param('content');
        $create_backup = $request->get_param('create_backup') !== false;

        if (empty($file)) {
            return new WP_Error('missing_file', __('File parameter is required', 'vdl-agent'), array('status' => 400));
        }

        if ($content === null) {
            return new WP_Error('missing_content', __('Content parameter is required', 'vdl-agent'), array('status' => 400));
        }

        $validation = self::validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Check file size
        if (strlen($content) > self::$max_file_size) {
            return new WP_Error('file_too_large', __('File content exceeds maximum size (500 KB)', 'vdl-agent'), array('status' => 400));
        }

        $theme_dir = self::get_theme_dir();
        $file_path = $theme_dir . '/' . $file;

        // Create backup if file exists
        $backup_id = null;
        if ($create_backup && file_exists($file_path)) {
            $backup_result = self::create_backup($file);
            if (!is_wp_error($backup_result)) {
                $backup_id = $backup_result;
            }
        }

        // Create directory if needed
        $dir = dirname($file_path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Write file
        $result = file_put_contents($file_path, $content);

        if ($result === false) {
            return new WP_Error('write_error', __('Could not write file', 'vdl-agent'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success'   => true,
            'file'      => $file,
            'size'      => $result,
            'backup_id' => $backup_id,
            'message'   => __('File saved successfully', 'vdl-agent'),
        ));
    }

    /**
     * Search and replace in file
     */
    public static function search_replace($request) {
        $file = $request->get_param('file');
        $search = $request->get_param('search');
        $replace = $request->get_param('replace');

        if (empty($file) || $search === null || $replace === null) {
            return new WP_Error('missing_params', __('File, search and replace parameters are required', 'vdl-agent'), array('status' => 400));
        }

        $validation = self::validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $theme_dir = self::get_theme_dir();
        $file_path = $theme_dir . '/' . $file;

        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found', 'vdl-agent'), array('status' => 404));
        }

        $content = file_get_contents($file_path);

        if ($content === false) {
            return new WP_Error('read_error', __('Could not read file', 'vdl-agent'), array('status' => 500));
        }

        // Create backup before modification
        $backup_id = self::create_backup($file);

        // Count occurrences
        $count = substr_count($content, $search);

        if ($count === 0) {
            return rest_ensure_response(array(
                'success'      => true,
                'file'         => $file,
                'replacements' => 0,
                'message'      => __('Search string not found', 'vdl-agent'),
            ));
        }

        // Replace
        $new_content = str_replace($search, $replace, $content);

        // Write
        $result = file_put_contents($file_path, $new_content);

        if ($result === false) {
            return new WP_Error('write_error', __('Could not write file', 'vdl-agent'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success'      => true,
            'file'         => $file,
            'replacements' => $count,
            'backup_id'    => $backup_id,
            'message'      => sprintf(__('%d replacement(s) made', 'vdl-agent'), $count),
        ));
    }

    /**
     * Create a backup of a file
     */
    private static function create_backup($file) {
        $theme_dir = self::get_theme_dir();
        $file_path = $theme_dir . '/' . $file;

        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found', 'vdl-agent'));
        }

        $backup_dir = self::get_backup_dir();

        // Create backup directory if needed
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);

            // Add index.php for security
            file_put_contents($backup_dir . 'index.php', '<?php // Silence is golden');

            // Add .htaccess to deny access
            file_put_contents($backup_dir . '.htaccess', 'deny from all');
        }

        // Generate backup ID
        $backup_id = date('Ymd_His') . '_' . sanitize_file_name(str_replace('/', '_', $file));

        // Copy file
        $backup_path = $backup_dir . $backup_id;
        $result = copy($file_path, $backup_path);

        if (!$result) {
            return new WP_Error('backup_error', __('Could not create backup', 'vdl-agent'));
        }

        // Store backup metadata
        $backups = get_option('vdl_theme_backups', array());
        $backups[$backup_id] = array(
            'file'       => $file,
            'created_at' => current_time('c'),
            'size'       => filesize($file_path),
        );

        // Keep only last 50 backups
        if (count($backups) > 50) {
            $oldest = array_slice(array_keys($backups), 0, -50);
            foreach ($oldest as $old_id) {
                @unlink($backup_dir . $old_id);
                unset($backups[$old_id]);
            }
        }

        update_option('vdl_theme_backups', $backups);

        return $backup_id;
    }

    /**
     * List backups
     */
    public static function list_backups($request) {
        $backups = get_option('vdl_theme_backups', array());

        $backup_list = array();
        foreach ($backups as $id => $data) {
            $backup_list[] = array_merge(array('id' => $id), $data);
        }

        // Sort by date descending
        usort($backup_list, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return rest_ensure_response(array(
            'success' => true,
            'backups' => $backup_list,
            'count'   => count($backup_list),
        ));
    }

    /**
     * Restore a backup
     */
    public static function restore_backup($request) {
        $backup_id = $request->get_param('id');

        $backups = get_option('vdl_theme_backups', array());

        if (!isset($backups[$backup_id])) {
            return new WP_Error('backup_not_found', __('Backup not found', 'vdl-agent'), array('status' => 404));
        }

        $backup_dir = self::get_backup_dir();
        $backup_path = $backup_dir . $backup_id;

        if (!file_exists($backup_path)) {
            return new WP_Error('backup_file_not_found', __('Backup file not found', 'vdl-agent'), array('status' => 404));
        }

        $theme_dir = self::get_theme_dir();
        $file = $backups[$backup_id]['file'];
        $file_path = $theme_dir . '/' . $file;

        // Create backup of current file before restore
        $pre_restore_backup = null;
        if (file_exists($file_path)) {
            $pre_restore_backup = self::create_backup($file);
        }

        // Restore
        $result = copy($backup_path, $file_path);

        if (!$result) {
            return new WP_Error('restore_error', __('Could not restore backup', 'vdl-agent'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success'            => true,
            'file'               => $file,
            'backup_id'          => $backup_id,
            'pre_restore_backup' => $pre_restore_backup,
            'message'            => __('Backup restored successfully', 'vdl-agent'),
        ));
    }

    /**
     * Deploy theme from ZIP URL
     */
    public static function deploy_theme($request) {
        $zip_url = $request->get_param('url');
        $theme_name = $request->get_param('theme_name');

        if (empty($zip_url)) {
            return new WP_Error('missing_url', __('ZIP URL is required', 'vdl-agent'), array('status' => 400));
        }

        // Download ZIP
        $tmp_file = download_url($zip_url);

        if (is_wp_error($tmp_file)) {
            return new WP_Error('download_error', $tmp_file->get_error_message(), array('status' => 500));
        }

        // Extract to themes directory
        $themes_dir = get_theme_root();
        $result = unzip_file($tmp_file, $themes_dir);

        // Clean up
        @unlink($tmp_file);

        if (is_wp_error($result)) {
            return new WP_Error('extract_error', $result->get_error_message(), array('status' => 500));
        }

        // Optionally activate theme
        if ($theme_name) {
            switch_theme($theme_name);
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Theme deployed successfully', 'vdl-agent'),
            'activated' => !empty($theme_name),
        ));
    }
}
