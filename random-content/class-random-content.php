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
	 * Whether any shortcode on the current page uses ajax="yes"
	 *
	 * @since 1.6.2
	 * @access private
	 * @var bool
	 */
	private $has_ajax_placeholders = false;

	/**
	 * Recursion guard to prevent infinite loops when apply_filters('the_content')
	 * is called inside shortcode handlers (since the_content runs do_shortcode)
	 *
	 * @since 1.6.3
	 * @access private
	 * @var bool
	 */
	private static $rendering = false;

	public static function is_rendering()
	{
		return self::$rendering;
	}

	public static function set_rendering($value)
	{
		self::$rendering = (bool) $value;
	}

	/**
	 * Process post content through essential WordPress formatting filters
	 * without triggering page builder filters (Elementor, Beaver Builder, etc.)
	 *
	 * Using apply_filters('the_content', ...) inside shortcodes/widgets causes
	 * page builders to re-render the current page's builder content for each
	 * random content post, because get_the_ID() still returns the host page ID.
	 *
	 * @since 1.6.5
	 * @param string $content Raw post content
	 * @return string Processed content
	 */
	public static function process_content($content)
	{
		if (function_exists('do_blocks')) {
			$content = do_blocks($content);
		}
		$content = wptexturize($content);
		$content = convert_smilies($content);
		$content = wpautop($content);
		$content = shortcode_unautop($content);
		if (function_exists('wp_filter_content_tags')) {
			$content = wp_filter_content_tags($content);
		}
		$content = do_shortcode($content);
		return $content;
	}

	/**
	 * Initializes the plugin by defining the properties.
	 *
	 * @since 0.1.0
	 */
	public function __construct()
	{

		$this->name = 'random-content';
		$this->version = '1.6.5';
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

		// REST API for AJAX mode; scripts enqueued conditionally in wp_footer
		add_action('rest_api_init', array($this, 'register_rest_routes'));
		add_action('wp_footer', array($this, 'maybe_enqueue_ajax_scripts'));

		// Cache invalidation hooks
		add_action('save_post_endo_wrc_cpt', array($this, 'clear_random_content_cache'));
		add_action('delete_post', array($this, 'clear_random_content_cache'));
		add_action('edited_endo_wrc_group', array($this, 'clear_random_content_cache'));
		add_action('delete_endo_wrc_group', array($this, 'clear_random_content_cache'));

		// Pro upsell (only when Pro is not active)
		if (!class_exists('Endo_Random_Content_Pro')) {
			add_action('add_meta_boxes_endo_wrc_cpt', array($this, 'add_pro_teaser_meta_boxes'));
			add_action('admin_notices', array($this, 'render_pro_admin_banner'));
			add_action('admin_menu', array($this, 'add_pro_submenu_page'));
			add_filter('plugin_action_links_' . plugin_basename(dirname(__FILE__)) . '/random-content.php', array($this, 'add_pro_plugin_row_link'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
			add_action('wp_ajax_rc_dismiss_pro_banner', array($this, 'ajax_dismiss_pro_banner'));
		}
	}

	/**
	 * Registers the REST API route for fetching random content via AJAX
	 *
	 * @since 1.6.0
	 */
	public function register_rest_routes()
	{
		register_rest_route('random-content/v1', '/posts', array(
			'methods'  => 'GET',
			'callback' => array($this, 'rest_get_random_content'),
			'permission_callback' => '__return_true',
			'args' => array(
				'group'     => array('default' => '', 'sanitize_callback' => 'sanitize_text_field'),
				'num_posts' => array('default' => 1, 'sanitize_callback' => 'absint'),
				'field'     => array('default' => 'id', 'sanitize_callback' => 'sanitize_text_field'),
			),
		));
	}

	/**
	 * REST API callback that returns rendered random content as HTML
	 *
	 * @since 1.6.0
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function rest_get_random_content($request)
	{
		$group     = $request->get_param('group');
		$num_posts = $request->get_param('num_posts');
		$field     = $request->get_param('field');

		if (!in_array($field, array('id', 'slug'), true)) {
			$field = 'id';
		}

		self::$rendering = true;
		$posts = self::get_random_content($num_posts, $group, $field);

		$content = '';
		if (!empty($posts)) {
			foreach ($posts as $post) {
				$content .= self::process_content($post->post_content);
			}
			$content = apply_filters('rc_content', $content);
		}
		self::$rendering = false;

		$response = rest_ensure_response(array('html' => $content));
		$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
		$response->header('Pragma', 'no-cache');
		$response->header('Expires', '0');

		return $response;
	}

	/**
	 * Enqueues AJAX scripts only when a shortcode on the page used ajax="yes"
	 *
	 * @since 1.6.2
	 */
	public function maybe_enqueue_ajax_scripts()
	{
		if (!$this->has_ajax_placeholders) {
			return;
		}

		wp_enqueue_script(
			'random-content',
			plugin_dir_url(__FILE__) . 'js/random-content.js',
			array(),
			$this->version,
			true
		);
		wp_localize_script('random-content', 'rcData', array(
			'restUrl' => esc_url_raw(rest_url('random-content/v1/posts')),
			'nonce'   => wp_create_nonce('wp_rest'),
		));
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
			shuffle($random_ids);
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
		if (self::$rendering) {
			return '';
		}

		$a = shortcode_atts(array(
			'group_id' => '',
			'num_posts' => 1,
			'ajax' => 'no',
		), $atts);

		self::$rendering = true;
		$posts = self::get_random_content($a['num_posts'], $a['group_id'], 'id');
		$content = '';
		if (!empty($posts)) {
			foreach ($posts as $post) {
				$content .= self::process_content($post->post_content);
			}
		}
		self::$rendering = false;

		if ($a['ajax'] === 'yes') {
			$this->has_ajax_placeholders = true;
			$placeholder = sprintf(
				'<div class="rc-placeholder" data-rc-group="%s" data-rc-num="%d" data-rc-field="id"></div>',
				esc_attr($a['group_id']),
				(int) $a['num_posts']
			);
			return $placeholder . '<noscript>' . $content . '</noscript>';
		}

		return $content;
	}

	/**
	 * Defines new random content shortcode
	 *
	 * @since 1.0
	 */
	public function new_shortcode($atts)
	{
		if (self::$rendering) {
			return '';
		}

		$a = shortcode_atts(array(
			'group_id' => '',
			'num_posts' => 1,
			'ajax' => 'no',
		), $atts);

		self::$rendering = true;
		$posts = self::get_random_content($a['num_posts'], $a['group_id'], 'id');
		$content = '';
		if (!empty($posts)) {
			foreach ($posts as $post) {
				$content .= self::process_content($post->post_content);
			}
			$content = apply_filters('rc_content', $content);
		}
		self::$rendering = false;

		if ($a['ajax'] === 'yes') {
			$this->has_ajax_placeholders = true;
			$placeholder = sprintf(
				'<div class="rc-placeholder" data-rc-group="%s" data-rc-num="%d" data-rc-field="id"></div>',
				esc_attr($a['group_id']),
				(int) $a['num_posts']
			);
			return $placeholder . '<noscript>' . $content . '</noscript>';
		}

		return $content;
	}
	// =========================================================================
	// Pro Upsell Methods
	// =========================================================================

	/**
	 * URL for the Pro upgrade page
	 */
	private function get_pro_url()
	{
		return 'https://randomcontentpro.com';
	}

	/**
	 * Enqueues admin CSS for upsell components
	 */
	public function enqueue_admin_styles($hook)
	{
		$screen = get_current_screen();
		if (!$screen) {
			return;
		}

		// Load on our CPT screens, taxonomy screens, plugins page, and our Go Pro page
		$load_on = array('endo_wrc_cpt', 'edit-endo_wrc_cpt', 'edit-endo_wrc_group', 'plugins');
		if (in_array($screen->id, $load_on, true) || $screen->id === 'endo_wrc_cpt_page_rc-go-pro') {
			wp_enqueue_style(
				'rc-admin-upsell',
				plugin_dir_url(__FILE__) . 'css/rc-admin-upsell.css',
				array(),
				$this->version
			);
		}
	}

	/**
	 * Adds locked/disabled Pro feature teaser meta boxes to the edit screen
	 */
	public function add_pro_teaser_meta_boxes()
	{
		add_meta_box(
			'rc-pro-features',
			__('Pro Features', 'random-content'),
			array($this, 'render_pro_teaser_meta_box'),
			'endo_wrc_cpt',
			'side',
			'low'
		);
	}

	/**
	 * Renders the Pro features teaser meta box content
	 */
	public function render_pro_teaser_meta_box($post)
	{
		$pro_url = $this->get_pro_url();
		?>
		<div class="rc-pro-teaser">
			<div class="rc-pro-teaser-feature">
				<span class="dashicons dashicons-calendar-alt"></span>
				<div>
					<strong><?php esc_html_e('Smart Scheduling', 'random-content'); ?></strong>
					<p><?php esc_html_e('Set date ranges and time windows for each content item.', 'random-content'); ?></p>
				</div>
			</div>
			<div class="rc-pro-teaser-feature">
				<span class="dashicons dashicons-groups"></span>
				<div>
					<strong><?php esc_html_e('Audience Targeting', 'random-content'); ?></strong>
					<p><?php esc_html_e('Show content to specific user roles or campaign visitors.', 'random-content'); ?></p>
				</div>
			</div>
			<div class="rc-pro-teaser-feature">
				<span class="dashicons dashicons-chart-bar"></span>
				<div>
					<strong><?php esc_html_e('Weighted Selection', 'random-content'); ?></strong>
					<p><?php esc_html_e('Control how often each item is shown with weight 1-10.', 'random-content'); ?></p>
				</div>
			</div>
			<div class="rc-pro-teaser-feature">
				<span class="dashicons dashicons-visibility"></span>
				<div>
					<strong><?php esc_html_e('Impression Tracking', 'random-content'); ?></strong>
					<p><?php esc_html_e('See how many times each content item has been displayed.', 'random-content'); ?></p>
				</div>
			</div>
			<div class="rc-pro-teaser-feature">
				<span class="dashicons dashicons-controls-repeat"></span>
				<div>
					<strong><?php esc_html_e('Repeat Prevention', 'random-content'); ?></strong>
					<p><?php esc_html_e('Avoid showing the same content to returning visitors.', 'random-content'); ?></p>
				</div>
			</div>
			<a href="<?php echo esc_url($pro_url); ?>" class="rc-pro-teaser-button" target="_blank" rel="noopener">
				<?php esc_html_e('Upgrade to Pro', 'random-content'); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Renders a dismissible admin banner on plugin pages
	 */
	public function render_pro_admin_banner()
	{
		// Only show on our CPT screens
		$screen = get_current_screen();
		if (!$screen) {
			return;
		}
		$our_screens = array('endo_wrc_cpt', 'edit-endo_wrc_cpt', 'edit-endo_wrc_group');
		if (!in_array($screen->id, $our_screens, true)) {
			return;
		}

		// Check if banner was dismissed
		if (get_option('rc_pro_banner_dismissed')) {
			return;
		}

		$pro_url = $this->get_pro_url();
		?>
		<div class="notice rc-pro-banner is-dismissible" data-rc-dismiss-nonce="<?php echo esc_attr(wp_create_nonce('rc_dismiss_pro_banner')); ?>">
			<div class="rc-pro-banner-inner">
				<div class="rc-pro-banner-content">
					<strong><?php esc_html_e('Unlock the full power of Random Content', 'random-content'); ?></strong>
					<p><?php esc_html_e('Get scheduling, audience targeting, weighted selection, impression tracking, and more with Random Content Pro.', 'random-content'); ?></p>
				</div>
				<a href="<?php echo esc_url($pro_url); ?>" class="button button-primary rc-pro-banner-cta" target="_blank" rel="noopener">
					<?php esc_html_e('Learn More', 'random-content'); ?>
				</a>
			</div>
		</div>
		<script>
		jQuery(function($) {
			$(document).on('click', '.rc-pro-banner .notice-dismiss', function() {
				var nonce = $(this).closest('.rc-pro-banner').data('rc-dismiss-nonce');
				$.post(ajaxurl, { action: 'rc_dismiss_pro_banner', _wpnonce: nonce });
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler to persist banner dismissal
	 */
	public function ajax_dismiss_pro_banner()
	{
		check_ajax_referer('rc_dismiss_pro_banner');
		if (current_user_can('manage_options')) {
			update_option('rc_pro_banner_dismissed', 1, true);
		}
		wp_die();
	}

	/**
	 * Adds the "Go Pro" submenu page under Random Content
	 */
	public function add_pro_submenu_page()
	{
		add_submenu_page(
			'edit.php?post_type=endo_wrc_cpt',
			__('Go Pro', 'random-content'),
			__('Go Pro', 'random-content'),
			'manage_options',
			'rc-go-pro',
			array($this, 'render_pro_page')
		);
	}

	/**
	 * Renders the Go Pro comparison page
	 */
	public function render_pro_page()
	{
		$pro_url = $this->get_pro_url();
		?>
		<div class="wrap rc-pro-page">
			<h1><?php esc_html_e('Upgrade to Random Content Pro', 'random-content'); ?></h1>
			<p class="rc-pro-page-intro">
				<?php esc_html_e('Take full control of your random content with powerful Pro features.', 'random-content'); ?>
			</p>

			<table class="rc-pro-comparison">
				<thead>
					<tr>
						<th class="rc-pro-comparison-feature"><?php esc_html_e('Feature', 'random-content'); ?></th>
						<th class="rc-pro-comparison-free"><?php esc_html_e('Free', 'random-content'); ?></th>
						<th class="rc-pro-comparison-pro"><?php esc_html_e('Pro', 'random-content'); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e('Random content display', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Shortcode & Widget support', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Content Groups', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('AJAX loading (no page cache issues)', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Gutenberg Block', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Smart Scheduling (date & time windows)', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Audience Targeting (roles & campaigns)', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Weighted Randomization', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Impression Tracking', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Repeat Prevention (cooldown & no-repeat)', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Display Rules (visibility & page types)', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Fallback Content', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Fade-in Transitions', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('GA4 / GTM Analytics Integration', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
					<tr>
						<td><?php esc_html_e('Priority Support', 'random-content'); ?></td>
						<td><span class="dashicons dashicons-minus rc-no"></span></td>
						<td><span class="dashicons dashicons-yes-alt rc-check"></span></td>
					</tr>
				</tbody>
			</table>

			<div class="rc-pro-page-cta">
				<a href="<?php echo esc_url($pro_url); ?>" class="button button-primary button-hero" target="_blank" rel="noopener">
					<?php esc_html_e('Get Random Content Pro', 'random-content'); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Adds a "Go Pro" link to the plugin row on the Plugins page
	 */
	public function add_pro_plugin_row_link($links)
	{
		$pro_url = $this->get_pro_url();
		$links[] = '<a href="' . esc_url($pro_url) . '" style="color:#00a32a;font-weight:600;" target="_blank" rel="noopener">' . esc_html__('Go Pro', 'random-content') . '</a>';
		return $links;
	}
} // end class