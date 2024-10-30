<?php
/**
 * Description: ConnectPOS Integration with WordPress and external plugins.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use ConnectPOS\Helper\Cpos_Database;

/**
 * Class Cpos_Integrate
 * @package ConnectPOS\Integration
 */
class Cpos_Integrate {

    /**
     * Integration constructor.
     *
     * @return void
     */
    public function __construct() {
        $this->cpi_include();
    }

    /**
     * Include Integration Dependency.
     *
     * @return void
     */
    protected function cpi_include() {
        include __DIR__ . '/Cpos_Customer.php';
        include __DIR__ . '/Cpos_GiftCard.php';
        include __DIR__ . '/Cpos_Order.php';
        include __DIR__ . '/Cpos_OrderStatus.php';
        include __DIR__ . '/Cpos_Polylang.php';
        include __DIR__ . '/Cpos_Product.php';
        include __DIR__ . '/Cpos_RewardPoint.php';
        include __DIR__ . '/Cpos_WordPress.php';
    }

    /**
     * Initialize Integration.
     *
     * @return void
     */
    public function cpi_init() {
        $databaseHelper = new Cpos_Database();

        new Cpos_Customer();
        new Cpos_GiftCard( $databaseHelper );
        new Cpos_Order( $databaseHelper );
        new Cpos_OrderStatus( $databaseHelper );
        new Cpos_Polylang( $databaseHelper );
        new Cpos_Product( $databaseHelper );
        new Cpos_RewardPoint( $databaseHelper );
        new Cpos_WordPress();
    }
}