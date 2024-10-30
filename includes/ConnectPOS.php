<?php
/**
 * Description: ConnectPOS Class
 * Author: ConnectPOS
 *
 * @package ConnectPOS
 */

namespace ConnectPOS;

use ConnectPOS\Integration\Cpos_Integrate;

/**
 * Class ConnectPOS
 * @package ConnectPOS
 */
class ConnectPOS {
    const PLUGIN_CODE = 'connectpos';

    /**
     * WooCommerceByConnectPOS constructor.
     *
     * @param Cpos_Integrate $integration
     */
    public function __construct( Cpos_Integrate $integration ) {
        $integration->cpi_init();

        add_filter( 'woocommerce_adjust_non_base_location_prices', '__return_false' );

        add_filter( 'woocommerce_order_hide_zero_taxes', '__return_false' );
    }
}