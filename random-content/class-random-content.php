<?php


class Endo_Random_Content
{

	/**
	 * The ID of this plugin.
	 *
	 * @since    0.1.0
	 * @access   private
	 * @var      string    $name    The ID of this plugin.
	 */
	private $name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    0.1.0
	 * @access   private
	 * @var      string    $version    The version of the plugin
	 */
	private $version;

	/**
	 * Initializes the plugin by defining the properties.
	 *
	 * @since 0.1.0
	 */
	public function __construct()
	{

		$this->name = 'random-content';
		$this->version = '1.5.0';
	}

	/**
	 * Defines the hooks that will register the post type and taxonomy, adds the shortcode, and registers the widget
	 *
	 * @since 1.0
	 */
	public function run()
	{

		add_action('init', array($this, 'register_post_type'));

		add_action('init', array($this, 'register_taxonomy'));

		add_action('init', array($this, 'plugin_textdomain'));

		// this shortcode name (random) has been deprecated due to conflicts with other plugins
		add_shortcode('random', array(&$this, 'shortcode'));

		add_shortcode('random_content', array(&$this, 'new_shortcode'));

		add_action('widgets_init', array($this, 'register_endo_wrc_widget'));

		add_filter('manage_edit-endo_wrc_group_columns', array($this, 'add_random_content_group_columns'));

		add_filter('manage_endo_wrc_group_custom_column', array($this, 'random_content_group_custom_columns'), 10, 3);

		// Cache invalidation hooks
		add_action('save_post_endo_wrc_cpt', array($this, 'clear_random_content_cache'));
		add_action('delete_post', array($this, 'clear_random_content_cache'));
		add_action('edited_endo_wrc_group', array($this, 'clear_random_content_cache'));
		add_action('delete_endo_wrc_group', array($this, 'clear_random_content_cache'));
	}

	/**
	 * Clears all random content transient caches
	 *
	 * @since 1.5.0
	 */
	public function clear_random_content_cache()
	{
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rc_posts_%' OR option_name LIKE '_transient_timeout_rc_posts_%'");
	}

