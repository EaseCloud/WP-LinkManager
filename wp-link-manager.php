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
 * 在每个文章的 meta 里面缓存分析的结果，包括：
 * wlm_suspected_domain_count: 文章里面的外链域计数
 * wlm_suspected_domain_list: 文章里面的外链域列表
 * wlm_suspected_link_count: 文章里面的外链计数
 * wlm_suspected_link_list: 文章里面的外链列表
 */
$wlm_options = array(
    array(
        'key' => 'wlm_mode',
        'type' => 'radio',
        'label' => __('Mode', WLM_DOMAIN),
        'choices' => array(
            'white' => __('White list mode', WLM_DOMAIN),
            'black' => __('Black list mode', WLM_DOMAIN),
        ),
    ),
    array(
        'key' => 'wlm_domain_white_list',
        'type' => 'textarea',
        'label' => __('White List', WLM_DOMAIN),
    ),
    array(
        'key' => 'wlm_domain_black_list',
        'type' => 'textarea',
        'label' => __('Black List', WLM_DOMAIN),
    ),
);

/**
 *
 * @link: https://codex.wordpress.org/Creating_Options_Pages
 */
add_action('admin_menu', function() {

    // TODO: 入口点需要重构，这样不可靠
    parse_wlm_rule_lists();

    // TODO: 需要重构，配置页前置 POST 提交处理。
    if(is_admin() && @$_GET['page'] == 'wlm_settings') {
        wlm_process_action();
    }

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

function wlm_process_action() {

    if(isset($_POST['action'])) {

        if($_POST['action'] == 'analyze') {

            /**
             * Re-analyze procedure.
             */

            wlm_start_analyze_job();

            //    ob_clean(); wp_redirect(''); exit;

        } elseif($_POST['action'] == 'analyze_stop') {

            wlm_stop_analyze_job();

        }
    }

}

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
 * Return a string of replacement tag with an index.
 * @param int $i: The tag index.
 * @return string
 *      format: {% wlm_link ## %}
 */
function wlm_get_replacement_tag($i) {
    return "{% wlm_link $i %}";
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
    if(is_string($domains)) $domains = explode('|', $domains);

    // Scratch all the href attribute of <a> tags.
    phpQuery::newDocument($content);

    $result = array();

    // Loops into each link in the post.
    foreach(pq('a') as $i => $tag) {

        $tag = pq($tag);

        // Parsing the link info.
        $markup = $tag->htmlOuter();
        $url = $tag->attr('href') ?: '';
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
    $document = phpQuery::newDocument($content);
    $marked_content = $document->htmlOuter();

    foreach($result as $i => $item) {

        // replace the first match.
        // @link: http://stackoverflow.com/q/1252693/2544762
        $pos = strpos($marked_content, $item['markup']);
        if($pos !== false) {
            $marked_content = substr_replace(
                $marked_content,
                wlm_get_replacement_tag($i),
                $pos,
                strlen($item['markup'])
            );
        }
    }

    return $result;

}


/**
 * Apply the link action on a specified post.
 * @param int|WP_Post $post: The id of the post or a WP_Post object.
 * @param string|array $domains: Which domains of link will be affected
 * @param string $action: The action type:
 *      + remove: remove the entire <a> tag including the content
 *      + strip_tag: remove the <a> and inner tags but keeps the content
 *      + unlink: remove the href attribute
 *      + nofollow: keep the markup but adding no-follow attr on it
 */
function wlm_do_link_action($post, $domains, $action='remove') {

    // Normalize the $post object to a valid WP_Post instance.
    if(!$post instanceof WP_Post) $post = get_post($post);

    // Normalize domain type
    if(is_string($domains)) $domains = explode('|', $domains);

    // Parse the post_content.
    $matches = wlm_match_domain($post->post_content, $domains, $content);
    if(!$matches) return;

    require_once 'phpQuery/phpQuery.php';

    foreach ($matches as $i => $item) {
        $tag = wlm_get_replacement_tag($i);
        switch($action) {
            case 'remove':
                $content = str_replace($tag, '', $content);
                break;
            case 'strip_tag':
                $a = pq($item['markup']);
                $content = str_replace($tag, $a->text(), $content);
                break;
            case 'unlink':
                $a = pq($item['markup']);
                $a->removeAttr('href');
                $content = str_replace($tag, $a->htmlOuter(), $content);
                break;
            case 'nofollow':
                $a = pq($item['markup']);
                $a->attr('rel', 'nofollow');
                $content = str_replace($tag, $a->htmlOuter(), $content);
                break;
            default:
                wp_die('WLM not supporting action.');
        }
    }

    // Then, update the post_content.
    wp_update_post(array(
        'ID' => $post->ID,
        'post_content' => $content,
    ));

}


/**
 * Analyze the post link status, and store the infomation in the post meta
 * @param int|WP_Post $post: The id of the post or a WP_Post object.
 */
function wlm_analyze_post($post) {

    // Normalize the $post object to a valid WP_Post instance.
    if(!$post instanceof WP_Post) $post = get_post($post);

    // Parse the link info of the post, by the default rule.
    $matches = wlm_match_domain($post->post_content);

    // Collect the suspected links.
    $suspected_domains = array();
    $suspected_links = array();

    // Loops into each link in the post.
    foreach($matches as $item) {
        $suspected_domains[$item['domain']] =
            (@$suspected_domains[$item['domain']]?:0) + 1;
        $suspected_links []= $item['url'];
    }

    update_post_meta($post->ID, 'wlm_suspected_domain_count', sizeof($suspected_domains));
    update_post_meta($post->ID, 'wlm_suspected_domain_list', $suspected_domains);

    update_post_meta($post->ID, 'wlm_suspected_link_count', sizeof($suspected_links));
    update_post_meta($post->ID, 'wlm_suspected_link_list', $suspected_links);

    /*
                <div style="border-bottom: 1px solid black;">
                    <p><?=$post->ID?>. <?=$post->post_title?>: <?=$post->post_status?></p>
                    <textarea style="width: 100%;" rows="8"><?=$post->post_content?></textarea>
                    <textarea style="width: 100%;" rows="8"><?php echo implode($suspected_links, "\n");?></textarea>
                </div>
    */
}


function wlm_get_job_option_name($handle) {
    return 'wlm_job_'.$handle;
}

/**
 * Get the job
 * @param string $handle
 * @param int $timeout
 * @return array: Returns the job status object
 * array(
 *      handle => The job handle
 *      pid => The php process id
 *      last_tick => Last active UNIX time
 *      data => The job data
 * )
 */
function wlm_check_job($handle='_job_handle', $timeout=30) {
    $status = get_option(wlm_get_job_option_name($handle));
    if(time() > intval($status['last_tick']) + $timeout) {
        wlm_stop_job($handle);
        return false;
    }
    return $status;
}


/**
 * Start a job, then response an http redirect.
 * @param string $handle
 * @param string $redirect
 * @param string|callback $callback: The job running function
 */
function wlm_start_job($handle='_job_handle', $redirect='', $callback) {

    // Exclusive check, if another job is already running, skip.
    if(wlm_check_job($handle)) return;

    $pid = rand(0, 99999999);
    $opt_name = wlm_get_job_option_name($handle);
    update_option($opt_name, array(
        'handle' => $handle,
        'pid' => $pid,
        'last_tick' => time(),
    ));

    ignore_user_abort(true);
    set_time_limit(0);

    ob_end_clean();
    ob_start();
    header("Content-Length: 0");
    wp_redirect($redirect ?: $_SERVER['REQUEST_URI']);
//    header("Location: $redirect");
    echo str_repeat(" ", 4096*1024);
    ob_end_flush();
    flush();

    $callback($handle, $pid);
}

function wlm_renew_job($handle, $pid, $data=array()) {
    $opt_name = wlm_get_job_option_name($handle);
    $status = wlm_check_job($handle);
    if(!$status || $status['pid'] != $pid) return false;
    update_option($opt_name, array(
        'handle' => $handle,
        'pid' => $pid,
        'last_tick' => time(),
        'data' => $data,
    ));
    return true;
}

function wlm_stop_job($handle) {
    delete_option(wlm_get_job_option_name($handle));
}

function wlm_start_analyze_job() {

    wlm_start_job('analyze', '', function($handle, $pid) {

        // Retrieve all posts.
        $posts = get_posts(array(
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'asc'
        ));

        $total = sizeof($posts);
        $count = 0;

        // Transversing all the posts.
        foreach($posts as $post) {
            $success = wlm_renew_job($handle, $pid, array(
                'total' => $total,
                'count' => $count++,
            ));
            if(!$success) break;
            wlm_analyze_post($post);
        }
        wlm_stop_job($handle);

    });

}

function wlm_stop_analyze_job() {
    wlm_stop_job('analyze');
}

function wlm_register_ajax_action($action, $callback) {
    add_action("wp_ajax_$action", $callback);
    add_action("wp_ajax_nopriv_$action", $callback);
}

function wlm_get_ajax_url($action) {
    return home_url("/wp-admin/admin-ajax.php?action=$action");
}


wlm_register_ajax_action('analyze_status', function() {
    $result = wlm_check_job('analyze');
    exit($result ? json_encode($result) : '');
});





