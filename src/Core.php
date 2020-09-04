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

    public function my_override_load_textdomain($override, $domain, $mofile)
    {
        global $l10n;

        // check if $mofile exisiste and is readable
        if (! (is_file($mofile) && is_readable($mofile))) {
            return false;
        }

        // creates a unique key for cache
        $key = md5($mofile);

        // I try to retrive data from cache
        $data = wp_cache_get($key, $domain);

        // Retrieve the last modified date of the translation files
        $mtime = filemtime($mofile);

        $mo = new \MO();

        // if cache not return data or data it's old
        if (! $data || ! isset($data['mtime']) || ($mtime > $data['mtime'])) {
            // retrive data from MO file
            if ($mo->import_from_file($mofile)) {
                $data = [
                    'mtime' => $mtime,
                    'entries' => $mo->entries,
                    'headers' => $mo->headers,
                ];

                // save data in cache
                wp_cache_set($key, $data, $domain, 0);
            } else {
                return false;
            }
        } else {
            $mo->entries = $data['entries'];
            $mo->headers = $data['headers'];
        }

        if (isset($l10n[$domain])) {
            $mo->merge_with($l10n[$domain]);
        }

        $l10n[$domain] = &$mo;

        return true;
    }

    public function ep_bulk_index_posts_request_args($args, $body)
    {
        $args['timeout'] = 300000;

        return $args;
    }

    public function add_actions()
    {
        remove_action('init', 'wp_widgets_init', 1);

        //Disable ACF fields that are from DB - improves performance a lot
        add_filter('posts_pre_query', [$this, 'acf_posts_pre_query'], 15, 2);

        //Remove Gutenberg Markting spam
        remove_action('try_gutenberg_panel', 'wp_try_gutenberg_panel');

        // disable Gutenberg completely
        add_filter('use_block_editor_for_post', '__return_false', 10);

        // disable for post types
        add_filter('use_block_editor_for_post_type', '__return_false', 10);

        //Cache MO locale loading
        //Taken from: https://www.it-swarm.dev/de/performance/verwenden-sie-override-load-textdomain-fuer-die-cache-uebersetzung-und-verbessern-sie-die-leistung/961933454/
        //based on: https://blog.blackfire.io/improving-wordpress.html
        add_filter('override_load_textdomain', [$this, 'my_override_load_textdomain'], 1, 3);

        //Disable KMM KRoN in json requests
        add_filter('krn_kron_enabled', function () {
            if (apply_filters('krn_is_rest_api_request', false)) {
                return false;
            }

            return true;
        });

        //Elastic Search
        //Reformat the query to support wildcards and order by date
        add_filter('ep_formatted_args', [$this, 'ep_formatted_args'], 1, 1);
        add_filter('ep_index_post_request_args', [$this, 'ep_index_post_request_args'], 1, 2);
        add_filter('ep_index_post_request_path', [$this, 'ep_index_post_request_path'], 1, 2);
        add_filter('ep_bulk_index_posts_request_args', [$this, 'ep_bulk_index_posts_request_args'], 10, 2);

        add_filter('ep_config_mapping', [$this, 'ep_config_mapping']);

        //workaround: https://github.com/10up/ElasticPress/pull/1158
        add_filter('ep_post_sync_args', [$this, 'ep_post_sync_args'], 10, 2);

        add_filter('ep_post_sync_kill', function ($v, $args, $id) {
            return true;
        }, 999, 3);

        add_filter('save_post', [$this, 'krn_index_object'], 9999, 1);
        //add_action( 'wp_insert_post', array( $this, 'krn_index_object_w' ), 999, 3 );
        add_action('add_attachment', [$this, 'krn_index_object_w'], 999, 3);
        add_action('edit_attachment', [$this, 'krn_index_object_w'], 999, 3);

        /// ELASITC PRESS

        add_filter('acp/filtering/cache/seconds', function ($seconds) {
            return 86400 * 30;
        });

        //FIX OLD/legacy ACF entries that used to have double encoded values
        add_filter('acf/load_value', [$this, 'acf_load_value'], 10, 3);

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

    public function krn_index_object_w($a, $b = null, $c = null)
    {
        $this->krn_index_object($a);
    }

    public function krn_index_object($post_id)
    {
        if (! function_exists('ep_prepare_post')) {
            //No elasticpress installed
            return;
        }
        $blocking = true;
        $post = get_post($post_id);
        if ($post->post_status == 'auto-draft') {
            return;
        }
        if ($post->post_status == 'draft') {
            return;
        }
        if (empty($post)) {
            return false;
        }
        $post_args = ep_prepare_post($post_id);
        $response = ep_index_post($post_args, $blocking);
    }

    public function acf_load_value($value, $post_id, $field)
    {
        return maybe_unserialize($value);
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
        if ($this->isExtenendEPQuery($s)) {
            return $s;
        }
        $w = explode(' ', $s);
        $fin = [];
        foreach ($w as $word) {
            $fin[] = '/.*' . $word . '.*/';
        }

        return join(' ', $fin);
    }

    public function ep_config_mapping($mapping)
    {
        /*
         *
        $mapping['settings']['analysis']['analyzer']['default']['filter'] = [  'standard','lowercase', 'edge_ngram'];
        $mapping['settings']['analysis']['filter']['edge_ngram']['min_gram'] =  3;
        $mapping['settings']['analysis']['filter']['edge_ngram']['max_gram'] =  128; //(quite bit but we're happy with this)
         */
        return $mapping;
    }

    public function ep_formatted_args($args)
    {
        if (isset($_GET['rekog_celebs'])) {
            $args['post_filter']['bool']['must'][] = [
                'term' => [
                    'rekog_celebs.slug' => $_GET['rekog_celebs'],
                ],
                ];

            return $args;
        }
        if (isset($_GET['rekog_persons'])) {
            $args['post_filter']['bool']['must'][] = [
                'term' => [
                    'rekog_persons.slug' => $_GET['rekog_persons'],
                ],
                ];

            return $args;
        }

        if (! array_key_exists('bool', $args['query'])) {
            return $args;
        }

        //Simplifie as fuck
        //
        $qs = $args['query']['bool']['should'][0]['multi_match']['query'];
        $qs = $this->wildCardIt($qs);
        $qs = $this->sanitizeEPQuery($qs);
        $nq = [
          'query_string' => [
            'default_field' => 'post_title.post_title',
            'query' => $qs,
            'default_operator' => 'AND',
            'analyze_wildcard' => true,
            'fuzziness' => 5,
          ],
        ];
        //Reset
        unset($args['query']);
        $args['query'] = $nq;

        //echo "<pre>";
        //echo json_encode($args);
        //exit;

        return $args;
    }

    public function sanitizeEPQuery($q)
    {
        if ($this->isExtenendEPQuery($q)) {
            return preg_replace("#^\!#", '', $q);
        }

        return str_replace(
            ['\\',    '+',  '-',  '&',  '|',  '!',  '(',  ')',  '{',  '}',  '[',  ']',  '^',  '~',  '?',  ':'],
            ['\\\\', "\+", "\-", "\&", "\|", "\!", "\(", "\)", "\{", "\}", "\[", "\]", "\^", "\~", "\?", "\:"],
            $q
        );
    }

    public function isExtenendEPQuery($q)
    {
        return preg_match("#^\!#", $q);
    }

    /*
     * /Elasticpress
     */
}
