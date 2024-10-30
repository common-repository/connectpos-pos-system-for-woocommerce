<?php
/**
 * Description: ConnectPOS Integration with WooCommerce Points and Rewards.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use ConnectPOS\Helper\Cpos_Database;
use stdClass;
use WC_REST_Authentication;
use Exception;
use WC_Points_Rewards_Manager;
use WC_Customer;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class Cpos_RewardPoint
 * @package ConnectPOS\Integration
 */
class Cpos_RewardPoint {

    const REWARD_POINT_META_KEY = 'wc_rp';

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

        if ( ! is_plugin_active('woocommerce-points-and-rewards/woocommerce-points-and-rewards.php') ) {
            return;
        }

        add_action( 'rest_api_init', function () {
            register_rest_route( 'wc/v3', 'reward-points-options', [
                'methods'             => WP_REST_SERVER::READABLE,
                'callback'            => array( $this, 'cprp_get_reward_points' ),
                'permission_callback' => '__return_true',
            ] );
            new WC_REST_Authentication();
        } );

        add_action( 'rest_api_init', function () {
            register_rest_field(
                'customer',
                'meta_data',
                [
                    'get_callback' => array( $this, 'cprp_get_user_reward_points' ),
                    'schema'       => [ 'type' => 'array' ]
                ]
            );
        });

        add_action('rest_api_init', function () {
            register_rest_route('wc/v3/reward-points', 'adjust', [
                'methods'             => WP_REST_SERVER::CREATABLE,
                'callback'            => array($this, 'cprp_adjust_points'),
                'permission_callback' => '__return_true',
            ]);
            new WC_REST_Authentication();
        });
    }

    /**
     * @return array|object|stdClass[]|null
     */
    public function cprp_get_reward_points() {
        return $this->db->cpdb_clear()
                        ->cpdb_select()
                        ->cpdb_table( $this->db->cpdb_get_db()->options )
                        ->cpdb_where( 'option_name LIKE \'%s\'', 'wc_points_rewards%' )
                        ->cpdb_list();
    }

    /**
     * @param mixed $object
     *
     * @return mixed
     * @throws Exception
     */
    public function cprp_get_user_reward_points( $object ) {
        $point                 = WC_Points_Rewards_Manager::get_users_points( $object['id'] );
        $object['meta_data'][] = [ 'key' => self::REWARD_POINT_META_KEY, 'value' => $point ];

        return $object['meta_data'];
    }

    /**
     * @param WP_REST_Request $data
     *
     * @return mixed
     */
    public function cprp_adjust_points(WP_REST_Request $data)
    {
        $body = $data->get_body();
        $jsonBody = json_decode($body, true);
        $customer_id = $jsonBody['customer_id'];
        $balance = $jsonBody['balance'];
        $event_type = $jsonBody['event_type'];

        if (!$customer_id) {
            return json_decode('{ "message": "Cannot found customer" }');
        }

        if (!$balance) {
            return json_decode('{ "message": "Cannot adjust without \'balance\' field" }');
        }

        WC_Points_Rewards_Manager::set_points_balance($customer_id, $balance, $event_type);

        $customer = new WC_Customer($customer_id);
        do_action("woocommerce_api_update_customer_data", $customer->get_id(), [], $customer);
        $customer->save();

        return json_decode('{}');
    }
}