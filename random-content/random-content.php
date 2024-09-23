<?php
/*
Plugin Name: Random Content
Plugin URI: https://www.endocreative.com
Description: Randomly display any content anywhere on your site
Author: Endo Creative
Author URI: https://www.endocreative.com
Version: 1.4.1
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * Includes the core plugin class for executing the plugin.
 */
require_once(plugin_dir_path(__FILE__) . 'class-random-content.php');


/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0
 */
function run_random_content()
{

	$plugin = new Endo_Random_Content();
	$plugin->run();
}
run_random_content();
