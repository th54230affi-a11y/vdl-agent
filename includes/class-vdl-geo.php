<?php
/**
 * VDL Agent GEO (Generative Engine Optimization)
 *
 * Optimise la visibilite du site dans les reponses generees par les LLMs
 * (ChatGPT, Copilot, Perplexity, Google AI Overviews) :
 * - Schema JSON-LD enrichi (Article, Organization, FAQPage, Person)
 * - Timestamp "Derniere mise a jour" visible sur les articles
 * - Gestion du fichier llms.txt
 *
 * @package VDL_Agent
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class VDL_GEO {

    /**
     * Initialize GEO features
     */
    public static function init() {
        // Inject JSON-LD schema in <head>
        add_action('wp_head', array(__CLASS__, 'inject_schema_jsonld'), 5);

        // Display "Last updated" timestamp on single posts
        add_filter('the_content', array(__CLASS__, 'prepend_last_updated'), 1);

        // Serve llms.txt from WordPress (rewrite rule)
        add_action('init', array(__CLASS__, 'register_llms_txt_rewrite'));
        add_action('template_redirect', array(__CLASS__, 'serve_llms_txt'));
    }

    // ──────────────────────────────────────────────────────────
    //  1. SCHEMA JSON-LD
    // ──────────────────────────────────────────────────────────

    /**
     * Inject structured data (JSON-LD) in the <head> section.
     *
     * On every page: Organization schema.
     * On single posts/pages: Article schema with author (Person).
     * On posts with FAQ-style headings: FAQPage schema.
     */
    public static function inject_schema_jsonld() {
        // Always output Organization
        self::output_organization_schema();

        // Single post/page: Article + optional FAQ
        if (is_singular()) {
            self::output_article_schema();
            self::output_faq_schema();
        }
    }

    /**
     * Organization schema — brand identity for LLMs.
     */
    private static function output_organization_schema() {
        $site_name = get_bloginfo('name');
        $site_url  = home_url('/');
        $logo_url  = '';

        // Try to get custom logo
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo_data) {
                $logo_url = $logo_data[0];
            }
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $site_name,
            'url'      => $site_url,
        );

        if ($logo_url) {
            $schema['logo'] = $logo_url;
        }

        // Description from tagline
        $description = get_bloginfo('description');
        if ($description) {
            $schema['description'] = $description;
        }

        // Social profiles (stored in vdl_geo_social option)
        $social = get_option('vdl_geo_social', array());
        if (!empty($social)) {
            $schema['sameAs'] = array_values(array_filter($social));
        }

        self::render_jsonld($schema);
    }

    /**
     * Article / BlogPosting schema with Person author.
     */
    private static function output_article_schema() {
        global $post;

        if (!$post) {
            return;
        }

        // Skip if Rank Math or Yoast already outputs Article schema
        if (self::seo_plugin_handles_article_schema()) {
            return;
        }

        $author       = get_the_author_meta('display_name', $post->post_author);
        $author_url   = get_the_author_meta('url', $post->post_author);
        $author_desc  = get_the_author_meta('description', $post->post_author);
        $published    = get_the_date('c', $post);
        $modified     = get_the_modified_date('c', $post);
        $excerpt      = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(strip_tags($post->post_content), 30);
        $thumbnail_id = get_post_thumbnail_id($post);
        $thumbnail    = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'large') : '';

        $schema = array(
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => get_the_title($post),
            'description'   => $excerpt,
            'datePublished' => $published,
            'dateModified'  => $modified,
            'url'           => get_permalink($post),
            'author'        => array(
                '@type' => 'Person',
                'name'  => $author,
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
                'url'   => home_url('/'),
            ),
        );

        if ($author_url) {
            $schema['author']['url'] = $author_url;
        }
        if ($author_desc) {
            $schema['author']['description'] = $author_desc;
        }
        if ($thumbnail) {
            $schema['image'] = $thumbnail;
        }

        // Add wordCount for LLM context
        $word_count = str_word_count(strip_tags($post->post_content));
        if ($word_count > 0) {
            $schema['wordCount'] = $word_count;
        }

        self::render_jsonld($schema);
    }

    /**
     * FAQPage schema — auto-extracted from content.
     *
     * Finds H2/H3 headings that end with "?" and captures
     * the following paragraph as the answer.
     */
    private static function output_faq_schema() {
        global $post;

        if (!$post) {
            return;
        }

        $content = $post->post_content;
        $faqs    = self::extract_faq_from_content($content);

        if (empty($faqs)) {
            return;
        }

        $main_entity = array();
        foreach ($faqs as $faq) {
            $main_entity[] = array(
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $faq['answer'],
                ),
            );
        }

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $main_entity,
        );

        self::render_jsonld($schema);
    }

    /**
     * Extract FAQ pairs from post content.
     *
     * Looks for H2/H3 headings ending with "?" and captures
     * the immediately following paragraph(s) as the answer.
     *
     * @param string $content Raw post content
     * @return array Array of ['question' => ..., 'answer' => ...]
     */
    public static function extract_faq_from_content($content) {
        $faqs = array();

        // Match H2/H3 headings that end with "?"
        // Capture the text between closing heading tag and next heading or end
        $pattern = '/<h[23][^>]*>(.*?\?)\s*<\/h[23]>(.*?)(?=<h[23]|$)/si';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $faqs;
        }

        foreach ($matches as $match) {
            $question = wp_strip_all_tags(trim($match[1]));
            $answer_raw = trim($match[2]);

            // Extract the first paragraph from the answer block
            if (preg_match('/<p[^>]*>(.*?)<\/p>/si', $answer_raw, $p_match)) {
                $answer = wp_strip_all_tags(trim($p_match[1]));
            } else {
                // Fallback: strip tags and take first 300 chars
                $answer = wp_strip_all_tags($answer_raw);
                $answer = mb_substr(trim($answer), 0, 300);
            }

            if (mb_strlen($question) > 10 && mb_strlen($answer) > 20) {
                $faqs[] = array(
                    'question' => $question,
                    'answer'   => $answer,
                );
            }
        }

        // Limit to 10 FAQ items (Google recommendation)
        return array_slice($faqs, 0, 10);
    }

    /**
     * Check if Rank Math or Yoast already handles Article schema.
     */
    private static function seo_plugin_handles_article_schema() {
        // Rank Math
        if (class_exists('RankMath')) {
            $schema_types = get_post_meta(get_the_ID(), 'rank_math_rich_snippet', true);
            if ($schema_types === 'article' || empty($schema_types)) {
                // Rank Math outputs Article schema by default
                return true;
            }
        }

        // Yoast SEO
        if (defined('WPSEO_VERSION')) {
            return true;
        }

        return false;
    }

    /**
     * Render a JSON-LD script tag.
     */
    private static function render_jsonld($schema) {
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo "\n</script>\n";
    }

    // ──────────────────────────────────────────────────────────
    //  2. TIMESTAMP "DERNIERE MISE A JOUR"
    // ──────────────────────────────────────────────────────────

    /**
     * Prepend a "Last updated" date at the top of single posts.
     *
     * Only shows if the post has been modified after publication
     * AND the modification is more than 24h after the original publish date.
     *
     * @param string $content Post content
     * @return string Modified content
     */
    public static function prepend_last_updated($content) {
        if (!is_singular('post') || !is_main_query()) {
            return $content;
        }

        // Disable via option
        if (get_option('vdl_geo_disable_updated_date', false)) {
            return $content;
        }

        global $post;

        if (!$post) {
            return $content;
        }

        $published = get_the_date('U', $post);
        $modified  = get_the_modified_date('U', $post);

        // Only show if modified at least 24h after publication
        if (($modified - $published) < 86400) {
            return $content;
        }

        $date_str = get_the_modified_date('j F Y', $post);

        $badge = '<p class="vdl-last-updated" style="'
            . 'font-size: 0.85em; color: #666; margin-bottom: 1em; '
            . 'padding: 6px 12px; background: #f8f9fa; border-left: 3px solid #0073aa; '
            . 'border-radius: 2px;">'
            . '<strong>Dernière mise à jour :</strong> '
            . esc_html($date_str)
            . '</p>';

        return $badge . $content;
    }

    // ──────────────────────────────────────────────────────────
    //  3. LLMS.TXT
    // ──────────────────────────────────────────────────────────

    /**
     * Register rewrite rule for /llms.txt
     */
    public static function register_llms_txt_rewrite() {
        add_rewrite_rule('^llms\.txt$', 'index.php?vdl_llms_txt=1', 'top');
        add_filter('query_vars', function ($vars) {
            $vars[] = 'vdl_llms_txt';
            return $vars;
        });
    }

    /**
     * Serve /llms.txt content.
     *
     * Generates a Markdown file listing the site's most important pages
     * with descriptions, so AI crawlers can identify key content.
     */
    public static function serve_llms_txt() {
        if (!get_query_var('vdl_llms_txt')) {
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $site_name = get_bloginfo('name');
        $site_desc = get_bloginfo('description');
        $site_url  = home_url('/');

        $output = "# {$site_name}\n\n";
        if ($site_desc) {
            $output .= "> {$site_desc}\n\n";
        }
        $output .= "## Pages principales\n\n";

        // Homepage
        $output .= "- [{$site_name} — Accueil]({$site_url}): Page d'accueil du site\n";

        // Get popular/pillar pages — custom option or fallback to most commented/viewed
        $pillar_ids = get_option('vdl_geo_pillar_pages', array());

        if (!empty($pillar_ids)) {
            foreach ($pillar_ids as $pid) {
                $p = get_post($pid);
                if ($p && $p->post_status === 'publish') {
                    $title   = $p->post_title;
                    $url     = get_permalink($p);
                    $excerpt = has_excerpt($p)
                        ? get_the_excerpt($p)
                        : wp_trim_words(strip_tags($p->post_content), 20, '...');
                    $output .= "- [{$title}]({$url}): {$excerpt}\n";
                }
            }
        } else {
            // Auto-generate: top 30 posts by comment count, then recent
            $top_posts = get_posts(array(
                'post_type'      => array('post', 'page'),
                'post_status'    => 'publish',
                'posts_per_page' => 30,
                'orderby'        => 'comment_count',
                'order'          => 'DESC',
            ));

            foreach ($top_posts as $p) {
                $title   = $p->post_title;
                $url     = get_permalink($p);
                $excerpt = has_excerpt($p)
                    ? get_the_excerpt($p)
                    : wp_trim_words(strip_tags($p->post_content), 20, '...');
                $output .= "- [{$title}]({$url}): {$excerpt}\n";
            }
        }

        $output .= "\n## Informations\n\n";
        $output .= "- Ce site est maintenu par l'equipe de {$site_name}\n";
        $output .= "- Derniere generation : " . current_time('Y-m-d') . "\n";

        echo $output;
        exit;
    }

    // ──────────────────────────────────────────────────────────
    //  4. REST API ENDPOINTS (for remote management)
    // ──────────────────────────────────────────────────────────

    /**
     * Register GEO REST API routes.
     * Called from VDL_API::register_routes().
     */
    public static function register_routes($namespace) {
        // Get GEO config
        register_rest_route($namespace, '/geo/config', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'api_get_config'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));

        // Set pillar pages for llms.txt
        register_rest_route($namespace, '/geo/pillar-pages', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'api_set_pillar_pages'),
            'permission_callback' => array('VDL_Auth', 'check_confirm_token'),
        ));

        // Extract FAQ from a specific post (diagnostic)
        register_rest_route($namespace, '/geo/faq/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'api_get_faq'),
            'permission_callback' => array('VDL_Auth', 'check_api_key'),
        ));
    }

    /**
     * API: Get GEO configuration status.
     */
    public static function api_get_config($request) {
        $pillar_pages = get_option('vdl_geo_pillar_pages', array());
        $social       = get_option('vdl_geo_social', array());

        return rest_ensure_response(array(
            'success' => true,
            'geo'     => array(
                'schema_jsonld'     => true,
                'last_updated_date' => !get_option('vdl_geo_disable_updated_date', false),
                'llms_txt'          => home_url('/llms.txt'),
                'pillar_pages'      => count($pillar_pages),
                'social_profiles'   => $social,
                'faq_auto_extract'  => true,
            ),
        ));
    }

    /**
     * API: Set pillar page IDs for llms.txt.
     */
    public static function api_set_pillar_pages($request) {
        $ids = $request->get_param('ids');

        if (!is_array($ids)) {
            return new WP_Error('invalid_ids', 'ids must be an array of post IDs', array('status' => 400));
        }

        // Validate all IDs are published posts/pages
        $valid_ids = array();
        foreach ($ids as $id) {
            $p = get_post((int) $id);
            if ($p && $p->post_status === 'publish') {
                $valid_ids[] = (int) $id;
            }
        }

        update_option('vdl_geo_pillar_pages', $valid_ids);

        return rest_ensure_response(array(
            'success'      => true,
            'pillar_pages' => count($valid_ids),
            'ids'          => $valid_ids,
        ));
    }

    /**
     * API: Extract FAQ pairs from a specific post.
     */
    public static function api_get_faq($request) {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }

        $faqs = self::extract_faq_from_content($post->post_content);

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'title'   => $post->post_title,
            'faqs'    => $faqs,
            'count'   => count($faqs),
        ));
    }
}
