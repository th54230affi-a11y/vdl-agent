<?php
/**
 * VDL Agent Authentication
 *
 * Gère l'authentification API Key pour les requêtes REST
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Auth {

    /**
     * Vérifie l'authentification API Key
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function check_api_key($request) {
        $auth_header = $request->get_header('Authorization');

        if (empty($auth_header)) {
            return new WP_Error(
                'vdl_missing_auth',
                __('Authorization header is required', 'vdl-agent'),
                array('status' => 401)
            );
        }

        // Extract Bearer token
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return new WP_Error(
                'vdl_invalid_auth_format',
                __('Invalid authorization format. Use: Bearer YOUR_API_KEY', 'vdl-agent'),
                array('status' => 401)
            );
        }

        $provided_key = $matches[1];
        $stored_key = VDL_Agent::get_api_key();

        if (empty($stored_key)) {
            return new WP_Error(
                'vdl_no_api_key',
                __('API key not configured on this site', 'vdl-agent'),
                array('status' => 500)
            );
        }

        if (!hash_equals($stored_key, $provided_key)) {
            // Log failed attempt
            self::log_failed_attempt($request);

            return new WP_Error(
                'vdl_invalid_api_key',
                __('Invalid API key', 'vdl-agent'),
                array('status' => 401)
            );
        }

        // Rate limiting check
        $rate_limit = self::check_rate_limit($request);
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        return true;
    }

    /**
     * Vérifie le token de confirmation pour les opérations sensibles
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function check_confirm_token($request) {
        // First check API key
        $api_check = self::check_api_key($request);
        if (is_wp_error($api_check)) {
            return $api_check;
        }

        $confirm_header = $request->get_header('X-VDL-Confirm');

        if (empty($confirm_header)) {
            return new WP_Error(
                'vdl_missing_confirm',
                __('X-VDL-Confirm header is required for this operation', 'vdl-agent'),
                array('status' => 403)
            );
        }

        $stored_token = VDL_Agent::get_confirm_token();

        if (!hash_equals($stored_token, $confirm_header)) {
            return new WP_Error(
                'vdl_invalid_confirm',
                __('Invalid confirmation token', 'vdl-agent'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Check rate limiting
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    private static function check_rate_limit($request) {
        $ip = self::get_client_ip();
        $transient_key = 'vdl_rate_limit_' . md5($ip);

        $requests = get_transient($transient_key);

        if ($requests === false) {
            $requests = 0;
        }

        // Limit: 100 requests per minute
        $limit = apply_filters('vdl_agent_rate_limit', 100);

        if ($requests >= $limit) {
            return new WP_Error(
                'vdl_rate_limit_exceeded',
                __('Rate limit exceeded. Please try again later.', 'vdl-agent'),
                array('status' => 429)
            );
        }

        // Increment counter
        set_transient($transient_key, $requests + 1, MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Log failed authentication attempt
     *
     * @param WP_REST_Request $request
     */
    private static function log_failed_attempt($request) {
        $ip = self::get_client_ip();
        $endpoint = $request->get_route();

        error_log(sprintf(
            '[VDL Agent] Failed auth attempt from %s on %s',
            $ip,
            $endpoint
        ));

        // Track failed attempts for potential blocking
        $transient_key = 'vdl_failed_auth_' . md5($ip);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            $attempts = 0;
        }

        set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);

        // Block after 10 failed attempts
        if ($attempts >= 10) {
            set_transient('vdl_blocked_ip_' . md5($ip), true, HOUR_IN_SECONDS);
        }
    }

    /**
     * Check if IP is blocked
     *
     * @return bool
     */
    public static function is_ip_blocked() {
        $ip = self::get_client_ip();
        return (bool) get_transient('vdl_blocked_ip_' . md5($ip));
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    public static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];

                // Handle comma-separated list
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }

                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Generate new API key
     *
     * @return string
     */
    public static function regenerate_api_key() {
        $new_key = wp_generate_password(32, false);
        update_option('vdl_agent_api_key', $new_key);
        return $new_key;
    }

    /**
     * Generate new confirm token
     *
     * @return string
     */
    public static function regenerate_confirm_token() {
        $new_token = wp_generate_password(16, false);
        update_option('vdl_agent_confirm_token', $new_token);
        return $new_token;
    }
}
