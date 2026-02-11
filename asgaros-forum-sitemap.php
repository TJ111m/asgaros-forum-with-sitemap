<?php
/**
 * Asgaros Forum XML Sitemap
 * 放置到 wp-content/mu-plugins/ 目录，无需激活即可生效
 *
 * 提供在线可访问的 XML Sitemap 地址，供提交到 Google Search Console
 * 实时生成，包含： forum 首页、分类、版块、主题、帖子
 * 
 * 访问地址:
 *   - https://你的域名/wp-json/asgaros/v1/forum-sitemap （REST 接口，放好即用）
 *   - https://你的域名/forum-sitemap.xml （需在 设置→固定链接 点保存以刷新重写规则）
 *
 * 缓存：结果缓存在 1 小时。加 ?nocache=1 可刷新
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    register_rest_route('asgaros/v1', '/forum-sitemap', [
        'methods' => 'GET',
        'callback' => 'af_sitemap_rest_callback',
        'permission_callback' => '__return_true',
    ]);
});

// 添加 /forum-sitemap.xml 重写，便于提交到 Google（首次需在 设置→固定链接 点保存）
add_action('init', function() {
    add_rewrite_rule('^forum-sitemap\.xml$', 'index.php?af_sitemap=1', 'top');
});

add_filter('query_vars', function($vars) {
    $vars[] = 'af_sitemap';
    return $vars;
});

add_action('template_redirect', function() {
    if (get_query_var('af_sitemap')) {
        af_sitemap_output_raw();
        exit;
    }
});

function af_sitemap_rest_callback($request) {
    $result = af_sitemap_output_raw(true);
    if (is_wp_error($result)) {
        return $result;
    }
    $response = new WP_REST_Response($result, 200);
    $response->header('Content-Type', 'application/xml; charset=UTF-8');
    $response->header('X-Robots-Tag', 'noindex');
    return $response;
}

/**
 * 生成并输出 XML Sitemap
 * @param bool $return 为 true 时返回 XML 字符串，否则直接 echo 并 header
 * @return string|WP_Error
 */
function af_sitemap_output_raw($return = false) {
    $af = af_sitemap_require_asgaros();
    if (is_wp_error($af)) {
        if ($return) {
            return $af;
        }
        status_header(503);
        echo '<?xml version="1.0" encoding="UTF-8"?><error>' . esc_html($af->get_error_message()) . '</error>';
        return null;
    }

    $use_cache = true;
    $nocache = (isset($_GET['nocache']) && $_GET['nocache'] === '1') || (defined('REST_REQUEST') && isset($_REQUEST['nocache']) && $_REQUEST['nocache'] === '1');
    if ($nocache) {
        delete_transient('af_forum_sitemap_xml');
        $use_cache = false;
    }
    $cache_key = 'af_forum_sitemap_xml';
    if ($use_cache) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            if ($return) {
                return $cached;
            }
            af_sitemap_send_headers();
            echo $cached;
            return null;
        }
    }

    $xml = af_sitemap_build($af);
    if ($use_cache) {
        set_transient($cache_key, $xml, HOUR_IN_SECONDS);
    }

    if ($return) {
        return $xml;
    }
    af_sitemap_send_headers();
    echo $xml;
    return null;
}

function af_sitemap_send_headers() {
    status_header(200);
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex', true);
}

function af_sitemap_require_asgaros() {
    global $asgarosforum;
    if (!isset($asgarosforum) || !class_exists('AsgarosForum')) {
        return new WP_Error('asgaros_not_loaded', 'Asgaros Forum is not active', ['status' => 503]);
    }
    return $asgarosforum;
}