	/**
	 * Gets random content with caching and efficient randomization
	 * Shared method used by shortcodes and widget
	 *
	 * @since 1.5.0
	 * @param int    $num_posts Number of posts to return
	 * @param string $group_id  Group ID to filter by (optional)
	 * @param string $field     Taxonomy field type: 'id' or 'slug' (default: 'id')
	 * @return array Array of post objects
	 */
	public static function get_random_content($num_posts = 1, $group_id = '', $field = 'id')
	{
		$num_posts = max(1, (int) $num_posts);
		$cache_key = 'rc_posts_' . md5($group_id . '_' . $field);
		$cache_duration = HOUR_IN_SECONDS;

		// Try to get cached post IDs
		$all_post_ids = get_transient($cache_key);

		if (false === $all_post_ids) {
			// Build query args to get all matching post IDs
			$query_args = array(
				'post_type' => 'endo_wrc_cpt',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'no_found_rows' => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if (!empty($group_id)) {
				$query_args['tax_query'] = array(
					array(
						'taxonomy' => 'endo_wrc_group',
						'field' => $field,
						'terms' => $group_id
					)
				);
			}

			$query = new WP_Query($query_args);
			$all_post_ids = $query->posts;

			// Cache the post IDs
			set_transient($cache_key, $all_post_ids, $cache_duration);
		}

		if (empty($all_post_ids)) {
			return array();
		}

		// Use PHP array_rand for efficient randomization instead of ORDER BY RAND()
		$count = count($all_post_ids);
		if ($count <= $num_posts) {
			$random_ids = $all_post_ids;
			shuffle($random_ids);
		} else {
			$random_keys = array_rand($all_post_ids, $num_posts);
			if (!is_array($random_keys)) {
				$random_keys = array($random_keys);
			}
			$random_ids = array_map(function($key) use ($all_post_ids) {
				return $all_post_ids[$key];
			}, $random_keys);
		}

		// Fetch the actual posts using the random IDs
		$posts_query = new WP_Query(array(
			'post_type' => 'endo_wrc_cpt',
			'post__in' => $random_ids,
			'posts_per_page' => $num_posts,
			'orderby' => 'post__in',
			'no_found_rows' => true,
		));

		return $posts_query->posts;
	}


	/**
	 * Loads the localization files for translation
	 *
	 * @since 1.2
	 */
	public function plugin_textdomain()
	{

		load_plugin_textdomain('random-content', false, dirname(plugin_basename(__FILE__)) . '/languages/');
	}

	/**
	 * Calls the widget class that extends WP_Widget
	 *
	 * @since 1.0
	 */
	public function register_endo_wrc_widget()
	{

		require_once(plugin_dir_path(__FILE__) . 'class-random-content-widget.php');

		register_widget('Endo_WRC_Widget');
	}


	/**
	 * Registers the random content post type
	 *
	 * @since 0.1.0
	 */
	public function register_post_type()
	{

		$args = array(
			'labels' => array(
				'name' => __('Random Content', 'random-content'),
				'singular_name' => __('Random Content', 'random-content'),
				'add_new' => __('Add New', 'random-content'),
				'add_new_item' => __('Add New Random Content', 'random-content'),
				'edit_item' => __('Edit Random Content', 'random-content'),
				'new_item' => __('Add New Random Content', 'random-content'),
				'view_item' => __('View Random Content', 'random-content'),
				'search_items' => __('Search', 'random-content'),
				'not_found' => __('No Random Content Found', 'random-content'),
				'not_found_in_trash' => __('No Random Content Found in Trash', 'random-content')
			),
			'show_ui' => true,
			'supports' => array(
				'title',
				'editor'
			),
			'show_in_rest' => true
		);
		register_post_type('endo_wrc_cpt', $args);
	}

	/**
	 * Registers the group taxonomy for the random content post type
	 *
	 * @since 0.1.0
	 */
	public function register_taxonomy()
	{

		register_taxonomy(
			'endo_wrc_group',
			'endo_wrc_cpt',
			array(
				'labels' => array(
					'name' => __('Groups', 'random-content'),
					'singular_name' => __('Group', 'random-content'),
					'add_new_item' => __('Add New Group', 'random-content'),
					'not_found' => __('No groups found.', 'random-content'),
					'parent_item' => __('Parent Group', 'random-content'),
					'search_items' => __('Search Groups', 'random-content'),

				),
				'hierarchical' => true,
				'show_admin_column' => true,
				'show_in_rest' => true
			)
		);
	}


	/**
	 * Adds extra column for the ID to the group custom taxonomy
	 *
	 * @since 1.0
	 */
	public function add_random_content_group_columns($columns)
	{

		return array_merge($columns, array('group_id' => 'ID'));
	}

	/**
	 * Adds the group ID to the custom column
	 *
	 * @since 1.0
	 */
	public function random_content_group_custom_columns($value, $column_name, $id)
	{

		switch ($column_name) {

			case 'group_id':
				$value = $id;
				break;
			default:
				break;
		}

		return $value;
	}

	/**
	 * Defines random content shortcode
	 *
	 * @since 0.3.0
	 * @deprecated 1.0.0 Use [random_content] shortcode instead
	 */
	public function shortcode($atts)
	{
		$a = shortcode_atts(array(
			'group_id' => '',
			'num_posts' => 1,
		), $atts);

		$posts = self::get_random_content($a['num_posts'], $a['group_id'], 'id');

		if (empty($posts)) {
			return __('No posts found.', 'random-content');
		}

		$content = "";
		foreach ($posts as $post) {
			setup_postdata($post);
			$content .= apply_filters('the_content', $post->post_content);
		}
		wp_reset_postdata();

		return $content;
	}

	/**
	 * Defines new random content shortcode
	 *
	 * @since 1.0
	 */
	public function new_shortcode($atts)
	{
		$a = shortcode_atts(array(
			'group_id' => '',
			'num_posts' => 1,
		), $atts);

		$posts = self::get_random_content($a['num_posts'], $a['group_id'], 'id');

		if (empty($posts)) {
			return apply_filters('rc_content', __('No posts found.', 'random-content'));
		}

		$content = "";
		foreach ($posts as $post) {
			setup_postdata($post);
			$content .= apply_filters('the_content', $post->post_content);
		}
		wp_reset_postdata();

		return apply_filters('rc_content', $content);
	}
} // end class