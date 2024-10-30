<?php
/**
 * Description: ConnectPOS Integration with WordPress and external plugins.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Settings
 */

namespace ConnectPOS\Settings;

/**
 * Class Cpos_Settings
 * @package ConnectPOS\Settings
 */
class Cpos_Settings {

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected $plugin_name = 'connectpos';

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   public
     * @var      string    $admin_plugin_path    The string used to uniquely identify this plugin.
     */
    public static $admin_plugin_path;

    /**
     * Integration constructor.
     *
     * @return void
     */
    public function __construct() {
      add_action( 'activated_plugin', array( $this, 'cp_activation_redirect' ) );
      add_action( 'admin_head', array( $this, 'cp_custom_styles' ) );
    }

    /**
     * Redirect after active plugin.
     *
     * @return void
     */
    function cp_activation_redirect( $plugin ) {
      if( $plugin == plugin_basename( __FILE__ ) ) {
          exit( wp_redirect( admin_url( 'admin.php?page=connectpos' ) ) );
      }
    }

    /**
     * Additional styles.
     *
     * @return void
     */
    function cp_custom_styles() {
      echo '<style>
        .toplevel_page_connectpos > .wp-menu-image > img {
          height: 20px;
          width: 20px;
          opacity: 1 !important;
          padding-top: 0px !important;
        }

        .toplevel_page_connectpos > .wp-menu-image {
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
      </style>';
    }

    /**
     * Initialize settings.
     *
     * @return void
     */
    public function cps_init() {
      add_action('admin_menu', array( $this, 'cps_add_plugin_admin_menu' ), 9);
    }

    public function cps_add_plugin_admin_menu() {
      add_menu_page(  $this->plugin_name, 'ConnectPOS', 'administrator', $this->plugin_name, array( $this, 'cps_display_plugin_admin_dashboard' ), Cpos_Settings::$admin_plugin_path . 'admin/icons/apple-icon-20x20.png', 26 );
    }

    public function cps_display_plugin_admin_dashboard() {
      require_once 'partials/'.$this->plugin_name.'-admin-display.php';
    }
}