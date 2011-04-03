<?php
/**
 * Plugin Name: Prospress Notifications
 * Description: Enable email notifications within Prospress auction marketplaces
 * Author: Andrew Patton
 * Author URI: http://www.purecobalt.com
 * Plugin URI: http://www.purecobalt.com/wp/prospress-notifications
 * Version: 0.0.2
 * Revision Date: March 11, 2011
 * Requires at least: WP 3.0
 * Tested up to: WP 3.1
 */


// Set plugin constants
define ( 'PC_PPN_DB_VERSION', 0.2 );
define ( 'PC_PPN_DIR', dirname( __FILE__ ) );
define ( 'PC_PPN_URL', plugin_dir_url( __FILE__ ) );


// Load core functionality
function pc_ppn_init() {
	require_once PC_PPN_DIR . '/ppn-core.php';
}
// load notifications when prospress auctions market system is being constructed
add_action( 'auction_init', 'pc_ppn_init' );
