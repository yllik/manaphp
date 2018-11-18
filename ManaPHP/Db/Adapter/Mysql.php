<?php
namespace ManaPHP\Db\Adapter;

use ManaPHP\Db;
use ManaPHP\Exception\DsnFormatException;
use ManaPHP\Exception\InvalidArgumentException;
use ManaPHP\Db\AssignmentInterface;

/**
 * Class ManaPHP\Db\Adapter\Mysql
 *
 * @package db\adapter
 */
class Mysql extends Db
{
    /**
     * @var string
     */
    protected $_charset = 'UTF8';

    /**
     * \ManaPHP\Db\Adapter constructor
     *
     * @param string $uri
     */
    public function __construct($uri = 'mysql://root@localhost/test?charset=utf8')
    {
        $parts = parse_url($uri);

        if ($parts['scheme'] !== 'mysql') {
            throw new DsnFormatException(['`:url` is invalid, `:scheme` scheme is not recognized', 'url' => $uri, 'scheme' => $parts['scheme']]);
        }

        $this->_username = isset($parts['user']) ? $parts['user'] : 'root';
        $this->_password = isset($parts['pass']) ? $parts['pass'] : '';

        $dsn = [];

        if (isset($parts['host'])) {
            $dsn['host'] = $parts['host'];
        }

        if (isset($parts['port'])) {
            $dsn['port'] = $parts['port'];
        }

        if (isset($parts['path'])) {
            $db = trim($parts['path'], '/');
            if ($db !== '') {
                $dsn['dbname'] = $db;
            }
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $parts2);
        } else {
            $parts2 = [];
        }

        if (isset($parts2['charset'])) {
            $this->_charset = $parts2['charset'];
        }

        if (isset($parts2['persistent'])) {
            $this->_options[\PDO::ATTR_PERSISTENT] = $parts2['persistent'] === '1';
        }

        $this->_options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '{$this->_charset}'";

        $dsn_parts = [];
        foreach ($dsn as $k => $v) {
            $dsn_parts[] = $k . '=' . $v;
        }
        $this->_dsn = 'mysql:' . implode(';', $dsn_parts);

