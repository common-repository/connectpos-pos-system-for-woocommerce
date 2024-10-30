<?php
/**
 * Description: ConnectPOS Integration with WooCommerce Order Status Manager.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_SERVER;
use WC_REST_Authentication;
use ConnectPOS\Helper\Cpos_Database;

/**
 * Class Cpos_OrderStatus
 * @package ConnectPOS\Integration
 */
class Cpos_OrderStatus {
    const SETTING_GROUP = 'order_statuses';

    /**
     * @var CposDatabase
     */
    protected $db;

    /**
     * Cpos_OrderStatus constructor.
     *
     * @param Cpos_Database $db
     */
    public function __construct( Cpos_Database $db ) {
        if ( ! is_plugin_active( 'woocommerce-order-status-manager/woocommerce-order-status-manager.php' ) ) {
            return;
        }

        $this->db = $db;

        // list order status
        add_filter( 'woocommerce_settings-' . self::SETTING_GROUP, array( $this, 'cpos_register_order_status_setting' ) );

        add_action( 'rest_api_init', function () {
            // create
            register_rest_route( 'wc/v3', 'settings/' . self::SETTING_GROUP, [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'cpos_create' ),
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'name' => [ 'description' => __( 'Status name' ), 'required' => true, 'type' => 'string', ],
                        'slug' => [ 'description' => __( 'Status slug' ), 'required' => true, 'type' => 'string', ],
                    ]
                ],
            ] );

            // update
            register_rest_route( 'wc/v3', 'settings/' . self::SETTING_GROUP, [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'cpos_update' ),
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'name' => [ 'description' => __( 'Status name' ), 'required' => true, 'type' => 'string', ],
                        'slug' => [ 'description' => __( 'Status slug' ), 'required' => true, 'type' => 'string', ],
                    ]
                ],
            ] );

            // delete
            register_rest_route( 'wc/v3', 'settings/' . self::SETTING_GROUP . '/(?P<slug>\w[\w\s\-]*)', [
                'args' => [ 'slug' => [ 'description' => __( 'Status slug' ), 'type' => 'string', ], ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'cpos_delete' ),
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'slug' => [ 'description' => __( 'Status slug' ), 'required' => true, 'type' => 'string', ],
                    ]
                ],
            ] );
            new WC_REST_Authentication();
        } );
    }

    /**
     * Registers order statuses setting. list order status via WooCommerce setting group API.
     *
     * @param array $settings Existing registered settings.
     *
     * @return array
     */
    public function cpos_register_order_status_setting( $settings ) {
        $options = [];
        $posts   = $this->cpos_get_instance()->get_order_status_posts();

        foreach ( $posts as $post ) {
            $options[ $post->post_name ] = $this->cpos_prepare_status_to_response( $post );
        }

        $settings[] = [
            'id'          => 'woocommerce_order_statues',
            'option_key'  => 'woocommerce_order_statues',
            'label'       => 'Order Statuses',
            'description' => 'WooCommerce Order Status Manager',
            'type'        => 'select',
            'options'     => $options,
        ];

        return $settings;
    }

    /**
     * Handle create new order status API request.
     *
     * @param WP_REST_Request $request
     *
     * @return array|WP_Error
     */
    public function cpos_create( WP_REST_Request $request ) {
        $data   = $request->get_json_params();
        $postId = $this->cpos_get_instance()->create_post_for_status( $data['slug'], $data['name'] );

        if ( $postId === 0 ) {
            return new WP_Error( 'create_post_failed', __( 'Create new order status failed.' ), [ 'status' => 400 ] );
        }

        if ( isset( $data['description'] ) && $data['description'] !== '' ) {
            wp_update_post( [ 'ID' => $postId, 'post_excerpt' => $data['description'], ] );
        }

        if ( isset( $data['color'] ) && $data['color'] !== '' ) {
            update_post_meta( $postId, '_color', $data['color'] );
        }

        return $this->cpos_prepare_status_to_response( get_post( $postId ) );
    }

    /**
     * Handle update existing order status API request.
     *
     * @param WP_REST_Request $request
     *
     * @return array|WP_Error
     */
    public function cpos_update( WP_REST_Request $request ) {
        $data   = $request->get_json_params();

        $result = $this->cpos_get_status_by_slug( $data['slug'] );
        if ( ! $result ) {
            return new WP_Error( 'not_found', __( 'This order status does not exist.' ), [ 'status' => 404 ] );
        }

        $postId = (int) $result->ID;
        if ( isset( $data['description'] ) && $data['description'] !== '' ) {
            wp_update_post( [ 'ID' => $postId, 'post_excerpt' => $data['description'], ] );
        }

        if ( isset( $data['name'] ) && $data['name'] !== '' ) {
            wp_update_post( [ 'ID' => $postId, 'post_title' => $data['name'], ] );
        }

        if ( isset( $data['color'] ) && $data['color'] !== '' ) {
            update_post_meta( $postId, '_color', $data['color'] );
        }

        return $this->cpos_prepare_status_to_response( get_post( $postId ) );
    }

    /**
     * Handle delete existing order status API request.
     *
     * @param WP_REST_Request $request
     *
     * @return array|WP_Error
     */
    public function cpos_delete( WP_REST_Request $request ) {
        $slug = $request->get_param( 'slug' );

        $instance = $this->cpos_get_instance();
        if ( $instance->is_core_status( $slug ) ) {
            return new WP_Error( 'create_post_failed', __( 'Core status cannot be deleted.' ), [ 'status' => 409 ] );
        }

        $result = $this->cpos_get_status_by_slug( $slug );
        if ( ! $result ) {
            return new WP_Error( 'not_found', __( 'This order status does not exist.' ), [ 'status' => 404 ] );
        }

        wp_delete_post( (int) $result->ID );

        return [];
    }


    /**
     * Get order status manager instance
     *
     * @return \WC_Order_Status_Manager_Order_Statuses
     */
    protected function cpos_get_instance() {
        return wc_order_status_manager()->get_order_statuses_instance();
    }

    /**
     * Prepare order status post to response. Mostly used for API response
     *
     * @param WP_Post $post
     *
     * @return array
     */
    protected function cpos_prepare_status_to_response( WP_Post $post ) {
        return [
            'id'             => (int) $post->ID,
            'name'           => (string) $post->post_title,
            'slug'           => (string) $post->post_name,
            'description'    => (string) $post->post_excerpt,
            'color'          => (string) get_post_meta( $post->ID, '_color', true ),
            'is_core_status' => (bool) $this->cpos_get_instance()->is_core_status( $post->post_name ),
        ];
    }

    /**
     * Get order status post by status 'slug'.
     *
     * @param string $slug
     *
     * @return object|null
     */
    protected function cpos_get_status_by_slug( $slug ) {
        $result = $this->db->cpdb_clear()->cpdb_select()
                           ->cpdb_table( $this->db->cpdb_get_db()->posts )
                           ->cpdb_where( 'post_type = %s', 'wc_order_status' )
                           ->cpdb_where( 'post_status = %s', 'publish' )
                           ->cpdb_where( 'post_name = %s', $slug )
                           ->cpdb_first();

        if ( ! is_object( $result ) || (int) $result->ID === 0 ) {
            return null;
        }

        return $result;
    }
}