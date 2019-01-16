<?php

namespace KMM\Hacks;

class Core
{
    private $plugin_dir;

    public function __construct($i18n)
    {
        global $wpdb;
        $this->i18n = $i18n;
        $this->wpdb = $wpdb;
        $this->plugin_dir = plugin_dir_url(__FILE__) . '../';

        $this->add_filters();
        $this->add_actions();
        $this->add_metabox();
    }

    public function add_metabox()
    {
    }

    public function add_filters()
    {
    }

    public function add_actions()
    {
        //Disable ACF fields that are from DB - improves performance a lot
        add_filter('posts_pre_query', [$this, 'acf_posts_pre_query'], 15, 2);

        //Remove Gutenberg Markting spam
        remove_action('try_gutenberg_panel', 'wp_try_gutenberg_panel');

        //Disable Gutenberg completly
			  add_filter('use_block_editor_for_post', '__return_false', 10);

				// disable for post types
				add_filter('use_block_editor_for_post_type', '__return_false', 10);

        //Elastic Search
        //Reformat the query to support wildcards and order by date
        add_filter('ep_formatted_args', [$this, 'ep_formatted_args'], 1, 1);
        add_filter('ep_index_post_request_args', [$this, 'ep_index_post_request_args'], 1, 2);
        add_filter('ep_index_post_request_path', [$this, 'ep_index_post_request_path'], 1, 2);

        //workaround: https://github.com/10up/ElasticPress/pull/1158
        add_filter('ep_post_sync_args', [$this, 'ep_post_sync_args'], 10, 2);


        /// ELASITC PRESS

        add_filter('acp/filtering/cache/seconds', function ($seconds) {
            return 86400 * 30;
        });

        //Disable comment count in admin-navigation
        if (is_admin()) {
            add_filter('wp_count_comments', function ($counts, $post_id) {
                if ($post_id) {
                    return $counts;
                }

                return (object) [
                    'approved' => 0,
                    'spam' => 0,
                    'trash' => 0,
                    'total_comments' => 0,
                    'moderated' => 0,
                    'post-trashed' => 0,
                ];
            }, 10, 2);
        }

        //Disable Article Counter - query runs for about 1-2 seconds in the edit.php list head
        add_filter('admin_init', function () {
            foreach (get_post_types() as $type) {
                $cache_key = _count_posts_cache_key($type, 'readable');
                $counts = array_fill_keys(get_post_stati(), 1);
                wp_cache_set($cache_key, (object)$counts, 'counts');
            }
        }, -1);
        add_action('admin_head', function () {
            $css = '<style>';
            $css .= '.subsubsub a .count { display: none; }';
            $css .= '</style>';

            echo $css;
        });
        //Heartbeat is a bi** in large scale
        add_action('admin_enqueue_scripts', [$this, 'maybe_kill_heartbeat'], 100);
        //Handle WP-Heartbeat
        add_filter('heartbeat_settings', [$this, 'heartbeat_settings']);

        //Media Library scaling issues
        add_filter('disable_months_dropdown', function () {
            return true;
        });
        add_filter('media_library_months_with_files', function () {
            return [];
        });
        add_filter('media_library_show_audio_playlist', function () {
            return false;
        });
        add_filter('media_library_show_video_playlist', function () {
            return false;
        });
    }

    public function ep_post_sync_args($args, $post_id)
    {
        $args['comment_status'] = absint($args['comment_status']);
        $args['ping_status'] = absint($args['ping_status']);
        return $args;
    }
    //ACF querie disable
    //
    public function debug_enabled()
    {
        if (defined('WP_DEBUG') && WP_DEBUG == true) {
            return true;
        }

        return false;
    }

    public function acf_posts_pre_query($posts, \WP_Query $query)
    {
        if (is_object($query) && property_exists($query, 'query_vars') && $query->query_vars['post_type'] == 'acf-field-group' && ! $this->debug_enabled()) {
            return [];
        }

        return $posts;
    }

    //Heartbeat
    //
    public function maybe_kill_heartbeat()
    {
        $current_screen = get_current_screen()->base;
        if ($current_screen !== 'post') {
            wp_deregister_script('heartbeat');
        }
    }

    public function heartbeat_settings($settings)
    {
        $settings['interval'] = 120; //Anything between 15-60
        return $settings;
    }

    /*
     * /Heartbeat
     *
     */

    /*
     * Elasticpress
     *
     */
    public function ep_index_post_request_path($path, $post)
    {
        return $path . '?refresh=wait_for';
    }

    public function ep_index_post_request_args($args, $post)
    {
        $args['blocking'] = true;

        return $args;
    }

    public function wildCardIt($s)
    {
        return $s;
        $w = explode(' ', $s);
        $fin = [];
        foreach ($w as $word) {
            $fin[] = '*' . $word . '*';
        }

        return join($fin, ' ');
    }

    public function ep_formatted_args($args)
    {
        if (! array_key_exists('bool', $args['query'])) {
            return $args;
        }
        $args['query']['bool']['must'] = $args['query']['bool']['should'];
        unset($args['query']['bool']['should']);
        $new = $args['query']['bool']['must'][0];
        $new['query_string'] = $new['multi_match'];
        $new['query_string']['query'] = $this->wildCardIt($new['query_string']['query']);
        $new['query_string']['query'] = str_replace(
            ['\\',    '+',  '-',  '&',  '|',  '!',  '(',  ')',  '{',  '}',  '[',  ']',  '^',  '~',  '?',  ':'],
            ['\\\\', "\+", "\-", "\&", "\|", "\!", "\(", "\)", "\{", "\}", "\[", "\]", "\^", "\~", "\?", "\:"],
            $new['query_string']['query']
        );
        $new['query_string']['analyze_wildcard'] = true;
        unset($new['query_string']['type']);
        $new['query_string']['fields'] = ['post_title'];
        $new['query_string']['boost'] = 10;
        $new['query_string']['default_operator'] = 'AND';
        unset($new['multi_match']);
        unset($new['type']);
        array_unshift($args['query']['bool']['must'], $new);

        foreach ($args['query']['bool']['must'] as $idx => &$r) {
            if (isset($r['multi_match'])) {
                unset($args['query']['bool']['must'][$idx]);
            }
        }
        $args['sort'] = ['post_date' => ['order' => 'desc']];

        return $args;
    }

    /*
     * /Elasticpress
     */
}
