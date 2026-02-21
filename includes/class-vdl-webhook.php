<?php
/**
 * VDL Agent — WiseWand Webhook Handler
 *
 * Reçoit les webhooks de WiseWand après génération d'article.
 * Synchronise automatiquement les meta SEO (meta_title, meta_description)
 * dans le plugin SEO actif (Rank Math, Yoast, etc.).
 *
 * Flux :
 * 1. WiseWand génère un article
 * 2. WiseWand publie en draft sur WordPress (via connexion WP)
 * 3. WiseWand appelle ce webhook avec le contenu de l'article
 * 4. Ce handler extrait meta_title + meta_description du payload
 * 5. Cherche le post WordPress correspondant (par titre ou post_id)
 * 6. Écrit les meta dans Rank Math / Yoast via VDL_SEO::get_write_meta_key()
 *
 * @package VDL_Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_Webhook {

    /**
     * Option name for webhook secret
     */
    const OPTION_SECRET = 'vdl_agent_webhook_secret';

    /**
     * Handle incoming WiseWand webhook
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_wisewand($request) {
        $body = $request->get_json_params();

        if (empty($body)) {
            $body = $request->get_body_params();
        }

        if (empty($body)) {
            return new WP_Error(
                'vdl_webhook_empty',
                __('Empty webhook payload', 'vdl-agent'),
                array('status' => 400)
            );
        }

        // Log incoming webhook for debugging
        error_log('[VDL Webhook] Received WiseWand webhook: ' . wp_json_encode(array_keys($body)));

        // Extract meta from various possible payload structures
        $meta_title = '';
        $meta_description = '';
        $wp_post_id = null;
        $article_title = '';

        // WiseWand peut envoyer les données dans différentes structures
        // Structure 1 : { output: { meta_title, meta_description, publishwordpress_postid } }
        if (isset($body['output'])) {
            $output = $body['output'];
            $meta_title = isset($output['meta_title']) ? sanitize_text_field($output['meta_title']) : '';
            $meta_description = isset($output['meta_description']) ? sanitize_text_field($output['meta_description']) : '';
            $wp_post_id = isset($output['publishwordpress_postid']) ? (int) $output['publishwordpress_postid'] : null;
        }

        // Structure 2 : directement dans le body { meta_title, meta_description }
        if (empty($meta_title) && isset($body['meta_title'])) {
            $meta_title = sanitize_text_field($body['meta_title']);
        }
        if (empty($meta_description) && isset($body['meta_description'])) {
            $meta_description = sanitize_text_field($body['meta_description']);
        }

        // Post ID depuis le body directement
        if (!$wp_post_id && isset($body['publishwordpress_postid'])) {
            $wp_post_id = (int) $body['publishwordpress_postid'];
        }
        if (!$wp_post_id && isset($body['post_id'])) {
            $wp_post_id = (int) $body['post_id'];
        }
        if (!$wp_post_id && isset($body['wordpress_post_id'])) {
            $wp_post_id = (int) $body['wordpress_post_id'];
        }

        // Titre de l'article (pour recherche par titre si pas de post_id)
        if (isset($body['title'])) {
            $article_title = sanitize_text_field($body['title']);
        } elseif (isset($body['subject'])) {
            $article_title = sanitize_text_field($body['subject']);
        }

        // Focus keyword
        $focus_keyword = '';
        if (isset($body['target_keyword'])) {
            $focus_keyword = sanitize_text_field($body['target_keyword']);
        } elseif (isset($body['keyword'])) {
            $focus_keyword = sanitize_text_field($body['keyword']);
        }

        // Si pas de meta, rien à faire
        if (empty($meta_title) && empty($meta_description) && empty($focus_keyword)) {
            error_log('[VDL Webhook] No meta found in payload, logging full body for debug');
            error_log('[VDL Webhook] Body: ' . wp_json_encode($body));

            return rest_ensure_response(array(
                'success' => true,
                'action'  => 'logged',
                'message' => 'No SEO meta found in payload — webhook logged for debugging',
            ));
        }

        // Trouver le post WordPress
        if (!$wp_post_id && !empty($article_title)) {
            $wp_post_id = self::find_post_by_title($article_title);
        }

        if (!$wp_post_id) {
            error_log('[VDL Webhook] Could not find WordPress post for webhook');
            error_log('[VDL Webhook] Title: ' . $article_title);

            return rest_ensure_response(array(
                'success' => false,
                'action'  => 'post_not_found',
                'message' => 'Could not find WordPress post — meta saved for later sync',
                'meta'    => array(
                    'meta_title'       => $meta_title,
                    'meta_description' => $meta_description,
                    'article_title'    => $article_title,
                ),
            ));
        }

        // Vérifier que le post existe
        $post = get_post($wp_post_id);
        if (!$post) {
            return rest_ensure_response(array(
                'success' => false,
                'action'  => 'post_not_found',
                'message' => "Post ID $wp_post_id not found in WordPress",
            ));
        }

        // Écrire les meta SEO
        $updated = array();
        $seo_plugin = self::detect_seo_plugin();

        if (!empty($meta_title)) {
            $meta_key = self::get_write_meta_key('seo_title');
            update_post_meta($wp_post_id, $meta_key, $meta_title);
            $updated['seo_title'] = array('value' => $meta_title, 'meta_key' => $meta_key);
        }

        if (!empty($meta_description)) {
            $meta_key = self::get_write_meta_key('meta_description');
            update_post_meta($wp_post_id, $meta_key, $meta_description);
            $updated['meta_description'] = array('value' => $meta_description, 'meta_key' => $meta_key);
        }

        if (!empty($focus_keyword)) {
            $meta_key = self::get_write_meta_key('focus_keyword');
            update_post_meta($wp_post_id, $meta_key, $focus_keyword);
            $updated['focus_keyword'] = array('value' => $focus_keyword, 'meta_key' => $meta_key);
        }

        error_log(sprintf(
            '[VDL Webhook] SEO meta synced for post #%d (%s) — %s',
            $wp_post_id,
            $post->post_title,
            implode(', ', array_keys($updated))
        ));

        return rest_ensure_response(array(
            'success'    => true,
            'action'     => 'seo_meta_synced',
            'post_id'    => $wp_post_id,
            'post_title' => $post->post_title,
            'seo_plugin' => $seo_plugin,
            'updated'    => $updated,
        ));
    }

    /**
     * Authenticate webhook via secret token
     *
     * Le webhook WiseWand envoie le password dans le header Authorization
     * ou dans le body du payload.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function check_webhook_auth($request) {
        $stored_secret = get_option(self::OPTION_SECRET, '');

        // Si pas de secret configuré, accepter tous les webhooks (mode ouvert)
        // Utile pour le premier setup et debugging
        if (empty($stored_secret)) {
            return true;
        }

        // Vérifier Authorization header
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header)) {
            // Bearer token ou token direct
            $token = $auth_header;
            if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
                $token = $matches[1];
            }
            if (hash_equals($stored_secret, $token)) {
                return true;
            }
        }

        // Vérifier dans le body (password)
        $body = $request->get_json_params();
        if (isset($body['password']) && hash_equals($stored_secret, $body['password'])) {
            return true;
        }
        if (isset($body['secret']) && hash_equals($stored_secret, $body['secret'])) {
            return true;
        }

        // Vérifier query param
        $token_param = $request->get_param('token');
        if (!empty($token_param) && hash_equals($stored_secret, $token_param)) {
            return true;
        }

        return new WP_Error(
            'vdl_webhook_unauthorized',
            __('Invalid webhook secret', 'vdl-agent'),
            array('status' => 401)
        );
    }

    /**
     * Find a WordPress post by its title (recent first)
     *
     * @param string $title
     * @return int|null Post ID or null
     */
    private static function find_post_by_title($title) {
        global $wpdb;

        // Chercher le post le plus récent avec ce titre exact
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_title = %s
             AND post_status IN ('publish', 'draft', 'pending', 'future')
             AND post_type = 'post'
             ORDER BY post_date DESC
             LIMIT 1",
            $title
        ));

        return $post_id ? (int) $post_id : null;
    }

    /**
     * Detect active SEO plugin
     * Delegates to VDL_SEO if available, otherwise detects independently.
     *
     * @return string Plugin identifier (rank_math, yoast, aioseo, seopress, none)
     */
    private static function detect_seo_plugin() {
        if (class_exists('VDL_SEO') && method_exists('VDL_SEO', 'detect_seo_plugin')) {
            return VDL_SEO::detect_seo_plugin();
        }

        // Fallback detection
        if (class_exists('RankMath')) return 'rank_math';
        if (defined('WPSEO_VERSION')) return 'yoast';
        if (defined('AIOSEO_VERSION')) return 'aioseo';
        if (defined('SEOPRESS_VERSION')) return 'seopress';
        return 'none';
    }

    /**
     * Get the write meta key for a given field, based on active SEO plugin.
     * Same logic as VDL_SEO::get_write_meta_key() but independent.
     *
     * @param string $field
     * @return string meta_key
     */
    private static function get_write_meta_key($field) {
        $plugin = self::detect_seo_plugin();

        $map = array(
            'rank_math' => array(
                'seo_title'        => 'rank_math_title',
                'meta_description' => 'rank_math_description',
                'focus_keyword'    => 'rank_math_focus_keyword',
            ),
            'yoast' => array(
                'seo_title'        => '_yoast_wpseo_title',
                'meta_description' => '_yoast_wpseo_metadesc',
                'focus_keyword'    => '_yoast_wpseo_focuskw',
            ),
            'aioseo' => array(
                'seo_title'        => '_aioseo_title',
                'meta_description' => '_aioseo_description',
                'focus_keyword'    => '_aioseo_keyphrases',
            ),
            'seopress' => array(
                'seo_title'        => '_seopress_titles_title',
                'meta_description' => '_seopress_titles_desc',
                'focus_keyword'    => '_seopress_analysis_target_kw',
            ),
        );

        if (isset($map[$plugin][$field])) {
            return $map[$plugin][$field];
        }

        // Fallback
        return '_vdl_' . $field;
    }

    /**
     * Generate or retrieve webhook secret
     *
     * @return string
     */
    public static function get_or_create_secret() {
        $secret = get_option(self::OPTION_SECRET, '');
        if (empty($secret)) {
            $secret = wp_generate_password(32, false);
            update_option(self::OPTION_SECRET, $secret);
        }
        return $secret;
    }
}
