<?php

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://www.wplauncher.com
 * @since      1.0.0
 *
 * @package    ConnectPOS
 * @subpackage ConnectPOS/admin/partials
 */

use ConnectPOS\Settings\Cpos_Settings;

?>

<style>
  <?php
  echo file_get_contents(Cpos_Settings::$admin_plugin_path . 'admin/css/connectpos-admin.css');
  ?>
</style>

<div class="wrap">
  <div id="icon-themes" class="icon32"></div>
  <h2>ConnectPOS</h2>
  <!--NEED THE settings_errors below so that the errors/success messages are shown after submission - wasn't working once we started using add_menu_page and stopped using add_options_page so needed this-->
  <?php settings_errors(); ?>
  <div class="settings-page-content-container d-flex flex-column align-items-center justify-center">
    <img src="<?php echo Cpos_Settings::$admin_plugin_path . 'admin/icons/apple-icon-192x192.png'; ?>" alt="">
    <a href="https://retail.connectpos.com" target="_blank" rel="noopener noreferrer" class="btn btn-default d-inline-flex align-items-center justify-center mt-3">
      Login to ConnectPOS
    </a>
  </div>
</div>