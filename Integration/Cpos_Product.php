<?php
/**
 * Description: ConnectPOS Integration with WooCommerce Product.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use ConnectPOS\Helper\Cpos_Database;
use Iconic_WAS_Fees;
use WC_Product_Variation;
use WP_REST_Request;

/**
 * Class Cpos_Product
 * @package ConnectPOS\Integration
 */
class Cpos_Product {
    /**
     * @var CposDatabase
     */
    protected $db;

    /**
     * CposOrder constructor.
     *
     * @param Cpos_Database $db
     *
     * @return void
     */
    public function __construct( Cpos_Database $db ) {
        $this->db = $db;

        add_filter( 'woocommerce_rest_product_object_query', array( $this, 'cpp_product_date_time_filter' ), 10, 2 );

        add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'cpp_product_variants' ), 999, 1 );

        add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'cpp_product_variants' ), 20, 1 );

        add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'cpp_order_date_time' ), 20, 1 );

        add_filter( 'woocommerce_rest_prepare_shop_order_refund_object', array( $this, 'cpp_order_date_time' ), 20, 1 );
    }

    /**
     * @param $response
     * @param $object
     * @param $request
     *
     * @return mixed
     */
    public function cpp_product_variants( $response ) {
        $variations       = [];
        if ( isset( $response->data['variations'] ) ) {
            $variations = $response->data['variations'];
        }
        $variations_array = [];

        $product = wc_get_product($response->data['id']);

        if ( class_exists( 'Iconic_WAS_Fees' ) ) {
            $iconic_was_fees = Iconic_WAS_Fees::get_fees($product);

            if ( $iconic_was_fees ) {
                $response->data['iconic_was_fees'] = $iconic_was_fees;
            }
        }

        if ( empty( $variations ) || ! is_array( $variations ) ) {
            return $response;
        }

        foreach ( $variations as $variation ) {
            $variation_id                    = $variation;
            $variation                       = new WC_Product_Variation( $variation_id );

            $variations_res                  = [];
            $variations_res['id']            = $variation_id;
            $variations_res['on_sale']       = $variation->is_on_sale();
            $variations_res['regular_price'] = (float) $variation->get_regular_price();
            $variations_res['sale_price']    = (float) $variation->get_sale_price();
            $variations_res['sku']           = $variation->get_sku();
            $variations_res['quantity']      = $variation->get_stock_quantity();
            $variations_res['manage_stock']  = $variation->get_manage_stock();
            $variations_res['backorders']    = $variation->get_backorders();
            if ($variation->get_image_id() != 0) {
                $variationImageAttachment = wp_get_attachment_image_src($variation->get_image_id());
                if (!empty($variationImageAttachment)) {
                    $variations_res['image'] = $variationImageAttachment[0];
                }
            }
            if ( $variations_res['quantity'] === null ) {
                $variations_res['quantity'] = '';
            }
            $variations_res['stock'] = $variation->get_stock_quantity();

            $attributes = [];
            // variation attributes
            foreach ( $variation->get_variation_attributes() as $attribute_name => $attribute ) {
                // taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`
                $attributes[] = [
                    'name'   => wc_attribute_label( str_replace( 'attribute_', '', $attribute_name ), $variation ),
                    'slug'   => str_replace( 'attribute_', '', wc_attribute_taxonomy_slug( $attribute_name ) ),
                    'option' => $attribute,
                ];
            }

            $variations_res['meta_data']             = $variation->get_meta_data();
            $variations_res['attributes']            = $attributes;
            $variations_res['date_on_sale_to']       = $variation->get_date_on_sale_to();
            $variations_res['date_on_sale_from']     = $variation->get_date_on_sale_from();
            $variations_array[]           = $variations_res;
        }

        $response->data['product_variations'] = $variations_array;

        return $response;
    }

    /**
     * @param object $response
     *
     * @return mixed
     */
    public function cpp_order_date_time( $response ) {
        if ( $response->data === null ) {
            return $response;
        }

        if ( ! empty( $response->data['date_modified_gmt'] ) ) {
            $response->data['date_modified'] = $response->data['date_modified_gmt'];
        }

        if ( ! empty( $response->data['date_created_gmt'] ) ) {
            $response->data['date_created'] = $response->data['date_created_gmt'];
        }

        if ( ! empty( $response->data['date_completed_gmt'] ) ) {
            $response->data['date_completed'] = $response->data['date_completed_gmt'];
        }

        if ( ! empty( $response->data['date_paid_gmt'] ) ) {
            $response->data['date_paid'] = $response->data['date_paid_gmt'];
        }

        return $response;
    }

    /**
     * @param array $args
     * @param WP_REST_Request $request
     *
     * @return array
     */
    public function cpp_product_date_time_filter( array $args, WP_REST_Request $request ) {
        $modified_after  = $request->get_param( 'modified_after_gmt' );
        $modified_before = $request->get_param( 'modified_before_gmt' );

        if ( $modified_after ) {
            $args['date_query'][0]['column'] = 'post_modified_gmt';
            $args['date_query'][0]['after']  = $modified_after;
        }

        if ( $modified_before ) {
            $args['date_query'][0]['column'] = 'post_modified_gmt';
            $args['date_query'][0]['before'] = $modified_before;
        }

        return $args;
    }
}