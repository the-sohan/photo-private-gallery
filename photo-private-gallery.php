<?php
/**
 * Plugin Name: Photo Private Gallery
 * Description: Adds paid, per-listing access controls for Directorist private galleries.
 * Version: 0.4.1
 * Author: SovWare
 * Text Domain: photo-private-gallery
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'PPG_VERSION', '0.4.1' );
define( 'PPG_FILE', __FILE__ );
define( 'PPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'PPG_URL', plugin_dir_url( __FILE__ ) );

require_once PPG_PATH . 'includes/class-ppg-activator.php';
require_once PPG_PATH . 'includes/class-ppg-settings.php';
require_once PPG_PATH . 'includes/class-ppg-frontend.php';
require_once PPG_PATH . 'includes/class-ppg-entitlements.php';
require_once PPG_PATH . 'includes/class-ppg-protected-media.php';

register_activation_hook( PPG_FILE, array( 'PPG_Activator', 'activate' ) );

/**
 * Boot the plugin after WordPress has loaded active plugins.
 */
function ppg_boot_plugin() {
	PPG_Settings::instance();
	PPG_Frontend::instance();
	PPG_Entitlements::instance();
	PPG_Protected_Media::instance();
}
add_action( 'plugins_loaded', 'ppg_boot_plugin' );

/**
 * Return the saved plugin settings with defaults applied.
 *
 * @return array<string, mixed>
 */
function ppg_get_settings() {
	$defaults = array(
		'plan_id'         => 0,
		'button_text'     => __( 'Click here to pay and view this private gallery', 'photo-private-gallery' ),
		'dashboard_label' => __( 'Paid Private Galleries', 'photo-private-gallery' ),
	);

	$settings = get_option( 'ppg_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * Return the entitlement table name.
 */
function ppg_entitlements_table() {
	global $wpdb;

	return $wpdb->prefix . 'ppg_gallery_entitlements';
}
