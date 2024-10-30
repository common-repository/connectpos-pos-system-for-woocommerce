<?php
/**
 * Description: ConnectPOS Integration with WooCommerce Orders.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use ConnectPOS\ConnectPOS;
use ConnectPOS\Helper\Cpos_Database;
use WC_API_Exception;
use WC_Meta_Data;
use WC_Order_Item_Product;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_SERVER;
use WC_REST_Authentication;
use WP_HTTP_Response;
use WP_Error;
use WC_Order;
use WC_Points_Rewards_Manager;
use MyParcelNL\WooCommerce\includes\admin\OrderSettings;
use WPO\WC\MyParcel\Compatibility\WC_Core as WCX;
use MyParcelNL\WooCommerce\includes\admin\Messages;
use WCMP_Export;
use WPO_WCPDF;

/**
 * Class Cpos_Order
 * @package ConnectPOS\Integration
 */
class Cpos_Order {
    const HIDDEN_META_KEY = '_cpos';
    const VISIBLE_META_KEY = 'cpos';

    const TAX_RATE_ID_STEP = 1000000;

    /**
     * @var Cpos_Database
     */
    protected $db;

    /**
     * @var array
     */
    protected $custom_taxes = [];

    /**
     * @var array
     */
    protected $hidden_meta_keys = [];

    /**
     * @var string
     */
    protected $order_locale;

    /**
     * @var string
     */
    protected $order_tax_config;

