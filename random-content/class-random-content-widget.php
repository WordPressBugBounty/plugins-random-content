<?php

class Endo_WRC_Widget extends WP_Widget
{

	public function __construct()
	{
		$options = array(
			'description'	=> __('Display random content from the selected group', 'random-content'),
			'name' 			=> __('Random Content', 'random-content')
		);

		parent::__construct('endo_wrc_widget', 'WRC_Widget', $options);
	}

	public function widget($args, $instance)
	{
		$title = isset($instance['title']) ? $instance['title'] : '';
		$group = isset($instance['group']) ? $instance['group'] : '';
		$num_posts = isset($instance['num_posts']) ? absint($instance['num_posts']) : 1;

		$title = apply_filters('widget_title', $title);

		echo $args['before_widget'];

		if (!empty($title)) {
			echo $args['before_title'] . esc_html($title) . $args['after_title'];
		}

		// Use shared method from main class (uses 'slug' field for widget)
		$posts = Endo_Random_Content::get_random_content($num_posts, $group, 'slug');

		if (!empty($posts)) {
			foreach ($posts as $post) {
				setup_postdata($post);
				$thecontent = apply_filters('the_content', $post->post_content);
				echo wp_kses_post(apply_filters('rc_content', $thecontent));
			}
			wp_reset_postdata();
		}

		echo $args['after_widget'];
	}

	public function update($new_instance, $old_instance)
	{
		$instance = array();
		$instance['title'] = sanitize_text_field($new_instance['title']);
		$instance['group'] = sanitize_text_field($new_instance['group']);
		$instance['num_posts'] = absint($new_instance['num_posts']);
		return $instance;
	}

	public function form($instance)
	{
		$title = isset($instance['title']) ? $instance['title'] : '';
		$group = isset($instance['group']) ? $instance['group'] : '';
		$num_posts = isset($instance['num_posts']) ? $instance['num_posts'] : 1;
?>

		<p>
			<label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'random-content'); ?> </label>
			<input type="text" class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" value="<?php echo esc_attr($title); ?>" />
		</p>

<?php
		$field_id = esc_attr($this->get_field_id('group'));
		$field_name = esc_attr($this->get_field_name('group'));

		$terms = get_terms(array(
			'taxonomy' => 'endo_wrc_group',
			'fields' => 'names',
			'hide_empty' => false,
		));

		if (!is_wp_error($terms) && !empty($terms)) {
			echo '<p>';
			echo '<label for="' . $field_id . '">' . esc_html__('Group:', 'random-content') . '</label>';
			echo '<select class="widefat" name="' . $field_name . '" id="' . $field_id . '">';
			echo '<option value=""' . selected($group, '', false) . '>' . esc_html__('All Groups', 'random-content') . '</option>';
			foreach ($terms as $term) {
				echo '<option value="' . esc_attr($term) . '"' . selected($group, $term, false) . '>' . esc_html($term) . '</option>';
			}
			echo '</select>';
			echo '</p>';
		} else {
			echo '<p>' . esc_html__('Create a group to organize multiple widgets.', 'random-content') . '</p>';
		}

		echo '<p>';
		echo '<input type="number" min="1" class="small-text" id="' . esc_attr($this->get_field_id('num_posts')) . '" name="' . esc_attr($this->get_field_name('num_posts')) . '" value="' . esc_attr($num_posts) . '" />';
		echo ' <label for="' . esc_attr($this->get_field_id('num_posts')) . '">' . esc_html__('Number of items to show at once', 'random-content') . '</label>';
		echo '</p>';
	}
}
