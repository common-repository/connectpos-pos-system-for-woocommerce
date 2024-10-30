<?php
/**
 * Description: ConnectPOS Integration with WooCommerce Customer.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use WC_REST_Authentication;
use WP_REST_Request;
use WP_REST_SERVER;
use WC_Points_Rewards_Manager;

/**
 * Class Cpos_Customer
 * @package ConnectPOS\Integration
 */
class Cpos_Customer {

    const REWARD_POINT_META_KEY = 'wc_rp';

    /**
     * CposOrder constructor.
     *
     * @return void
     */
    public function __construct() {
        add_filter( 'woocommerce_rest_customer_query', array( $this, 'cpp_customer_date_time_filter' ), 10, 2 );
        add_action( 'user_register', array( $this, 'cpc_init_session' ), 10);
        add_action( 'woocommerce_new_customer', array( $this, 'cpc_close_session' ), 10);
		add_action( 'woocommerce_update_customer', array( $this, 'cpc_update_customer_data' ), 10, 2);
		add_action( 'rest_api_init', function () {
            register_rest_field(
                'customer',
                'meta_data',
                [
                    'get_callback' => array( $this, 'cpc_get_customer_meta_data' ),
                    'schema'       => [ 'type' => 'array' ]
                ]
            );
        }, 13);
        add_action('rest_api_init', function () {
            register_rest_route('wc/v3', 'roles', [
                'methods'             => WP_REST_SERVER::READABLE,
                'callback'            => array($this, 'cpc_get_editable_roles'),
                'permission_callback' => '__return_true',
            ]);
            new WC_REST_Authentication();
        });
	}

    /**
     * @param int $customer_id
     *
     * @return void
     */
    public function cpc_init_session(int $customer_id) {
        WC()->initialize_session();
    }

    /**
     * @param int $customer_id
     *
     * @return void
     */
    public function cpc_close_session(int $customer_id) {
        if ( is_null(WC()->session) ) {
            return;
        }
        WC()->session = null;
    }

    /**
     * @param WP_REST_Request $data
     *
     * @return void
     */
    public function cpc_get_editable_roles(WP_REST_Request $data) {
        $roles = [];
        foreach (wp_roles()->roles as $key => $value) {
            $roles[count($roles)] = [
                'code' => $key,
                'name' => $value['name'],
            ];
        }
        return $roles;
    }

    /**
     * @param array $args
     * @param WP_REST_Request $request
     *
     * @return array
     */
    public function cpp_customer_date_time_filter( array $args, WP_REST_Request $request ) {
        $modified_after  = $request->get_param( 'modified_after_gmt' );
        $modified_before = $request->get_param( 'modified_before_gmt' );
        $paged = $request->get_param( 'page' );
        $per_page = $request->get_param( 'limit' );
        if (!isset($per_page)) {
            $per_page = $request->get_param( 'per_page' );
        }
        $meta_key = $request->get_param( 'meta_key' );
        $meta_value = $request->get_param( 'meta_value' );

        $args['number'] = intval($per_page);
        $args['paged'] = intval($paged);
        $args['offset'] = 0;
		
        if ( isset($modified_after) ) {
            $args['meta_key'] = 'last_update';
            $args['meta_value'] = strval(strtotime( $modified_after ));
            $args['meta_compare'] = '>=';
			
            return $args;
        }

        if ( isset($modified_before) ) {
            $args['meta_key'] = 'last_update';
            $args['meta_value'] = strval(strtotime( $modified_before ));
            $args['meta_compare'] = '<=';
            return $args;
        }

        if ( $meta_key ) {
            $args['meta_key'] = $meta_key;
        }

        if ( $meta_value ) {
            $args['meta_value'] = $meta_value;
        }

        return $args;
    }
	
	
    /**
     * @param int $id
     * @param WC_Customer $data
     *
     * @return void
     */
    public function cpc_update_customer_data($id, $data = null) {
        if ( $data == null ) {
            return;
        }
        $customer_note = $data->get_meta("_cpos_customer_note");
        if ( !isset( $customer_note ) ) {
            return;
        }
        update_user_meta($id, 'description', $customer_note);
    }

     /**
     * @param mixed $object
     *
     * @return mixed
     * @throws Exception
     */
    public function cpc_get_customer_meta_data( $object ) {
        $description           = get_user_meta($object['id'], 'description');
        $object['meta_data'][] = [ 'key' => '_cpos_customer_note', 'value' => $description[0] ];

		if ( ! is_plugin_active('woocommerce-points-and-rewards/woocommerce-points-and-rewards.php') ) {
            return $object['meta_data'];
        }

		$point                 = WC_Points_Rewards_Manager::get_users_points( $object['id'] );
        $object['meta_data'][] = [ 'key' => self::REWARD_POINT_META_KEY, 'value' => $point ];

        return $object['meta_data'];
    }
}