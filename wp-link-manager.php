<?php
/*
Plugin Name: WP LinkManager
Version: 0.1.0
Plugin URI: https://www.huangwenchao.com.cn/
Description: Manage the out-link in your posts, to discover link injection on post content.
Author: Alfred Huang
Author URI: https://www.huangwenchao.com.cn/
*/

define('WLM_DOMAIN', 'WLM_DOMAIN');
define('WLM__PLUGIN_URL', plugin_dir_url(__FILE__));
define('WLM__PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Activation callbacks
 */
register_activation_hook(__FILE__, function() {
    // Set mode to black list by default
    update_option('wlm_mode', 'black');
});

/**
 * Deactivation callbacks
 */
register_deactivation_hook(__FILE__, function() {
});

/**
 *
 * @link: https://codex.wordpress.org/Creating_Options_Pages
 */
add_action('admin_menu', function() {

    add_menu_page(
        __('External Link Manager', WLM_DOMAIN),
        __('WLM Settings', WLM_DOMAIN),
        'manage_options',  // capability
        'wlm_settings',  // menu_slug
        'wlm_settings_page',  // function
        'dashicons-editor-unlink',
        81
    );

//    add_options_page(
//        __('External Link Manager', WLM_DOMAIN),
//        __('WLM Settings', WLM_DOMAIN),
//        'manage_options',  // capability
//        'wlm_settings',  // menu_slug
//        'wlm_settings_page'  // function
//    );

});

/**
 * Register the setting fields.
 */
add_action('admin_init', function() {
    register_setting('wlm-options-group', 'wlm_mode');
    register_setting('wlm-options-group', 'wlm_domain_white_list');
    register_setting('wlm-options-group', 'wlm_domain_black_list');
});

/**
 * Callback to produce the option page.
 */
function wlm_settings_page() {
    require __DIR__.'/option-page.php';
}

/**
 * Parsing the WLM white list and black list from options.
 */
function parse_wlm_rule_lists() {

    /**
     * Enqueues the admin script and styles.
     */
    if(is_admin()) {
        wp_enqueue_style('wlm', WLM__PLUGIN_URL.'_inc/wp-link-manager.css');
        wp_enqueue_script('wlm', WLM__PLUGIN_URL.'_inc/wp-link-manager.js', array('jquery'));
    }

    global $wlm_white_list;
    $wlm_white_list = array();
    $opt_white = get_option('wlm_domain_white_list', '');
    foreach(explode("\n", $opt_white) as $line) {
        $line = trim($line);
        if($line) $wlm_white_list []= $line;
    }

    global $wlm_black_list;
    $wlm_black_list = array();
    $opt_black = get_option('wlm_domain_black_list', '');
    foreach(explode("\n", $opt_black) as $line) {
        $line = trim($line);
        if($line) $wlm_black_list []= $line;
    }

}
add_action('admin_init', 'parse_wlm_rule_lists');


// Domain types.
define('WLM_LINK_TYPE_SELF', 0);
define('WLM_LINK_TYPE_UNKNOWN', 1);
define('WLM_LINK_TYPE_WHITE', 2);
define('WLM_LINK_TYPE_BLACK', -1);

/**
 * Judge the domain type.
 * @param $domain
 * @return int
 */
function wlm_judge_domain_type($domain) {

    global $wlm_white_list, $wlm_black_list;

    // First, black list.
    foreach($wlm_black_list as $rule) {
        $regex = '/^'.str_replace('*', '[\.\w\d\-_]+', $rule).'$/i';
        if(preg_match($regex, $domain)) {
            return WLM_LINK_TYPE_BLACK;
        }
    }

    // Then, white list.
    foreach($wlm_white_list as $rule) {
        $regex = '/^'.str_replace('*', '[\.\w\d\-_]+', $rule).'$/i';
        if(preg_match($regex, $domain)) {
            return WLM_LINK_TYPE_WHITE;
        }
    }

    // Finally, self link.
    if($_SERVER['HTTP_HOST'] == $domain) return WLM_LINK_TYPE_SELF;

    // Otherwise, judge as unkown.
    return WLM_LINK_TYPE_UNKNOWN;

}


/**
 * Judge if the domain is allowed by the strategy.
 * @param string $domain
 * @return bool
 */
function wlm_is_domain_allowed($domain) {

    $domain_type = wlm_judge_domain_type($domain);

    if($domain_type === WLM_LINK_TYPE_BLACK) return false;
    elseif($domain_type === WLM_LINK_TYPE_WHITE) return true;
    elseif($domain_type === WLM_LINK_TYPE_SELF) return true;
    elseif($domain_type === WLM_LINK_TYPE_UNKNOWN) {
        // if mode == 'black', allow all non-black domains.
        // if mode == 'white', deny all non-white and non-self domains.
        return get_option('wlm_mode') == 'black';
    }

    return false;

}

/**
 * Matches the link position in the content text.
 * @param string $content: The full text to search
 * @param string|array $domains: The object domains matching
 *      default: use all suspected domains list.
 * @param string $marked_content returns the content,
 *      with its matched <a> tag replaced of {% wlm_link <index> %}
 *      content, where index equals the result array list index
 *      starts at 0.
 * @return array: Returns the resulting objects of all matches:
 * Each element contains:
 * array(
 *      'markup' => the whole content of the tag.
 *      'url' => the matching url, i.e. the href attribute of the <a> tag.
 *      'domain' => the matching domain
 *      'attributes' => the attributes of the <a> tag.
 *      'content' => the content of the <a> tag.
 * )
 */
function wlm_match_domain($content, $domains=null, &$marked_content='') {

    include_once 'phpQuery/phpQuery.php';

    // Normalize domain type
    if(is_string($domains)) $domain = explode('|', $domains);

    // Scratch all the href attribute of <a> tags.
    preg_match_all(
        "/<\\s*a[^<>]+href=[\"']([^\"']+)[\"'].+?(?:\\/>|<\\/\\s*a\\s*>)/ims",
        $content, $matches, PREG_PATTERN_ORDER
    );

    $result = array();

    // Loops into each link in the post.
    for($i = 1; $i < sizeof($matches[1]); ++$i) {

        // Parsing the link info.
        $markup = $matches[0][$i];
        $url = $matches[1][$i];
        $url_info = parse_url($url);

        // No scheme or no hosts links are treated as safe.
        if(!isset($url_info['scheme']) || !isset($url_info['host'])) continue;

        $scheme = $url_info['scheme'];  // http or https
        $host = @$url_info['host'];  // domain name and port
        $path = @$url_info['path'] ?: '/';

        // Host in specified domains or not allowed by WLM strategy.
        $is_host_matched = $domains && in_array($host, $domains)
            || !$domains && !wlm_is_domain_allowed($host);

        // If matched, parse the link info.
        if($is_host_matched) {
            // phpQuery document
            phpQuery::newDocument($markup);
            $a = pq('a');
            $result []= array(
                'markup' => $markup,
                'url' => $url,
                'domain' => $host,
                'attributes' => $a->attr('*'),
                'content' => $a->html(),
            );
        }

    }

    // Replace the $marked_content as number tags.
    $marked_content = $content;
    foreach($result as $i => $item) {
        $pos = strpos($marked_content, $item['markup']);
        if($pos !== false) {
            $marked_content = substr_replace(
                $marked_content,
                "{% wlm_link $i %}",
                $pos,
                strlen($item['markup'])
            );
        }
    }

    return $result;

}



/**
 *
 * @param int $post_id: The id of the post
 * @param string $domain: Which domains of link will be affected
 * @param string $action: The action type:
 *      + TODO: remove: remove the entire <a> tag including the content
 *      + TODO: strip_tag: remove the <a> tag but keeps the content
 *      + TODO: set_null: set the href attribute to #
 *      + TODO: nofollow: keep the markup but adding no-follow attr on it
 */
function wlm_do_link_action($post_id, $domain, $action='remove') {


}


