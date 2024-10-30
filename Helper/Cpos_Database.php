<?php
/**
 * Description: ConnectPOS Database Helper.
 * Author: ConnectPOS
 *
 * @package ConnectPOS\Helper
 */

namespace ConnectPOS\Helper;

use http\Exception\RuntimeException;
use mysqli_result;
use stdClass;
use wpdb;

/**
 * Class Cpos_Database
 * @package ConnectPOS\Helper
 */
class Cpos_Database {
    const QUERY = 'query';
    const COLUMNS = 'columns';
    const TABLE = 'table';
    const WHERE = 'where';
    const GROUP = 'group';
    const HAVING = 'having';
    const ORDER = 'order';
    const LIMIT = 'limit';
    const OFFSET = 'offset';

    const SQL_SELECT = 'SELECT';
    const SQL_FROM = 'FROM';
    const SQL_WHERE = 'WHERE';
    const SQL_DISTINCT = 'DISTINCT';
    const SQL_GROUP_BY = 'GROUP BY';
    const SQL_ORDER_BY = 'ORDER BY';
    const SQL_HAVING = 'HAVING';
    const SQL_AND = 'AND';
    const SQL_AS = 'AS';
    const SQL_OR = 'OR';
    const SQL_ON = 'ON';
    const SQL_ASC = 'ASC';
    const SQL_DESC = 'DESC';
    const SQL_LIMIT = 'LIMIT';
    const SQL_OFFSET = 'OFFSET';

    /**
     * @var wpdb
     */
    protected $db;

    /**
     * @var array
     */
    protected $parts = [];

    /**
     * Database constructor.
     *
     * @return void
     */
    public function __construct() {
        global $wpdb;

        $this->db = $wpdb;
    }

    /**
     * @return wpdb
     */
    public function cpdb_get_db() {
        return $this->db;
    }

    /**
     * @param string $columns
     *
     * @return $this
     */
    public function cpdb_select( $columns = '*' ) {
        $this->parts[ self::COLUMNS ] = $columns;
        $this->parts[ self::QUERY ]   = self::SQL_SELECT;

        return $this;
    }

    /**
     * @param array $data
     * @param null $format
     *
     * @return bool|int|mysqli_result|resource
     */
    public function cpdb_insert( $data, $format = null ) {
        return $this->db->insert($this->parts[ self::TABLE ], $data, $format);
    }

    /**
     * @param array $data
     * @param array $condition
     * @param null $data_format
     * @param null $condition_format
     *
     * @return bool|int|mysqli_result|resource
     */
    public function cpdb_update( $data, $condition, $data_format = null, $condition_format = null ) {
        if (empty($condition)) {
            throw new RuntimeException(__('Missing where clause in update query.'));
        }

        return $this->db->update($this->parts[ self::TABLE ], $data, $condition, $data_format, $condition_format);
    }

    /**
     * @param string $table_name
     *
     * @return $this
     */
    public function cpdb_table( $table_name ) {
        $prefix = $this->db->prefix;
        if ( strpos( $table_name, $prefix ) !== 0 ) {
            $table_name = $prefix . $table_name;
        }

        $this->parts[ self::TABLE ] = $table_name;

        return $this;
    }

    /**
     * @param string $condition
     * @param mixed $value
     * @param bool $type
     *
     * @return $this
     */
    public function cpdb_where( $condition, $value, $type = true ) {
        if ( ! isset( $this->parts[ self::WHERE ] ) ) {
            $this->parts[ self::WHERE ][] = sprintf( '(%s)', $this->db->prepare( $condition, $value ) );

            return $this;
        }

        $cond                         = $type ? self::SQL_AND : self::SQL_OR;
        $this->parts[ self::WHERE ][] = $this->db->prepare( sprintf( '%s (%s)', $cond, $condition ), $value );

        return $this;
    }

    /**
     * @param int $value
     *
     * @return $this
     */
    public function cpdb_limit( $value ) {
        $this->parts[ self::LIMIT ] = $value;

        return $this;
    }

    /**
     * @param int $value
     *
     * @return $this
     */
    public function cpdb_offset( $value ) {
        $this->parts[ self::OFFSET ] = $value;

        return $this;
    }

    /**
     * @param $field
     * @param $desc $asc
     *
     * @return $this
     */
    public function cpdb_order( $field, $desc = true ) {
        $dir = $desc ? self::SQL_DESC : self::SQL_ASC;

        $this->parts[ self::ORDER ][] = sprintf( '%s %s', $field, $desc );

        return $this;
    }

    /**
     * @return array|object|stdClass|void|null
     */
    public function cpdb_first() {
        return $this->db->get_row( $this->cpdb_build_query() );
    }

    /**
     * @return array|object|stdClass[]|null
     */
    public function cpdb_list() {
        return $this->db->get_results( $this->cpdb_build_query() );
    }

    /**
     * @return string
     */
    public function cpdb_to_string() {
        return $this->cpdb_build_query();
    }

    /**
     * @return $this
     */
    public function cpdb_clear() {
        $this->parts = [];
        $this->db->flush();

        return $this;
    }

    /**
     * @return string
     */
    private function cpdb_build_query() {
        $parts = $this->parts;

        $query = sprintf(
            '%s %s %s %s ',
            $parts[ self::QUERY ],
            $parts[ self::COLUMNS ],
            self::SQL_FROM,
            $parts[ self::TABLE ]
        );

        if ( isset( $parts[ self::WHERE ] ) && ! empty( $parts[ self::WHERE ] ) ) {
            $query .= sprintf( '%s %s ', self::SQL_WHERE, implode( ' ', $parts[ self::WHERE ] ) );
        }

        if ( isset( $parts[ self::ORDER ] ) && ! empty( $parts[ self::ORDER ] ) ) {
            $query .= sprintf( '%s %s ', self::SQL_ORDER_BY, implode( ', ', $parts[ self::ORDER ] ) );
        }

        if ( isset( $parts[ self::LIMIT ] ) && $parts[ self::LIMIT ] ) {
            $query .= sprintf( '%s %d ', self::SQL_LIMIT, $parts[ self::LIMIT ] );
        }

        if ( isset( $parts[ self::OFFSET ] ) && $parts[ self::OFFSET ] ) {
            $query .= sprintf( '%s %d ', self::SQL_OFFSET, $parts[ self::OFFSET ] );
        }

        return $query;
    }
}