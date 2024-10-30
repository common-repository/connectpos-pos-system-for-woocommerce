<?php
/**
 * Plugin Name: ConnectPOS for fashion stores | Point of Sale for WooCommerce
 * Description: Integrate WooCommerce with ConnectPOS.
 * Author: ConnectPOS
 * Author URI: https://github.com/devconnectpos
 * version: 24.09.05
 * Text Domain: https://connectpos.com
 *
 * @package ConnectPOS
 */

defined( 'ABSPATH' ) || exit;

// wordpress
include_once ABSPATH . 'wp-admin/includes/plugin.php';

require __DIR__ . '/includes/ConnectPOS.php';

// Helper
include __DIR__ . '/Helper/Cpos_Database.php';

// Integration
include __DIR__ . '/Integration/Cpos_Integrate.php';
// Settings
include __DIR__ . '/admin/Cpos_Settings.php';

ConnectPOS\Settings\Cpos_Settings::$admin_plugin_path = plugin_dir_url( __FILE__ );
$integration = new ConnectPOS\Integration\Cpos_Integrate();
$setting = new ConnectPOS\Settings\Cpos_Settings();

$setting->cps_init();
$GLOBALS['woocommerce_by_connectpos'] = new ConnectPOS\ConnectPOS( $integration );