        parent::__construct();
    }

    /**
     * @param string $source
     *
     * @return array
     * @throws \ManaPHP\Db\Exception
     */
    public function getMetadata($source)
    {
        $fields = $this->fetchAll('DESCRIBE ' . $this->_escapeIdentifier($source), [], \PDO::FETCH_NUM);

        $attributes = [];
        $primaryKeys = [];
        $autoIncrementAttribute = null;
        $intTypes = [];

        foreach ($fields as $field) {
            $fieldName = $field[0];

            $attributes[] = $fieldName;

            if ($field[3] === 'PRI') {
                $primaryKeys[] = $fieldName;
            }

            if ($field[5] === 'auto_increment') {
                $autoIncrementAttribute = $fieldName;
            }

            $type = $field[1];
            if (strpos($type, 'int') !== false) {
                $intTypes[] = $fieldName;
            }
        }

        $r = [
            self::METADATA_ATTRIBUTES => $attributes,
            self::METADATA_PRIMARY_KEY => $primaryKeys,
            self::METADATA_AUTO_INCREMENT_KEY => $autoIncrementAttribute,
            self::METADATA_INT_TYPE_ATTRIBUTES => $intTypes,
        ];

        return $r;
    }

    /**
     * @param string $source
     *
     * @return static
     * @throws \ManaPHP\Db\Exception
     */
    public function truncate($source)
    {
        $this->execute('TRUNCATE TABLE ' . $this->_escapeIdentifier($source));

        return $this;
    }

    /**
     * @param string $source
     *
     * @return static
     * @throws \ManaPHP\Db\Exception
     */
    public function drop($source)
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->_escapeIdentifier($source));

        return $this;
    }

    /**
     * @param string $schema
     *
     * @return array
     * @throws \ManaPHP\Db\Exception
     */
    public function getTables($schema = null)
    {
        if ($schema) {
            $sql = 'SHOW FULL TABLES FROM `' . $this->_escapeIdentifier($schema) . '` WHERE Table_Type != "VIEW"';
        } else {
            $sql = 'SHOW FULL TABLES WHERE Table_Type != "VIEW"';
        }

        $tables = [];
        foreach ($this->fetchAll($sql, [], \PDO::FETCH_NUM) as $row) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /**
     * @param string $source
     *
     * @return bool
     * @throws \ManaPHP\Db\Exception
     */
    public function tableExists($source)
    {
        $parts = explode('.', str_replace('[]`', '', $source));

        if (count($parts) === 2) {
            $sql = "SELECT IF(COUNT(*) > 0, 1, 0) FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_NAME`= '$parts[0]' AND `TABLE_SCHEMA` = '$parts[1]'";
        } else {
            $sql = "SELECT IF(COUNT(*) > 0, 1, 0) FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_NAME` = '$parts[0]' AND `TABLE_SCHEMA` = DATABASE()";
        }

        $r = $this->fetchOne($sql, [], \PDO::FETCH_NUM);

        return $r[0] === '1';
    }

    public function buildSql($params)
    {
        $sql = '';

        if (isset($params['fields'])) {
            $sql .= 'SELECT ';

            if (isset($params['distinct'])) {
                $sql .= 'DISTINCT ';
            }

            $sql .= $params['fields'];
        }

        if (isset($params['from'])) {
            $sql .= ' FROM ' . $params['from'];
        }

        if (isset($params['join'])) {
            $sql .= $params['join'];
        }

        if (isset($params['where'])) {
            $sql .= ' WHERE ' . $params['where'];
        }

        if (isset($params['group'])) {
            $sql .= ' GROUP BY ' . $params['group'];
        }

        if (isset($params['having'])) {
            $sql .= ' HAVING ' . $params['having'];
        }

        if (isset($params['order'])) {
            $sql .= ' ORDER BY ' . $params['order'];
        }

        if (isset($params['limit'])) {
            $sql .= ' LIMIT ' . $params['limit'];
        }

        if (isset($params['offset'])) {
            $sql .= ' OFFSET ' . $params['offset'];
        }

        if (isset($params['forUpdate'])) {
            $sql .= 'FOR UPDATE';
        }

        return $sql;
    }

    /**
     * @param string $sql
     *
     * @return string
     */
    public function replaceQuoteCharacters($sql)
    {
        return preg_replace('#\[([a-z_][a-z0-9_]*)\]#i', '`\\1`', $sql);
    }

    /**
     * @param string  $table
     * @param array[] $records
     * @param string  $primaryKey
     * @param bool    $skipIfExists
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    public function bulkInsert($table, $records, $primaryKey = null, $skipIfExists = false)
    {
        if (!$records) {
            throw new InvalidArgumentException(['Unable to insert into :table table without data', 'table' => $table]);
        }

        $fields = array_keys($records[0]);
        $insertedFields = '[' . implode('],[', $fields) . ']';

        $pdo = $this->_getPdo();

        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($record as $field => $value) {
                $row[] = is_string($value) ? $pdo->quote($value) : $value;
            }

            $rows[] = '(' . implode(',', $row) . ')';
        }

        $sql = 'INSERT' . ($skipIfExists ? ' IGNORE' : '') . ' INTO ' . $this->_escapeIdentifier($table) . " ($insertedFields) VALUES " . implode(', ', $rows);

        $count = $this->execute($sql, []);
        $this->logger->debug(compact('count', 'table', 'records', 'skipIfExists'), 'db.bulk.insert');

        return $count;
    }

    /**
     * Updates data on a table using custom SQL syntax
     *
     * @param string $table
     * @param array  $insertFieldValues
     * @param array  $updateFieldValues
     * @param string $primaryKey
     *
     * @return    int
     */
    public function upsert($table, $insertFieldValues, $updateFieldValues = [], $primaryKey = null)
    {
        if (!$primaryKey) {
            $primaryKey = key($insertFieldValues);
        }

        if (!$updateFieldValues) {
            $updateFieldValues = $insertFieldValues;
        }

        $fields = array_keys($insertFieldValues);

        $bind = $insertFieldValues;
        $updates = [];
        foreach ($updateFieldValues as $k => $v) {
            $field = is_int($k) ? $v : $k;
            if ($primaryKey === $field) {
                continue;
            }

            if (is_int($k)) {
                $updates[] = "[$field]=:{$field}_dku";
                $bind["{$field}_dku"] = $insertFieldValues[$field];
            } elseif ($v instanceof AssignmentInterface) {
                $v->setFieldName($k);
                $updates[] = $v->getSql();
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $bind = array_merge($bind, $v->getBind());
            } else {
                $updates[] = $v;
            }
        }

        $insertFieldsSql = '[' . implode('],[', $fields) . ']';
        $insertValuesSql = ':' . implode(',:', $fields);

        $updateFieldsSql = implode(',', $updates);

        /** @noinspection SqlDialectInspection */
        /** @noinspection SqlNoDataSourceInspection */
        $sql = "INSERT INTO {$this->_escapeIdentifier($table)}($insertFieldsSql) VALUES($insertValuesSql) ON DUPLICATE KEY UPDATE $updateFieldsSql";

        $count = $this->execute($sql, $bind);
        $this->logger->info(compact('count', 'table', 'insertFieldValues', 'updateFieldValues', 'primaryKey'), 'db.upsert');
        return $count;
    }
}