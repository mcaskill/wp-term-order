<?php

/**
 * Plugin Name: WP Term Order
 * Plugin URI:  https://wordpress.org/plugins/wp-term-order/
 * Author:      John James Jacoby
 * Author URI:  https://jjj.blog/
 * Version:     0.1.6
 * Description: Sort taxonomy terms, your way
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Include the required files & dependencies
 *
 * @since 0.1.6
 */
function _wp_term_order_load() {
    // Setup the main file
    $plugin_path = plugin_dir_path( __FILE__ );

    // Classes
    require_once $plugin_path . 'includes/class-wp-term-order.php';
}
add_action( 'plugins_loaded', '_wp_term_order_load' );

/**
 * Instantiate the main WordPress Term Order class
 *
 * @since 0.1.0
 */
function _wp_term_order_init() {
    new WP_Term_Order( __FILE__ );
}
add_action( 'init', '_wp_term_order_init', 99 );
