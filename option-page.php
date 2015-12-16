<?php

/**
 * 在每个文章的 meta 里面缓存分析的结果，包括：
 * wlm_suspected_domain_count: 文章里面的外链域计数
 * wlm_suspected_domain_list: 文章里面的外链域列表
 * wlm_suspected_link_count: 文章里面的外链计数
 * wlm_suspected_link_list: 文章里面的外链列表
 */
$options = array(
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

if(isset($_POST['action'])) {

    if($_POST['action'] == 'analysis') {

        /**
         * Re-analysis procedure.
         */

        // Retrieve all posts.
        $posts = get_posts(array(
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        // Transversing all the posts.
        foreach($posts as $post) {

            $content = $post->post_content;

            // Scratch all the href attribute of <a> tags.
            $result = preg_match_all(
                "/<\\s*a[^<>]+href=[\"']([^\"']+)/ims",
                $content, $matches, PREG_PATTERN_ORDER
            );
            if(!$result) continue;

            // Collect the suspected links.
            $suspected_domains = array();
            $suspected_links = array();

            // Loops into each link in the post.
            for($i = 1; $i < sizeof($matches[1]); ++$i) {

                // Parsing the link info.
                $url = $matches[1][$i];
                $url_info = parse_url($url);

                // No scheme or no hosts links are treated as safe.
                if(!isset($url_info['scheme']) || !isset($url_info['host'])) continue;

                $scheme = $url_info['scheme'];  // http or https
                $host = $url_info['host'];  // domain name and port
                $path = isset($url_info['path']) ? $url_info['path'] : '/';

                if(!wlm_is_domain_allowed($host)) {
                    if(!isset($suspected_domains[$host])) {
                        $suspected_domains[$host] = 0;
                    }
                    $suspected_domains[$host] += 1;
                    $suspected_links []= $url;
                }

            }

//            var_dump($suspected_domains);

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

    //    ob_clean(); wp_redirect(''); exit;

    } elseif($_POST['action'] == 'update') {

        /**
         * Update settings procedure.
         */

        if (!isset($_POST['wlm_setting'])) {
            wp_die(__('The nonce checking field is incorrect.', WLM_DOMAIN));
        } elseif(!wp_verify_nonce($_POST['wlm_setting'])) {
            wp_die(__('Do not re-submit the form.', WLM_DOMAIN));
        } else {
            // Processing the form data.
            foreach($options as $i=>$opt) {
                $key = $opt['key'];
                if(isset($_POST[$key])) {
                    update_option($key, $_POST[$key]);
                }
            }
        }

    } elseif($_POST['action'] == 'strip_tag') {

        /**
         * Strip tags selected by css selectors.
         */

        $selector = $_POST['remove_css_selector'];

        // @link https://code.google.com/p/phpquery/
        include_once 'phpQuery/phpQuery.php';


    }
}


/**
 * Rendering the option page.
 */

?><div class="wrap">

    <h2><?php _e('External Link Management');?></h2>

    <form method="post" method="post" action="">
        <?php wp_nonce_field(-1, 'wlm_setting'); ?>
        <input type="hidden" name="action" value="update" />
        <table class="form-table">

            <?php foreach($options as $opt) {?>
            <tr valign="top">
                <th scope="row">
                    <label for="<?php echo $opt['key'];?>">
                        <?php echo $opt['label']; ?>
                    </label>
                </th>
                <td>
                    <?php if($opt['type'] == 'radio') {
                        foreach($opt['choices'] as $choice_key=> $choice_val) { ?>
                        <label>
                            <input type="radio" name="<?php echo $opt['key'];?>"
                                   value="<?php echo $choice_key; ?>"
                                   <?php if(get_option($opt['key']) == $choice_key)
                                   echo 'checked'; ?>
                                   />
                            <?php echo $choice_val; ?>
                        </label>
                        <?php }?>
                    <?php } elseif($opt['type'] == 'textarea') {?>
                        <textarea id="<?php echo $opt['key'];?>"
                                  name="<?php echo $opt['key'];?>"
                                  class="large-text code"
                                  rows="12"><?php
                            echo esc_attr(get_option($opt['key']));
                            ?></textarea>
                    <?php }?>
                </td>
            </tr>
            <?php }?>
        </table>
        <?php submit_button(); ?>
    </form>

    <hr/>

    <h3><?php _e('Suspected Posts List', WLM_DOMAIN);?></h3>

    <form method="post">
        <input type="hidden" name="action" value="analysis" />
        <?php
        $posts = get_posts(array(
            'posts_per_page' => -1,
            'meta_key' => 'wlm_suspected_domain_count',
            'meta_value' => 0,
            'meta_compare' => '>',
            'post_status' => 'any',
        ));
        ?>
        <p>
            <?php submit_button(__('Re-analysis', WLM_DOMAIN)); ?>
        </p>
        <table class="wp-list-table widefat fixed striped posts">
            <thead>
            <tr>
                <th scope="col" class="manage-column column-primary"><?php _e('Post Title');?></span></th>
                <th scope="col" class="manage-column"><?php _e('Suspected domains', WLM_DOMAIN);?></th>
                <th scope="col" class="manage-column"><?php _e('Suspected links', WLM_DOMAIN);?></th>
<!--                <th scope="col" class="manage-column">--><?php //_e('Actions', WLM_DOMAIN);?><!--</th>-->
            </thead>

            <tbody id="the-post-list">
            <?php foreach($posts as $post) { setup_postdata($GLOBALS['post']=&$post); ?>
                <tr id="post-<?=$post->ID?>" <?php  post_class();?>>
                    <td class="title column-title has-row-actions column-primary page-title" data-colname="标题">
                        <strong>
                            <?php edit_post_link(get_the_title());?> —
                            <span class="post-state"><?php echo get_post_status_object($post->post_status)->label;?></span>
                        </strong>
                        <div class="row-actions">
                            <span class="success">
                                <a href="javascript:">管理</a>
                            </span> |
                            <span class="edit">
                                <?php edit_post_link('编辑');?>
                            </span> |
                            <span class="view">
                                <a href="<?php the_permalink();?>" target="post_<?php the_ID(); ?>">查看</a>
                            </span> |
                            <span class="delete">
                                <a href="javascript:">清理</a>
                            </span>
                        </div>
                    </td>
                    <td>
                        <?php $domain_list = get_post_meta(get_the_ID(), 'wlm_suspected_domain_list', true); ?>
                        <?php foreach($domain_list as $domain => $count) {?>
                            <div>
                                <?php echo "$domain ($count)";?>
                                <span class="success">
                                    <a class="set-domain-white"
                                       href="javascript:"
                                       data-domain="<?php echo $domain;?>">白名单</a>
                                </span>
                                <span ></span>
                                <a class="remove-domain-from-post"
                                   href="javascript:"
                                   data-post="<?php the_ID();?>"
                                   data-domain="<?php echo $domain;?>">移除</a>
                            </div>
                        <?php }?>
                    </td>
                    <td>
                        <?php $link_list = get_post_meta(get_the_ID(), 'wlm_suspected_link_list', true); ?>
                        <?php foreach($link_list as $link) {
                            echo "<div><a href='$link'>$link</a></div>";
                        }?>
                    </td>
<!--                    <td>-->
<!--                    </td>-->
                </tr>
            <?php }?>
            </tbody>

            <tfoot>
            <tr>
                <th scope="col" class="manage-column column-primary"><?php _e('Post Title');?></span></th>
                <th scope="col" class="manage-column"><?php _e('Suspected domains', WLM_DOMAIN);?></th>
                <th scope="col" class="manage-column"><?php _e('Suspected links', WLM_DOMAIN);?></th>
<!--                <th scope="col" class="manage-column">--><?php //_e('Actions', WLM_DOMAIN);?><!--</th>-->
            </tr>
            </tfoot>

        </table>
        <p>
            <?php submit_button(__('Re-analysis', WLM_DOMAIN)); ?>
        </p>
    </form>

    <hr/>

    <h3><?php _e('Remove Specific Tags', WLM_DOMAIN);?></h3>

    <div class="notice notice-warning inline">
        <p>
            <?php _e("
                <strong>Warning:</strong>
                Be sure you know what you are doing: we will remove the css
                selected html elements in your post content of <strong>ALL</strong>
                posts, and may cause an unexpected deletion of useful content.
                ", WLM_DOMAIN); ?>
        </p>
    </div>

    <form method="post">

        <input type="hidden" name="action" value="strip_tag" />

        <table class="form-table">
            <tr valign="top">
                <th scope="row">
                    <label for="remove_css_selector">
                        <?php _e('CSS selector to remove', WLM_DOMAIN); ?>
                    </label>
                </th>
                <td>
                    <input id="remove_css_selector" type="text"
                           class="regular-text ltr"
                           name="remove_css_selector" />
                </td>
            </tr>
        </table>
        <p>
            <?php submit_button(__('Proceed', WLM_DOMAIN)); ?>
        </p>

    </form>


</div>