    /**
     * CposOrder constructor.
     *
     * @param Cpos_Database $db
     *
     * @return void
     */
    public function __construct( Cpos_Database $db ) {
        $this->db = $db;

        // handle conflict when refund with WooCommerce Advanced Dynamic Pricing Plugin
        add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'cpo_handle_conflict_refund' ), 9, 0 );

        // update order gift card, reward point meta data
        add_action( 'woocommerce_update_order', array( $this, 'cpo_update_meta_after_create_order' ), 10, 1 );

        // order email with custom locale
        add_action( 'woocommerce_order_status_changed', array( $this, 'cpo_calculate_order_total' ), 10, 1 );

        add_action( 'woocommerce_new_order', array( $this, 'cpo_order_email_locale' ), 10, 1 );

        // order item cpos meta data
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'cpos_hide_hidden_cpos_meta_data' ), 20, 1 );

        add_action( 'woocommerce_before_order_itemmeta', array( $this, 'cpo_get_hidden_cpos_meta_data' ), 10, 2 );

        add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'cpo_display_cpos_meta_data' ), 20, 1 );

        // Recalculate custom tax rate and order tax config
        add_filter( 'rest_request_before_callbacks', array( $this, 'cpo_set_custom_tax' ), 20, 3 );

        add_filter( 'woocommerce_find_rates', array( $this, 'cpo_replace_tax_rate' ), 20, 1 );

        add_action( 'woocommerce_before_order_object_save', array( $this, 'cpo_update_custom_tax_data' ), 10, 1 );

        add_action( 'woocommerce_order_before_calculate_totals', array( $this, 'cpo_set_order_tax_config' ), 10, 2 );

        add_filter( 'woocommerce_prices_include_tax', array( $this, 'cpo_set_order_item_tax_config' ), 20, 1 );

        add_filter( 'woocommerce_rest_prepare_shop_order_refund_object', array( $this, 'cpo_prepare_refund_response' ), 20, 3 );

        // List order refunds endpoint
        add_action( 'rest_api_init', function () {
            register_rest_route( 'wc/v3', 'order-refunds', [
                'methods'             => WP_REST_SERVER::READABLE,
                'callback'            => array( $this, 'cpo_get_order_refunds' ),
                'permission_callback' => '__return_true',
            ] );
            new WC_REST_Authentication();
        } );

        // Payment status column
        add_action( 'wp_loaded', array( $this, 'custom_columns' ), 20 );
        add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'cpo_adjust_paid_amount' ), 20 );
        add_action( 'woocommerce_rest_insert_shop_order_object', array( $this, 'cpo_update_suborders' ), 10, 3 );

        // Packing slip & Shipping label
        // if ( class_exists( 'WCMP_Export' ) ) {
        add_action('rest_api_init', function () {
            register_rest_route('wc/v3', 'myparcel-export', [
                'methods'             => WP_REST_SERVER::CREATABLE,
                'callback'            => array($this, 'cpo_myparcel_export'),
                'permission_callback' => '__return_true',
            ]);
            new WC_REST_Authentication();
        });
        add_filter('wpo_wcpdf_guest_access_enabled', array($this, 'cpo_allow_guest_and_private_for_wcpdf'), 20, 2);
        add_filter('wpo_wcpdf_check_privs', array($this, 'cpo_allow_guest_and_private_for_wcpdf'), 20, 2);
        add_filter('wc_myparcel_check_privs', array($this, 'cpo_allow_guest_and_private_for_myparcel'), 20, 2);
        // }
    }

    /**
     * Set order line item price include tax based on previously saved 'price_include_tax' flag from request
     *
     * @param bool $result
     * @return bool
     */
    public function cpo_set_order_item_tax_config($result)
    {
        if (empty($this->order_tax_config)) {
            return $result;
        }

        return (bool) $this->order_tax_config;
    }

    /**
     * Set order price include tax based on previously saved 'price_include_tax' flag from request
     *
     * @param bool $and_taxes
     * @param WC_Order $order
     * @throws \WC_Data_Exception
     */
    public function cpo_set_order_tax_config($and_taxes, $order)
    {
        if (empty($this->order_tax_config)) {
            return;
        }

        $order->set_prices_include_tax((bool) $this->order_tax_config);
    }

    /**
     * Format meta data key before display in admin panel
     *
     * @param string $display_key
     *
     * @return string
     */
    public function cpo_display_cpos_meta_data( $display_key ) {
        if (strpos($display_key, self::VISIBLE_META_KEY) !== 0) {
            return $display_key;
        }

        return ucwords(str_replace( [self::VISIBLE_META_KEY, '_', '-' ], ['', ' ', ' '], $display_key));
    }

    /**
     * Hide mete data with specific cpos key
     *
     * @param array $result
     *
     * @return array
     */
    public function cpos_hide_hidden_cpos_meta_data($result) {
        if (empty($this->hidden_meta_keys)) {
            return $result;
        }

        return array_merge($result, array_values($this->hidden_meta_keys));
    }

    /**
     * Save hidden meta data temporarily for future use
     *
     * @param int $item_id
     * @param WC_Order_Item_Product $item
     */
    public function cpo_get_hidden_cpos_meta_data( $item_id, $item ) {
        $meta_data = $item->get_meta_data();
        if ( empty( $meta_data ) ) {
            return;
        }

        /** @var WC_Meta_Data $meta */
        foreach ( $meta_data as $meta ) {
            $data = $meta->get_data();
            if (empty($data) || !isset($data['key'])) {
                continue;
            }

            if ( strpos($data['key'], self::HIDDEN_META_KEY) === false) {
                continue;
            }

            $this->hidden_meta_keys[$data['key']] = $data['key'];
        }
    }

    /**
     * Save custom tax data temporarily into class property for later use.
     * The data only exist during the runtime of the request.
     *
     * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result to send to the client.
     *                                                                   Usually a WP_REST_Response or WP_Error.
     * @param array $handler Route handler used for the request.
     * @param WP_REST_Request $request Request used to generate the response.
     *
     * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
     */
    public function cpo_set_custom_tax( $response, $handler, $request ) {
        if ( $request->get_route() !== '/wc/v3/orders' ) {
            return $response;
        }

        if ( $request->get_param( 'created_via' ) !== ConnectPOS::PLUGIN_CODE ) {
            return $response;
        }

        // set tax config from request payload
        $this->order_tax_config = (string) $request->get_param('prices_include_tax');

        $tax_lines = $request->get_param( 'tax_lines' );
        if ( ! is_array( $tax_lines ) || empty( $tax_lines ) ) {
            return $response;
        }

        foreach ( $tax_lines as $idx => $tax_line ) {
            // Increase the rate id by TAX_RATE_ID_STEP to avoid matching existing tax rate id by WooCommerce
            $this->custom_taxes[ $idx + self::TAX_RATE_ID_STEP ] = $tax_line;
        }

        return $response;
    }

    /**
     * Replace WooCommerce tax rate with custom tax rate data
     *
     * @param array $matched_tax_rates
     *
     * @return array
     */
    public function cpo_replace_tax_rate( $matched_tax_rates ) {
        if ( empty( $this->custom_taxes ) ) {
            return $matched_tax_rates;
        }

        $replaced_rate = [];
        foreach ( $this->custom_taxes as $rate_id => $tax ) {
            $data = [
                'rate'     => isset( $tax['rate_percent'] ) ? (float) $tax['rate_percent'] : 0,
                'label'    => isset( $tax['label'] ) ? $tax['label'] : 'Custom Tax',
                'shipping' => 'no',
                'compound' => 'no',
            ];

            if ( isset( $tax['compound'] ) && (bool) $tax['compound'] ) {
                $data['compound'] = 'yes';
            }

            $replaced_rate[ $rate_id ] = $data;
        }

        return $replaced_rate;
    }

    /**
     * Update and persist custom tax data into the database
     *
     * @param WC_Order $order
     */
    public function cpo_update_custom_tax_data( $order ) {
        if ( empty( $this->custom_taxes ) || empty( $order->get_items( 'tax' ) ) ) {
            return;
        }

        foreach ( $order->get_items( 'tax' ) as $tax ) {
            $tax_data = $tax->get_data();

            if ( ! isset( $tax_data['rate_id'], $this->custom_taxes[ (int) $tax_data['rate_id'] ] ) ) {
                continue;
            }

            $custom_tax = $this->custom_taxes[ (int) $tax_data['rate_id'] ];
            $tax->set_props( [
                'label'        => $custom_tax['label'],
                'rate_code'    => $custom_tax['rate_code'],
                'rate_percent' => $custom_tax['rate_percent'],
            ] );

            $order->remove_item( $tax->get_id() );
            $order->add_item( $tax );
        }
    }

    /**
     * @return void
     */
    public function cpo_handle_conflict_refund() {
        if ( class_exists( '\ADP\BaseVersion\Includes\Context' ) && class_exists( '\ADP\BaseVersion\Includes\External\WcCartStatsCollector' ) ) {
            remove_all_actions( 'woocommerce_order_after_calculate_totals', 10 );
        }
    }

    /**
     * @param $order_id
     *
     * @return void
     */
    public function cpo_update_meta_after_create_order( $order_id ) {
        if ( ! class_exists( 'WC_Customer' ) || ! class_exists( 'WC_Gift_Cards' ) ) {
            return;
        }

        remove_action( 'woocommerce_update_order', array( $this, 'cpo_update_meta_after_create_order' ), 10 );

        $post_meta_table = $this->db->cpdb_get_db()->postmeta;
        $gc_code         = $this->db->cpdb_clear()
                                    ->cpdb_select()
                                    ->cpdb_table( $post_meta_table )
                                    ->cpdb_where( 'meta_key = %s', '_wc_gc_code' )
                                    ->cpdb_where( 'post_id = %d', $order_id )
                                    ->cpdb_first();

        $gc_redeem = $this->db->cpdb_clear()
                              ->cpdb_select()
                              ->cpdb_table( $post_meta_table )
                              ->cpdb_where( 'meta_key = %s', '_wc_gc_redeemed' )
                              ->cpdb_where( 'post_id = %d', $order_id )
                              ->cpdb_first();

        $old_gc_code = $this->db->cpdb_clear()
                                ->cpdb_select()
                                ->cpdb_table( $post_meta_table )
                                ->cpdb_where( 'meta_key = %s', '_old_gc_code' )
                                ->cpdb_where( 'post_id = %d', $order_id )
                                ->cpdb_first();

        if ( $gc_code->meta_value === '' || $gc_redeem->meta_value === '' ) {
            return;
        }

        $gc_detail = $this->db->cpdb_clear()
                              ->cpdb_select()
                              ->cpdb_table( 'woocommerce_gc_cards' )
                              ->cpdb_where( 'code = %s', $gc_code->meta_value )
                              ->cpdb_first();

        $order         = wc_get_order( $order_id );
        $customerID    = $order->get_customer_id();
        $customerEmail = (string) $order->get_billing_email();

        if ( ( ! $old_gc_code || ( $old_gc_code->meta_value !== $gc_code->meta_value ) ) && ! is_null($gc_code) && ! is_null($gc_code->meta_value) ) {
            $this->db->cpdb_clear()
                     ->cpdb_table( 'wp_woocommerce_gc_activity' )
                     ->cpdb_insert( [
                         'user_id'    => $customerID,
                         'user_email' => $customerEmail,
                         'type'       => 'used',
                         'object_id'  => $order_id,
                         'gc_id'      => (float) $gc_detail->id,
                         'gc_code'    => $gc_code->meta_value,
                         'amount'     => (float) $gc_redeem->meta_value,
                         'date'       => time(),
                         'note'       => ''
                     ] );

            $this->db->cpdb_clear()
                     ->cpdb_table( $post_meta_table )
                     ->cpdb_insert( [
                         'post_id'    => $order_id,
                         'meta_key'   => '_old_gc_code',
                         'meta_value' => $gc_code->meta_value
                     ] );

            $this->db->cpdb_clear()
                     ->cpdb_table( 'woocommerce_gc_cards' )
                     ->cpdb_update(
                         [ 'remaining' => (float) $gc_detail->remaining - (float) $gc_redeem->meta_value ],
                         [ 'code' => $gc_code->meta_value ],
                         [ '%f' ],
                         [ '%s' ]
                     );
        }
    }

    /**
     * @param $order_id
     */
    public function cpo_order_email_locale( $order_id ) {
        if ( ! class_exists( 'Polylang_Woocommerce' ) ) {
            return;
        }

        $locale = $this->db->cpdb_clear()
                           ->cpdb_select()
                           ->cpdb_table( $this->db->cpdb_get_db()->postmeta )
                           ->cpdb_where( 'meta_key = %s', 'locale' )
                           ->cpdb_where( 'post_id = %d', $order_id )
                           ->cpdb_first();

        if ( ! $locale || empty( $locale ) ) {
            return;
        }

        $locale_value      = $locale->meta_value;
        $description_value = sprintf( '%%%s%%%s%%', 'locale', $locale_value );
        $language_term     = $this->db->cpdb_clear()
                                      ->cpdb_select()
                                      ->cpdb_table( $this->db->cpdb_get_db()->term_taxonomy )
                                      ->cpdb_where( 'taxonomy = %s', 'language' )
                                      ->cpdb_where( 'description LIKE %s', $description_value )
                                      ->cpdb_first();

        if ( ! $language_term || empty( $language_term ) ) {
            return;
        }

        $term_id           = $language_term->term_taxonomy_id;
        $term_relationship = $this->db->cpdb_clear()
                                      ->cpdb_select()
                                      ->cpdb_table( $this->db->cpdb_get_db()->term_relationships )
                                      ->cpdb_where( 'object_id = %s', $order_id )
                                      ->cpdb_where( 'term_taxonomy_id = %d', $term_id )
                                      ->cpdb_first();

        if ( ! empty( $term_relationship ) ) {
            return;
        }

        add_filter( 'woocommerce_email_setup_locale', '__return_true', 100 );
        $list_locale = pll_languages_list( [ 'fields' => 'locale' ] );

        foreach ( $list_locale as $iValue ) {
            $elem        = $iValue;
            $locale_lang = explode( '_', $elem )[0];

            if ( $locale_lang === $locale_value ) {
                $this->order_locale = $elem;
                add_filter( 'plugin_locale', array( $this, 'cpo_plugin_locale' ), 100 );
                add_filter( 'locale', array( $this, 'cpo_plugin_locale' ), 100 );
            }
        }
        $this->db->cpdb_clear()
                 ->cpdb_table( 'wp_term_relationships' )
                 ->cpdb_insert( [
                     'object_id'        => $order_id,
                     'term_taxonomy_id' => $term_id,
                     'term_order'       => 0
                 ] );
    }

    /**
     * @return string
     */
    public function cpo_plugin_locale() {
        return $this->order_locale;
    }

    /**
     * @param $order_id
     */
    public function cpo_calculate_order_total( $order_id ) {
        if ( ! is_plugin_active('woocommerce-points-and-rewards/woocommerce-points-and-rewards.php') ) {
            return;
        }

        $order           = wc_get_order( $order_id );
        $post_meta_table = $this->db->cpdb_get_db()->postmeta;

        $rp_redeem = $this->db->cpdb_clear()
                              ->cpdb_select()
                              ->cpdb_table( $post_meta_table )
                              ->cpdb_where( 'meta_key = %s', '_wc_points_redeemed' )
                              ->cpdb_where( 'post_id = %d', $order_id )
                              ->cpdb_first();

        $rp_earned = $this->db->cpdb_clear()
                              ->cpdb_select()
                              ->cpdb_table( $post_meta_table )
                              ->cpdb_where( 'meta_key = %s', '_wc_points_earned' )
                              ->cpdb_where( 'post_id = %d', $order_id )
                              ->cpdb_first();

        $order_total = $this->db->cpdb_clear()
                                ->cpdb_select()
                                ->cpdb_table( $post_meta_table )
                                ->cpdb_where( 'meta_key = %s', '_order_total' )
                                ->cpdb_where( 'post_id = %d', $order_id )
                                ->cpdb_first();

        $cart_discount = $this->db->cpdb_clear()
                                  ->cpdb_select()
                                  ->cpdb_table( $post_meta_table )
                                  ->cpdb_where( 'meta_key = %s', '_cart_discount' )
                                  ->cpdb_where( 'post_id = %d', $order_id )
                                  ->cpdb_first();

        $redeem_value = (float) $rp_redeem->meta_value;
        $earn_value   = (float) $rp_earned->meta_value;

        if ( $redeem_value <= 0 ) {
            return;
        }

        $point               = (float) WC_Points_Rewards_Manager::calculate_points_value( $redeem_value );
        $point_earned_reduce = (float) WC_Points_Rewards_Manager::calculate_points_value( $point );
        $rp_point            = (float) WC_Points_Rewards_Manager::get_users_points( $order->customer_id );

        $point_earned = $earn_value - $point_earned_reduce;
        $point_earned = $point_earned ?: 0;

        $this->db->cpdb_clear()
                 ->cpdb_table( $post_meta_table )
                 ->cpdb_update(
                     [ 'meta_value' => (string) ( $point + (float) $cart_discount->meta_value ) ],
                     [ 'post_id' => $order_id, 'meta_key' => '_cart_discount' ],
                     [ '%s' ],
                     [ '%d', '%s' ]
                 );

        $this->db->cpdb_clear()
                 ->cpdb_table( $post_meta_table )
                 ->cpdb_update(
                     [ 'meta_value' => $point_earned ],
                     [ 'post_id' => $order_id, 'meta_key' => '_wc_points_earned' ],
                     [ '%s' ],
                     [ '%d', '%s' ]
                 );

        $this->db->cpdb_clear()
                 ->cpdb_table( $post_meta_table )
                 ->cpdb_update(
                     [ 'meta_value' => $rp_point - $redeem_value, ],
                     [ 'user_id' => $order->customer_id, 'meta_key' => 'wc_rp' ],
                     [ '%d' ],
                     [ '%d', '%s' ]
                 );

        $this->db->cpdb_clear()
                 ->cpdb_table( 'wc_points_rewards_user_points_log' )
                 ->cpdb_insert( [
                     'user_id'  => $order->customer_id,
                     'points'   => $redeem_value,
                     'type'     => 'order-redeem',
                     'order_id' => $order_id
                 ] );

        $this->db->cpdb_clear()
                 ->cpdb_table( 'wc_points_rewards_user_points_log' )
                 ->cpdb_update(
                     [ 'points' => $point_earned ],
                     [ 'type' => 'order-placed', 'order_id' => $order_id ],
                     [ '%d' ],
                     [ '%s', '%d' ]
                 );

        $this->db->cpdb_clear()
                 ->cpdb_table( 'wc_points_rewards_user_points' )
                 ->cpdb_insert( [
                     'user_id'        => $order->customer_id,
                     'points'         => $point_earned,
                     'points_balance' => - $redeem_value,
                     'order_id'       => $order_id
                 ] );

        if ( $order_total ) {
            $this->db->cpdb_clear()
                     ->cpdb_table( $post_meta_table )
                     ->cpdb_update(
                         [ 'meta_value' => (float) $order_total->meta_value - $point ],
                         [ 'post_id' => $order_id, 'meta_key' => '_order_total' ],
                         [ '%s' ],
                         [ '%d', '%s' ]
                     );
        }

    }

    /**
     * @param WP_REST_Request $data
     *
     * @return mixed
     */
    public function cpo_get_order_refunds( WP_REST_Request $data ) {
        $order_ids = $data->get_param( 'order_ids' );

        if ( ! $order_ids ) {
            return json_decode( '{}' );
        }

        $response = [];
        foreach ( explode( ',', $order_ids ) as $order_id ) {
            $order_refunds = [];
            $refund_ids    = wc_get_orders( [
                'type'   => 'shop_order_refund',
                'parent' => $order_id,
                'limit'  => - 1,
                'return' => 'ids'
            ] );

            foreach ( $refund_ids as $refund_id ) {
                $order_refunds[] = $this->cpo_get_order_refund( $order_id, $refund_id );
            }

            $response[ (string) $order_id ] = $order_refunds;
        }

        return $response;
    }

    /**
     * @param mixed $order_id
     * @param mixed $id
     *
     * @return array
     */
    public function cpo_get_order_refund( $order_id, $id ) {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $id );
        if ( ! $refund ) {
            return [];
        }

        $line_items = [];

        // Add line items
        foreach ( $refund->get_items() as $item_id => $item ) {
            $product = $item->get_product();

            // line item meta data
            $hide_prefix = ( isset( $filter['all_item_meta'] ) && $filter['all_item_meta'] === 'true' ) ? null : '_';
            $item_meta   = $item->get_formatted_meta_data( $hide_prefix );
            foreach ( $item_meta as $key => $values ) {
                $item_meta[ $key ]->label = $values->display_key;
                unset( $item_meta[ $key ]->display_key, $item_meta[ $key ]->display_value );
            }

            $refund_item_meta_data = $this->db->cpdb_clear()
                                              ->cpdb_select()
                                              ->cpdb_table( 'woocommerce_order_itemmeta' )
                                              ->cpdb_where( 'meta_key = %s', '_refunded_item_id' )
                                              ->cpdb_where( 'order_item_id = %d', $item_id )
                                              ->cpdb_first();
            $meta                  = [];
            if ( $refund_item_meta_data ) {
                $meta = [
                    'id'            => (int) $refund_item_meta_data->meta_id,
                    'key'           => $refund_item_meta_data->meta_key,
                    'value'         => $refund_item_meta_data->meta_value,
                    'display_key'   => $refund_item_meta_data->meta_key,
                    'display_value' => $refund_item_meta_data->meta_value
                ];
            }

            $line_items[] = [
                'id'               => $item_id,
                'subtotal'         => wc_format_decimal( $order->get_line_subtotal( $item ), 2 ),
                'subtotal_tax'     => wc_format_decimal( $item->get_subtotal_tax(), 2 ),
                'total'            => wc_format_decimal( $order->get_line_total( $item ), 2 ),
                'total_tax'        => wc_format_decimal( $order->get_line_tax( $item ), 2 ),
                'price'            => wc_format_decimal( $order->get_item_total( $item ), 2 ),
                'quantity'         => $item->get_quantity(),
                'tax_class'        => $item->get_tax_class(),
                'name'             => $item->get_name(),
                'product_id'       => $item->get_variation_id() ?: $item->get_product_id(),
                'sku'              => is_object( $product ) ? $product->get_sku() : null,
                'meta_data'        => [ $meta ],
                'refunded_item_id' => (int) $item->get_meta( 'refunded_item_id' )
            ];
        }

        return [
            'id'         => $refund->get_id(),
            'created_at' => $refund->get_date_created() ? $refund->get_date_created() . '' : 0,
            'amount'     => wc_format_decimal( $refund->get_amount(), 2 ),
            'reason'     => $refund->get_reason(),
            'line_items' => $line_items
        ];

    }

	public function custom_columns() {
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_column' ), PHP_INT_MAX );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'order_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'source_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'paid_amount_column' ), 10, 2 );
	}

    /**
	 * Adds the payment status column in the orders list table.
	 *
	 * @since 0.1
	 *
	 * @param array $columns List of table columns.
	 * @return array modified list of columns.
	 */
	public function add_order_column( $columns ) {
        $columns['cpos_order_from'] = 'Order from';
        $columns['cpos_payment_status'] = 'Payment status';
        $columns['cpos_paid_amount'] = 'Paid amount';

		return $columns;
	}

	/**
	 * Fills the payment status column of each order.
	 *
	 * @since 0.1
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Order ID.
	 */
	public function order_column( $column, $post_id ) {
        $order = wc_get_order( $post_id );
        $source = $order->get_meta('Source');
        
		if ( 'cpos_payment_status' == $column ) {
            if ($source != 'in_store' && $source != 'In Store') {
                $is_paid = !is_null($order->get_date_paid());
                echo '<span style="text-transform: capitalize">' . ( $is_paid ? 'Paid' : 'Unpaid' ) . '</span>';
                return;
            }

			echo '<span style="text-transform: capitalize">' . join(' ', explode('_', $order->get_meta('_cpos_payment_status'))) . '</span>';
		}

	}

	/**
	 * Fills the order from column of each order.
	 *
	 * @since 0.1
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Order ID.
	 */
	public function source_column( $column, $post_id ) {
        $order = wc_get_order( $post_id );
        $source = $order->get_meta('Source');
        
		if ( 'cpos_order_from' == $column ) {
            if ($source == 'in_store' || $source == 'In Store') {
                echo '<span style="text-transform: capitalize">' . 'In Store' . '</span>';
                return;
            }
			echo '<span style="text-transform: capitalize">' . 'Ecommerce' . '</span>';
		}
	}

    private function get_meta_payment(WC_Meta_Data $meta_data)
    {
        return str_contains($meta_data->get_data()['key'], 'Payment: ');
    }

    private function reduce_payment_amount($prev, $current_meta)
    {
        return $prev + floatval($current_meta->get_data()['value']);
    }

	/**
	 * Fills the order from column of each order.
	 *
	 * @since 0.1
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Order ID.
	 */
	public function paid_amount_column( $column, $post_id ) {
        $order = wc_get_order( $post_id );
        $source = $order->get_meta('Source');
        $meta_data = $order->get_meta_data();
        $meta_payments = array_filter($meta_data, [$this, 'get_meta_payment']);
        $has_date_paid = !is_null($order->get_date_paid());

        if ($source == 'in_store' || $source == 'In Store') {
            $total = array_reduce($meta_payments, [$this, 'reduce_payment_amount'], 0);
            if ($total > $order->get_total())
            {
                $total = $order->get_total();
            }

            $total_paid = wc_price($total);
        } else {
            if ($has_date_paid) {
                $total_paid = wc_price(floatval($order->get_total()));
            } else {
                $total_paid = wc_price(0);
            }
        }
		if ( 'cpos_paid_amount' == $column ) {
			echo '<span style="text-transform: capitalize">' . $total_paid . '</span>';
		}
	}

    function getElementByClass(&$parentNode, $tagName, $className, $offset = 0) {
        $response = false;
    
        $childNodeList = $parentNode->getElementsByTagName($tagName);
        $tagCount = 0;
        for ($i = 0; $i < $childNodeList->length; $i++) {
            $temp = $childNodeList->item($i);
            if (stripos($temp->getAttribute('class'), $className) !== false) {
                if ($tagCount == $offset) {
                    $response = $temp;
                    break;
                }
    
                $tagCount++;
            }
    
        }
    
        return $response;
    }

	/**
	 * Create hooks handle displayed order paid amount.
	 */
	public function cpo_adjust_paid_amount( $order_id ) {
        $order        = wc_get_order( $order_id );
        $source = $order->get_meta('Source');
        $meta_data = $order->get_meta_data();
        $meta_payments = array_filter($meta_data, [$this, 'get_meta_payment']);
        $has_date_paid = !is_null($order->get_date_paid());
        $total = 0;
        if ($source == 'in_store' || $source == 'In Store') {
            $total = array_reduce($meta_payments, [$this, 'reduce_payment_amount'], 0);
            if ($total > $order->get_total())
            {
                $total = $order->get_total();
            }

            $total_paid = wc_price($total, array( 'currency' => $order->get_currency()));
        } else {
            if ($has_date_paid) {
                $total_paid = wc_price(floatval($order->get_total()), array( 'currency' => $order->get_currency()));
                $total = floatval($order->get_total());
            } else {
                $total_paid = wc_price(0, array( 'currency' => $order->get_currency()));
            }
        }

        $remain = floatval($total) - floatval($order->get_total());
        $isShowRemainSection = $remain <= 0 ? 1 : 0;

        $script = "<div><script>let intervalId;intervalId=setInterval(function(){let t=document.getElementsByClassName(\"wc-order-totals\"),e;t&&t.length;for(let r=0;r<t.length;r++){let o=t[r];if(o.innerHTML.includes(\"". translate( 'Paid', 'woocommerce' ) ."\")){e=o;break}}if(!e){clearInterval(intervalId);return}let l=e.querySelector(\"td.total\");if(l){if(l.innerHTML='". $total_paid ."',1 === ". $isShowRemainSection ."){let d=document.createElement(\"table\");d.className=\"wc-order-totals\",d.style=\"border-top: 1px solid #999; margin-top:12px; padding-top:12px\",d.innerHTML=`<tbody><tr><td class=\"label\" style=\"color: #d93025\">Remain:</td><td width=\"1%\"></td><td class=\"total\" style=\"color: #d93025\">". wc_price(abs($remain), array( 'currency' => $order->get_currency())) ."</td></tr></tbody>`;let n=document.querySelector(\"div#woocommerce-order-items .wc-order-data-row.wc-order-totals-items\");n&&n.appendChild(d)}clearInterval(intervalId)}},100);</script></div>";
        echo $script;
	}

	/**
	 * Update suborder of MVX plugin.
	 */
	public function cpo_update_suborders( $order, $request, $creating ) {
        if ( ! class_exists( 'MVX_Admin' ) || $creating ) {
            return;
        }

        $suborder = wc_get_order( $order->get_id() + 1 );
        if ( ! $suborder ) {
            return;
        }

        $id = $order->get_id() + 1;
        $data  = apply_filters( 'woocommerce_api_edit_order_data', $order, $id, $this );

        $suborder->set_status( $order->get_status() );
        $order_meta = $suborder->get_meta();
        $suborder->save();

        foreach ( $order_meta as $meta_key => $meta_value ) {
			if ( is_string( $meta_key ) && ! is_protected_meta( $meta_key ) && is_scalar( $meta_value ) ) {
				update_post_meta( $id, $meta_key, $meta_value );
			}
		}

        wc_update_order( $order_args );
	}

    public function cpo_allow_guest_and_private_for_wcpdf( $value )
    {
        if ( !isset( $_REQUEST['action'] ) ) {
            return $value;
        }

        if ( !isset( $_REQUEST['document_type'] ) ) {
            return $value;
        }

        if ($_REQUEST['action'] == 'generate_wpo_wcpdf' && $_REQUEST['document_type'] == 'packing-slip') {
            return true;
        }

        return $value;
    }

    public function cpo_allow_guest_and_private_for_myparcel( $value )
    {
        if ( !isset( $_SERVER['action'] ) ) {
            return $value;
        }

        if ( !isset( $_SERVER['request'] ) ) {
            return $value;
        }

        if ( $_SERVER['action'] == 'wcmp_export' && $_SERVER['request'] == 'get_labels' ) {
			if ( ! $value ) {
	            return true;
			}
        }

        return $value;
    }

    /**
     * @param WP_REST_Request $data
     *
     * @return mixed
     */
    public function cpo_myparcel_export(WP_REST_Request $data)
    {
        $body = $data->get_body();
        $jsonBody = json_decode($body, true);
        $order_id = $jsonBody['order_id'];

        if ( ! $order_id ) {
            return json_decode('{}');
        }

        $wcmp_export = new WCMP_Export();
        $wcmp_export->exportByOrderId($order_id);

        return json_decode('{}');
    }

    /**
	 * Prepare refund response.
	 *
	 */
	public function cpo_prepare_refund_response( $response, $object, $request ) {
        $order_id = $request['order_id'];
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

        $line_items = [];
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $response->data['id'] );
        // Add line items
        foreach ( $refund->get_items() as $item_id => $item ) {
            $product = $item->get_product();

            // line item meta data
            $hide_prefix = ( isset( $filter['all_item_meta'] ) && $filter['all_item_meta'] === 'true' ) ? null : '_';
            $item_meta   = $item->get_formatted_meta_data( $hide_prefix );
            foreach ( $item_meta as $key => $values ) {
                $item_meta[ $key ]->label = $values->display_key;
                unset( $item_meta[ $key ]->display_key, $item_meta[ $key ]->display_value );
            }

            $refund_item_meta_data = $this->db->cpdb_clear()
                                              ->cpdb_select()
                                              ->cpdb_table( 'woocommerce_order_itemmeta' )
                                              ->cpdb_where( 'meta_key = %s', '_refunded_item_id' )
                                              ->cpdb_where( 'order_item_id = %d', $item_id )
                                              ->cpdb_first();
            $meta                  = [];
            if ( $refund_item_meta_data ) {
                $meta = [
                    'id'            => (int) $refund_item_meta_data->meta_id,
                    'key'           => $refund_item_meta_data->meta_key,
                    'value'         => $refund_item_meta_data->meta_value,
                    'display_key'   => $refund_item_meta_data->meta_key,
                    'display_value' => $refund_item_meta_data->meta_value
                ];
            }

            $itemDetail = $order->get_item((int) $item->get_meta( '_refunded_item_id' ));

            $line_items[] = [
                'id'               => $item_id,
                'subtotal'         => wc_format_decimal( $order->get_line_subtotal( $item ), 2 ),
                'subtotal_tax'     => wc_format_decimal( $item->get_subtotal_tax(), 2 ),
                'total'            => wc_format_decimal( $order->get_line_total( $item ), 2 ),
                'total_tax'        => wc_format_decimal( $order->get_line_tax( $item ), 2 ),
                'price'            => wc_format_decimal( $order->get_item_total( $item ), 2 ),
                'quantity'         => $item->get_quantity(),
                'tax_class'        => $item->get_tax_class(),
                'name'             => $item->get_name(),
                'product_id'       => $item->get_variation_id() ?: $item->get_product_id(),
                'sku'              => is_object( $product ) ? $product->get_sku() : null,
                'meta_data'        => [ $meta ],
                'refunded_item_id' => (int) $item->get_meta( '_refunded_item_id' ),
                'restock_quantity' => (int) $itemDetail->get_meta( '_restock_refunded_items' ),
            ];
        }

        $response->data['line_items'] = $line_items;

        return $response;
	}
}
