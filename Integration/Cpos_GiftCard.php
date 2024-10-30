<?php
/**
 * Description: ConnectPOS Integration with WooCommerce Gift Card.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use ConnectPOS\Helper\Cpos_Database;
use WP_REST_SERVER;
use WC_REST_Authentication;

/**
 * Class Cpos_GiftCard
 * @package ConnectPOS\Integration
 */
class Cpos_GiftCard {

    /**
     * @var CposDatabase
     */
    protected $db;

    /**
     * CposGiftCard constructor.
     *
     * @param Cpos_Database $db
     */
    public function __construct( Cpos_Database $db ) {
        $this->db = $db;

        add_action( 'rest_api_init', function () {
            register_rest_route( 'wc/v3', 'gift-card', [
                'methods'             => WP_REST_SERVER::READABLE,
                'callback'            => array( $this, 'cpgc_get_gift_card' ),
                'permission_callback' => '__return_true',
            ] );
            new WC_REST_Authentication();
        } );
    }

    /**
     * @param $data
     *
     * @return array|mixed
     */
    public function cpgc_get_gift_card( $data ) {
        $code_detail = $this->db->cpdb_clear()
                                ->cpdb_select()
                                ->cpdb_table( 'woocommerce_gc_cards' )
                                ->cpdb_where( 'code = %s', $data->get_param( 'code' ) )
                                ->cpdb_first();

        if ( intval( $data->get_param( 'id' ) ) ) {
            $code_detail = $this->db->cpdb_clear()
                                ->cpdb_select()
                                ->cpdb_table( 'woocommerce_gc_cards' )
                                ->cpdb_where( 'id = %d', intval($data->get_param( 'id' )) )
                                ->cpdb_first();
        }

        if ( ! $code_detail || empty( $code_detail ) ) {
            return [];
        }

        $order                    = wc_get_order( $code_detail->order_id );
        $code_detail->customer_id = (string) $order->customer_id;

        return $code_detail;
    }
}