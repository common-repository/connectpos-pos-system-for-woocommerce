<?php
/**
 * Description: ConnectPOS Integration with Polylang.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Integration
 */

namespace ConnectPOS\Integration;

use ConnectPOS\Helper\Cpos_Database;

/**
 * Class Cpos_Polylang
 * @package ConnectPOS\Integration
 */
class Cpos_Polylang {

    const LOCALE_META_KEY = 'locale';

    /**
     * @var CposDatabase
     */
    protected $db;

    /**
     * Polylang constructor.
     *
     * @param Cpos_Database $db
     *
     * @return void
     */
    public function __construct( Cpos_Database $db ) {
        $this->db = $db;
        add_action( 'rest_api_init', function () {
            register_rest_field(
                'product',
                'meta_data',
                [
                    'get_callback'    => array( $this, 'cppl_get_product_locale' ),
                    'schema'          => [ 'type' => 'array' ]
                ]
            );
        } );
    }

    /**
     * @param mixed $object
     *
     * @return mixed
     */
    public function cppl_get_product_locale( $object ) {
        if ( ! class_exists( 'Polylang_Woocommerce' ) ) {
            return isset($object['meta_data']) ? $object['meta_data'] : array();
        }

        $locale        = pll_default_language();
        if (is_array($object)) {
            $languageTerms =  wp_get_object_terms($object['id'], 'language');
        
            if (is_array($languageTerms) && count($languageTerms) > 0) {
                if (is_object( $languageTerms[0])) {
                    $locale =  $languageTerms[0]->slug;
                }
            }
        }

        $object['meta_data'][] = [ 'key' => self::LOCALE_META_KEY, 'value' => $locale ];

        return $object['meta_data'];
    }
}