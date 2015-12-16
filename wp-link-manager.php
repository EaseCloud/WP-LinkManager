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
 * Enqueues the admin script and styles.
 */
if(is_admin()) {
    add_action('admin_init', function() {
        wp_enqueue_style('wlm', WLM__PLUGIN_URL.'_inc/wp-link-manager.css');
        wp_enqueue_script('wlm', WLM__PLUGIN_URL.'_inc/wp-link-manager.js', array('jquery'));
    });
}
