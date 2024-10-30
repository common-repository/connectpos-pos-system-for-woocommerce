<?php
/**
 * Description: ConnectPOS Integration with WordPress.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use WC_REST_Authentication;
use WP_REST_Server;

/**
 * Class Cpos_WordPress
 * @package ConnectPOS\Integration
 */
class Cpos_WordPress {
    const SETTING_GROUP = 'timezone';

    /**
     * WordPress constructor.
     *
     * @return void
     */
    public function __construct() {
        // timezone setting
        add_filter( 'woocommerce_settings-' . self::SETTING_GROUP, array( $this, 'cpwp_get_setting_timezone' ) );

        add_action( 'rest_api_init', function () {
            register_rest_route( 'wc/v3', 'timezone', array(
                'methods'             => WP_REST_SERVER::READABLE,
                'callback'            => array( $this, 'cpwp_get_current_timezone' ),
                'permission_callback' => '__return_true',
            ) );
            new WC_REST_Authentication();
        } );
    }

    /**
     * @deprecated Use timezone setting instead
     * @return array
     */
    public function cpwp_get_current_timezone() {
        return array( 'timezone' => wp_timezone_string() );
    }

    /**
     * Registers timezone setting. Get timezone via WooCommerce setting group API.
     *
     * @param array $settings Existing registered settings.
     *
     * @return array
     */
    public function cpwp_get_setting_timezone($settings )
    {
        $settings[] = [
            'id'          => 'woocommerce_timezone',
            'option_key'  => 'woocommerce_timezone',
            'label'       => 'WooCommerce Timezone',
            'description' => 'WooCommerce Timezone',
            'type'        => 'text',
            'default'     => wp_timezone_string(),
            'value'       => wp_timezone_string(),
        ];

        return $settings;
    }
}