<?php
/**
 * Plugin Name: AutoAltify
 * Plugin URI:  https://github.com/vishalpadhariya/autoaltify
 * Description: Auto-generate missing ALT text for image attachments based on the image title. Includes bulk actions, settings, AJAX bulk-run, logging, and developer hooks.
 * Version:     1.1.0
 * Author:      Vishal Padhariya
 * Author URI:  https://vishalpadhariya.github.io
 * Text Domain: autoaltify
 * Domain Path: /languages
 *
 * License: GPLv2 or later
 *
 * @package AutoAltify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AUTOALTIFY_VERSION', '1.1.0' );
define( 'AUTOALTIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTOALTIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once AUTOALTIFY_PLUGIN_DIR . 'includes/Core/Generator.php';
require_once AUTOALTIFY_PLUGIN_DIR . 'includes/Core/Logger.php';
require_once AUTOALTIFY_PLUGIN_DIR . 'includes/Core/Options.php';
require_once AUTOALTIFY_PLUGIN_DIR . 'includes/Admin/Admin.php';
require_once AUTOALTIFY_PLUGIN_DIR . 'includes/Public/Public_Hooks.php';

use AutoAltify\Core\Generator;
use AutoAltify\Core\Logger;
use AutoAltify\Core\Options;
use AutoAltify\Admin\Admin;
use AutoAltify\Public_Hooks\Public_Hooks;

// Plugin initialization.
register_activation_hook( __FILE__, 'autoaltify_activate' );
register_deactivation_hook( __FILE__, 'autoaltify_deactivate' );

/**
 * Plugin activation.
 */
function autoaltify_activate() {
	$options = new Options();
	$options->initialize();
}

/**
 * Plugin deactivation (keep settings and logs).
 */
function autoaltify_deactivation() {
	// Keep settings and logs on deactivation.
}

// Initialize plugin on plugins_loaded.
add_action( 'plugins_loaded', 'autoaltify_init' );

/**
 * Initialize AutoAltify plugin.
 */
function autoaltify_init() {
	$options = new Options();
	$generator = new Generator();
	$logger = new Logger();

	// Initialize admin.
	if ( is_admin() ) {
		new Admin( $options, $generator, $logger );
	}

	// Initialize public hooks.
	new Public_Hooks( $options, $generator, $logger );
}