function af_sitemap_build($af) {
    global $wpdb;

    // 确保 Asgaros Forum 已加载 options 和 taxonomy
    if (method_exists($af, 'load_options')) {
        $af->load_options();
    }
    if (!taxonomy_exists('asgarosforum-category')) {
        if (class_exists('AsgarosForumContent')) {
            AsgarosForumContent::initialize_taxonomy();
        }
    }

    $rewrite = $af->rewrite;
    $tables = $af->tables;

    // Forum 首页 - 使用 get_link('home')，options 键为 location
    $forum_page_id = isset($af->options['location']) ? (int) $af->options['location'] : 0;
    $home_link = $rewrite->get_link('home', false, false, '', false);
    $forum_base = $home_link ?: ($forum_page_id ? get_permalink($forum_page_id) : home_url('/'));

    $urls = [];

    // 1. Forum 首页
    $urls[] = [
        'loc' => $forum_base,
        'lastmod' => current_time('Y-m-d'),
        'changefreq' => 'hourly',
        'priority' => '1.0',
    ];

    // 2. 分类（Categories）- Asgaros 分类在 taxonomy，无独立页面，首页已涵盖

    // 3. 版块（Forums）- forum_status 为 'normal' 或 'approval'，不是 'open'
    $forums = $wpdb->get_results("SELECT id FROM {$tables->forums} WHERE forum_status != 'closed' ORDER BY sort ASC, id ASC");
    foreach ($forums as $f) {
        $loc = $rewrite->get_link('forum', $f->id, false, '', false);
        if (!$loc) {
            $loc = add_query_arg(['view' => $af->options['view_name_forum'] ?? 'forum', 'id' => $f->id], $forum_base);
        }
        $urls[] = [
            'loc' => $loc,
            'lastmod' => current_time('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => '0.8',
        ];
    }

    // 4. 主题（Topics）及帖子（Posts）- topics 表无 date 列，用帖子最新日期
    $topics = $wpdb->get_results("SELECT t.id, t.parent_id, (SELECT MAX(p.date) FROM {$tables->posts} p WHERE p.parent_id = t.id) AS last_date FROM {$tables->topics} t WHERE t.approved = 1 ORDER BY t.id ASC");

    foreach ($topics as $topic) {
        $topic_link = $rewrite->get_link('topic', $topic->id, false, '', false);
        if (!$topic_link) {
            $topic_link = add_query_arg(['view' => $af->options['view_name_topic'] ?? 'topic', 'id' => $topic->id], $forum_base);
        }
        $lastmod = !empty($topic->last_date) && $topic->last_date !== '1000-01-01 00:00:00'
            ? date('Y-m-d', strtotime($topic->last_date))
            : current_time('Y-m-d');
        $urls[] = [
            'loc' => $topic_link,
            'lastmod' => $lastmod,
            'changefreq' => 'weekly',
            'priority' => '0.7',
        ];

        // 帖子
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, date FROM {$tables->posts} WHERE parent_id = %d ORDER BY id ASC",
            $topic->id
        ));
        foreach ($posts as $post) {
            $post_link = $rewrite->get_post_link($post->id, $topic->id);
            if ($post_link && is_string($post_link)) {
                $post_link = html_entity_decode($post_link);
            }
            if (!$post_link) {
                $post_link = add_query_arg(['view' => 'post', 'id' => $post->id], $forum_base);
            }
            $post_lastmod = !empty($post->date) && $post->date !== '1000-01-01 00:00:00'
                ? date('Y-m-d', strtotime($post->date))
                : $lastmod;
            $urls[] = [
                'loc' => $post_link,
                'lastmod' => $post_lastmod,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }
    }

    return af_sitemap_render_xml($urls);
}

function af_sitemap_render_xml($urls) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . esc_url($u['loc']) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . esc_html($u['lastmod']) . '</lastmod>' . "\n";
        $xml .= '    <changefreq>' . esc_html($u['changefreq']) . '</changefreq>' . "\n";
        $xml .= '    <priority>' . esc_html($u['priority']) . '</priority>' . "\n";
        $xml .= '  </url>' . "\n";
    }
    $xml .= '</urlset>';
    return $xml;
}
