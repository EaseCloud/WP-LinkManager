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

$message = null;

if(isset($_POST['action'])) {

    if($_POST['action'] == 'analyze') {

//        /**
//         * Re-analyze procedure.
//         */
//
//        wlm_start_analyze_job();
//

    } elseif($_POST['action'] == 'update') {

        /**
         * Update settings procedure.
         */

        if (!isset($_POST['wlm_setting'])) {
            wp_die(__('The nonce checking field is incorrect.', WLM_DOMAIN));
        } elseif(!wp_verify_nonce($_POST['wlm_setting'])) {
            wp_die(__('Do not re-submit the form.', WLM_DOMAIN));
        } else {
            global $wlm_options;
            // Processing the form data.
            foreach($wlm_options as $i=>$opt) {
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

        ignore_user_abort(true);
        set_time_limit(0);

        // Retrieve all posts.
        $posts = get_posts(array(
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'asc'
        ));

        // Transversing all the posts.
        foreach($posts as $post) {
            $doc = phpQuery::newDocument($post->post_content);
            pq($selector)->remove();
            wp_update_post(array(
                'ID' => $post->ID,
                'post_content' => $doc->htmlOuter(),
            ));
        }

        // Message
        $message = array(
            'class' => 'notice notice-success',
            'msg' => __('CSS replacement done, please re-run post analyze.', WLM_DOMAIN),
        );

    }
}

/**
 * Rendering the option page.
 */

?><div class="wrap">

    <h2><?php _e('External Link Management');?></h2>

    <?php if($message) {?>
        <div class="<?php echo $message['class'];?>">
            <p><?php echo $message['msg']; ?></p>
        </div>
    <?php }?>

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
        <?php
        $posts = get_posts(array(
            'posts_per_page' => -1,
            'meta_key' => 'wlm_suspected_domain_count',
            'meta_value' => 0,
            'meta_compare' => '>',
            'post_status' => 'any',
        ));

        $analyze_status = wlm_check_job('analyze');
        if($analyze_status) {
            $count = @$analyze_status['data']['count'] ?: 0;
            $total = @$analyze_status['data']['total'] ?: 1;
            $percent = @round($count/$total*100, 1) ?: 0;
            ?>
        <input type="hidden" name="action" value="analyze_stop" />
        <div class="media-upload-form">
            <div class="media-item child-of-0" style="float: none;">
                <div id="analyze_progress" class="progress">
                    <div class="percent"><?php echo $percent;?>%</div>
                    <div class="bar" style="width: <?php echo $percent;?>%;"></div>
                </div>
                <div class="filename original">
                    <?php _e("Posts are analyzing:", WLM_DOMAIN); ?>
                    <span class="count"><?php echo $count;?></span>
                    <?php _e("of", WLM_DOMAIN); ?>
                    <span class="total"><?php echo $total;?></span>
                    <?php _e("are done.", WLM_DOMAIN); ?>
                </div>
            </div>
        </div>
        <?php submit_button(__('Stop analyze', WLM_DOMAIN)); ?>
        <?php } else {?>
        <input type="hidden" name="action" value="analyze" />
        <?php submit_button(__('Re-analyze', WLM_DOMAIN)); ?>
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
                                <a href="javascript:"><?php _e('Manage', WLM_DOMAIN);?></a>
                            </span> |
                            <span class="edit">
                                <?php edit_post_link(__('Edit', WLM_DOMAIN));?>
                            </span> |
                            <span class="view">
                                <a href="<?php the_permalink();?>" target="post_<?php the_ID(); ?>"
                                ><?php _e('View', WLM_DOMAIN);?></a>
                            </span> |
                            <span class="danger">
                                <!-- TODO: To be implemented -->
                                <a href="javascript:alert('<?php
                                _e('Not Implemented, you can manually edit the post source to fix the problem.', WLM_DOMAIN); ?>')"
                                   title="<?php _e('Remove the entire <a> tag listed as suspected.', WLM_DOMAIN);?>"
                                ><?php _e('Remove', WLM_DOMAIN);?></a>
                            </span> |
                            <span class="danger">
                                <!-- TODO: To be implemented -->
                                <a href="javascript:alert('<?php
                                _e('Not Implemented, you can manually edit the post source to fix the problem.', WLM_DOMAIN); ?>')"
                                   title="<?php _e('Strips the <a> and inner tags but keep the text.', WLM_DOMAIN);?>"
                                ><?php _e('Strip', WLM_DOMAIN);?></a>
                            </span> |
                            <span class="danger">
                                <!-- TODO: To be implemented -->
                                <a href="javascript:alert('<?php
                                _e('Not Implemented, you can manually edit the post source to fix the problem.', WLM_DOMAIN); ?>')"
                                   title="<?php _e('Only remove the href attribute, keep the tag and content.', WLM_DOMAIN);?>"
                                ><?php _e('Unlink', WLM_DOMAIN);?></a>
                            </span> |
                            <span class="warning">
                                <!-- TODO: To be implemented -->
                                <a href="javascript:alert('<?php
                                _e('Not Implemented, you can manually edit the post source to fix the problem.', WLM_DOMAIN); ?>')"
                                   title="<?php _e('Add nofollow attribute on the suspected links.', WLM_DOMAIN);?>"
                                ><?php _e('Nofollow', WLM_DOMAIN);?></a>
                            </span>
                        </div>
                    </td>
                    <td>
                        <?php $domain_list = get_post_meta(get_the_ID(), 'wlm_suspected_domain_list', true); ?>
                        <?php foreach($domain_list as $domain => $count) {?>
                            <div>
                                <?php echo "$domain ($count)";?>
                                <span class="warning">
                                <a class="remove-domain-from-post"
                                    href="javascript:alert('<?php
                                    _e('Not Implemented, you can manually edit the post source to fix the problem.', WLM_DOMAIN); ?>')"
                                   data-post="<?php the_ID();?>"
                                   data-domain="<?php echo $domain;?>"><?php _e('Process', WLM_DOMAIN);?></a>
                                </span>
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
        <?php submit_button(__('Re-analyze', WLM_DOMAIN)); ?>
        <?php }?>
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


<script>
    jQuery(function($) {
        var $progress = $('#analyze_progress');
        if($progress.length) {
            var updateProgress = function() {
                $.getJSON(
                    '<?php echo wlm_get_ajax_url('analyze_status'); ?>'
                ).then(function(analyze_status) {
                    if(!analyze_status) {
                        location.reload();
                        return false;
                    }
                    var count = analyze_status.data.count;
                    var total = analyze_status.data.total;
                    var percent = Math.round(count/total*1000)/10;
                    $progress.find('.percent').html(percent+'%');
                    $progress.find('.bar').width(percent+'%');
                    $progress.parent().find('.count').text(count);
                    $progress.parent().find('.total').text(total);
                    setTimeout(updateProgress, 1000);
                });
            };
            updateProgress();
        }
    });
</script>
