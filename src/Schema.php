<?php

/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace edgardmessias\db\informix;

use Exception;
use PDO;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ConstraintFinderInterface;
use yii\db\ConstraintFinderTrait;
use yii\db\Expression;
use yii\db\TableSchema;

/**
 * @author Edgard Messias <edgardmessias@gmail.com>
 * @since 1.0
 */
class Schema extends \yii\db\Schema implements ConstraintFinderInterface
{
    use ConstraintFinderTrait;

    private $_tabids = [];

    /**
     * @var array mapping from physical column types (keys) to abstract column types (values)
     */
    public $typeMap = [
        'bigint'                  => self::TYPE_BIGINT,
        'bigserial'               => self::TYPE_BIGINT,
        'binary18'                => self::TYPE_BINARY,
        'binaryvar'               => self::TYPE_BINARY,
        'blob'                    => self::TYPE_BINARY,
        'boolean'                 => self::TYPE_BOOLEAN,
        'byte'                    => self::TYPE_BINARY,
        'char'                    => self::TYPE_CHAR,
        'character varying'       => self::TYPE_CHAR,
        'character'               => self::TYPE_CHAR,
        'clob'                    => self::TYPE_TEXT,
        'date'                    => self::TYPE_DATE,
        'datetime hour to second' => self::TYPE_TIME,
        'datetime year to day'    => self::TYPE_DATE,
        'datetime year to second' => self::TYPE_DATETIME,
        'dec'                     => self::TYPE_DECIMAL,
        'decimal'                 => self::TYPE_DECIMAL,
        'double precision'        => self::TYPE_DOUBLE,
        'float'                   => self::TYPE_DOUBLE,
        'int'                     => self::TYPE_INTEGER,
        'int8'                    => self::TYPE_BIGINT,
        'integer'                 => self::TYPE_INTEGER,
        'json'                    => self::TYPE_JSON,
        'lvarchar'                => self::TYPE_STRING,
        'money'                   => self::TYPE_MONEY,
        'nchar'                   => self::TYPE_STRING,
        'numeric'                 => self::TYPE_DECIMAL,
        'nvarchar'                => self::TYPE_STRING,
        'pk'                      => self::TYPE_PK,
        'real'                    => self::TYPE_FLOAT,
        'serial'                  => self::TYPE_INTEGER,
        'serial8'                 => self::TYPE_BIGINT,
        'smallfloat'              => self::TYPE_FLOAT,
        'smallint'                => self::TYPE_SMALLINT,
        'text'                    => self::TYPE_TEXT,
        'varchar'                 => self::TYPE_STRING,
    ];

    /**
     * Determines the PDO type for the given PHP data value.
     * @param mixed $data the data whose PDO type is to be determined
     * @return integer the PDO type
     * @see http://www.php.net/manual/en/pdo.constants.php
     */
    public function getPdoType($data)
    {
        static $typeMap = [
            // php type => PDO type
            'boolean' => PDO::PARAM_BOOL,
            'integer' => PDO::PARAM_INT,
            'string' => PDO::PARAM_STR,
            'resource' => PDO::PARAM_LOB,
            'NULL' => PDO::PARAM_STR, // [Informix][Informix ODBC Driver]Wrong number of parameters if set NULL
        ];
        $type = gettype($data);

        return isset($typeMap[$type]) ? $typeMap[$type] : PDO::PARAM_STR;
    }

    /**
     * @return ColumnSchema
     * @throws InvalidConfigException
     */
    protected function createColumnSchema()
    {
        return Yii::createObject(ColumnSchema::class);
    }

    public function createColumnSchemaBuilder($type, $length = null)
    {
        return new ColumnSchemaBuilder($type, $length);
    }


    /**
     * Resolves the table name and schema name (if any).
     * @param TableSchema $table the table metadata object
     * @param string $name the table name
     */
    protected function resolveTableNames($table, $name)
    {
        $parts = explode('.', str_replace('"', '', $name));
        if (isset($parts[1])) {
            $table->schemaName = $parts[0];
            $table->name = $parts[1];
        } else {
            $table->schemaName = $this->defaultSchema;
            $table->name = $name;
        }
        $table->fullName = $table->schemaName !== $this->defaultSchema ? $table->schemaName . '.' . $table->name : $table->name;
    }

    /**
     * Quotes a simple table name for use in a query.
     * A simple table name should contain the table name only without any schema prefix.
     * If the table name is already quoted, this method will do nothing.
     * @param string $name table name
     * @return string the properly quoted table name
     */
    public function quoteSimpleTableName($name)
    {
        if ($this->db->isDelimident()) {
            return strpos($name, '"') !== false ? $name : '"' . $name . '"';
        }
        return trim($name, "\"'`");
    }

    /**
     * Quotes a simple column name for use in a query.
     * A simple column name should contain the column name only without any prefix.
     * If the column name is already quoted or is the asterisk character '*', this method will do nothing.
     * @param string $name column name
     * @return string the properly quoted column name
     */
    public function quoteSimpleColumnName($name)
    {
        if ($this->db->isDelimident()) {
            return strpos($name, '"') !== false || $name === '*' ? $name : '"' . $name . '"';
        }
        return trim($name, "\"'`");
    }

    /**
     * Returns all unique indexes for the given table.
     *
     * Each array element is of the following structure:
     *
     * ```php
     * [
     *  'IndexName1' => ['col1' [, ...]],
     *  'IndexName2' => ['col2' [, ...]],
     * ]
     * ```
     *
     * @param TableSchema $table the table metadata
     * @return array all unique indexes for the given table.
     */
    public function findUniqueIndexes($table)
    {
        return [];
    }

    /**
     * Loads the metadata for the specified table.
     * @param string $name table name
     * @return TableSchema|null driver dependent table metadata. Null if the table does not exist.
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     */
    protected function loadTableSchema($name)
    {
        $table = new TableSchema();
        $this->resolveTableNames($table, $name);
        if (!$this->findColumns($table)) {
            return null;
        }
        $this->findConstraints($table);
        return $table;
    }

    /**
     * Collects the table column metadata.
     *
     * @param TableSchema $table the table metadata
     * @return boolean whether the table exists in the database
     * @throws InvalidConfigException
     */
    protected function findColumns($table)
    {
        $sql = <<<SQL
SELECT syscolumns.colname,
       syscolumns.colmin,
       syscolumns.colmax,
       syscolumns.coltype,
       syscolumns.extended_id,
       NOT(coltype>255) AS allownull,
       syscolumns.collength,
       sysdefaults.type AS deftype,
       sysdefaults.default AS defvalue
FROM systables
  INNER JOIN syscolumns ON syscolumns.tabid = systables.tabid
  LEFT JOIN sysdefaults ON sysdefaults.tabid = syscolumns.tabid AND sysdefaults.colno = syscolumns.colno
WHERE systables.tabid >= 100
AND   systables.tabname = :tableName
ORDER BY syscolumns.colno
SQL;

        try {
            $columns = $this->db->createCommand($sql, [
                        ':tableName' => $table->name,
                    ])->queryAll();
        } catch (Exception $e) {
            return false;
        }
        if (empty($columns)) {
            return false;
        }

        $columnsTypes = [
            0  => 'CHAR',
            1  => 'SMALLINT',
            2  => 'INTEGER',
            3  => 'FLOAT',
            4  => 'SMALLFLOAT',
            5  => 'DECIMAL',
            6  => 'SERIAL',
            7  => 'DATE',
            8  => 'MONEY',
            9  => 'NULL',
            10 => 'DATETIME',
            11 => 'BYTE',
            12 => 'TEXT',
            13 => 'VARCHAR',
            14 => 'INTERVAL',
            15 => 'NCHAR',
            16 => 'NVARCHAR',
            17 => 'INT8',
            18 => 'SERIAL8',
            19 => 'SET',
            20 => 'MULTISET',
            21 => 'LIST',
            22 => 'ROW',
            23 => 'COLLECTION',
            24 => 'ROWREF',
            40 => 'VARIABLELENGTH',
            41 => 'FIXEDLENGTH',
            42 => 'REFSER8',
            52 => 'BIGINT',
            53 => 'BIGINT',
        ];
        foreach ($columns as $column) {
            if ($this->db->slavePdo->getAttribute(PDO::ATTR_CASE) === PDO::CASE_UPPER) {
                $column = array_change_key_case($column, CASE_LOWER);
            }
            $coltypebase = (int) $column['coltype'];
            $coltypereal = $coltypebase % 256;
            if (array_key_exists($coltypereal, $columnsTypes)) {
                $column['type'] = $columnsTypes[$coltypereal];
                $extended_id = (int) $column['extended_id'];
                switch ($coltypereal) {
                    case 0://CHAR
                    case 13://VARCHAR
                    case 15://NCHAR
                    case 16://NVARCHAR
                        $column['colprecision'] = $column['collength'];
                        break;
                    case 5:
                    case 8:
                        $column['colprecision'] = floor($column['collength'] / 256);
                        $column['colscale'] = $column['collength'] % 256;
                        $column['collength'] = $column['colprecision'] . ',' . $column['colscale'];
                        break;
                    case 14:
                    case 10:
                        $datetimeLength = '';
                        $datetimeTypes = [
                            0  => 'YEAR',
                            2  => 'MONTH',
                            4  => 'DAY',
                            6  => 'HOUR',
                            8  => 'MINUTE',
                            10 => 'SECOND',
                            11 => 'FRACTION',
                            12 => 'FRACTION',
                            13 => 'FRACTION',
                            14 => 'FRACTION',
                            15 => 'FRACTION',
                        ];
                        $largestQualifier = floor(($column['collength'] % 256) / 16);
                        $smallestQualifier = $column['collength'] % 16;
                        //Largest Qualifier
                        $datetimeLength .= (isset($datetimeTypes[$largestQualifier])) ? $datetimeTypes[$largestQualifier] : 'UNKNOWN';
                        if ($coltypereal === 14) {
                            //INTERVAL
                            $datetimeLength .= '(' . (floor($column['collength'] / 256) + floor(($column['collength'] % 256) / 16) - ($column['collength'] % 16) ) . ')';
                        } elseif (in_array($largestQualifier, [11, 12, 13, 14, 15], true)) {
                            $datetimeLength .= '(' . ($largestQualifier - 10) . ')';
                        }
                        $datetimeLength .= ' TO ';
                        //Smallest Qualifier
                        $datetimeLength .= (isset($datetimeTypes[$smallestQualifier])) ? $datetimeTypes[$smallestQualifier] : 'UNKNOWN';
                        if (in_array($largestQualifier, [11, 12, 13, 14, 15], true)) {
                            $datetimeLength .= '(' . ($largestQualifier - 10) . ')';
                        }
                        $column['collength'] = $datetimeLength;
                        break;
                    case 40:
                        switch ($extended_id) {
                            case 1:
                                $column['type'] = 'LVARCHAR';
                                break;
                            case 25:
                                $column['type'] = 'JSON';
                                break;
                            default:
                                $column['type'] = 'UDTVAR';
                                break;
                        }
                        break;
                    case 41:
                        switch ($extended_id) {
                            case 5:
                                $column['type'] = 'BOOLEAN';
                                break;
                            case 10:
                                $column['type'] = 'BLOB';
                                break;
                            case 11:
                                $column['type'] = 'CLOB';
                                break;
                            default :
                                $column['type'] = 'UDTFIXED';
                                break;
                        }
                        break;
                }
            } else {
                $column['type'] = 'UNKNOWN';
            }
            //http://publib.boulder.ibm.com/infocenter/idshelp/v10/index.jsp?topic=/com.ibm.sqlr.doc/sqlrmst48.htm
            switch ($column['deftype']) {
                case 'C':
                    $column['defvalue'] = new Expression('CURRENT');
                    break;
                case 'N':
                    $column['defvalue'] = new Expression('NULL');
                    break;
                case 'S':
                    $column['defvalue'] = new Expression('DBSERVERNAME');
                    break;
                case 'T':
                    $column['defvalue'] = new Expression('TODAY');
                    break;
                case 'U':
                    $column['defvalue'] = new Expression('USER');
                    break;
                case 'L':
                    //CHAR, NCHAR, VARCHAR, NVARCHAR, LVARCHAR, VARIABLELENGTH, FIXEDLENGTH
                    if (in_array($coltypereal, [0, 15, 16, 13, 40, 41], true)) {
                        $explod = explode(chr(0), $column['defvalue']);
                        $column['defvalue'] = isset($explod[0]) ? $explod[0] : '';
                    } else {
                        $explod = explode(' ', $column['defvalue'], 2);
                        $column['defvalue'] = isset($explod[1]) ? rtrim($explod[1]) : '';
                        if (in_array($coltypereal, [3, 5, 8], true)) {
                            $column['defvalue'] = (string) (float) $column['defvalue'];
                        }
                    }
                    //Literal value
                    break;
            }
            $c = $this->createColumn($column);
            $table->columns[$c->name] = $c;
        }
        return true;
    }

    /**
     * Creates a table column.
     *
     * @param array $column column metadata
     * @return \yii\db\ColumnSchema normalized column metadata
     * @throws InvalidConfigException
     */
    protected function createColumn($column)
    {
        $c = $this->createColumnSchema();
        $c->name = $column['colname'];
        $c->allowNull = (boolean) $column['allownull'];
        $c->isPrimaryKey = false;

        $type = strtolower($column['type']);
        $c->autoIncrement = strpos($type, 'serial') !== false;
        $c->dbType = $type;

        if (isset($this->typeMap[$type])) {
            $c->type = $this->typeMap[$type];
        } else {
            $c->type = self::TYPE_STRING;
        }

        if (preg_match('/(char|numeric|decimal|money)/i', $c->dbType)) {
            $c->dbType .= '(' . $column['collength'] . ')';
            $c->size = (int) $column['collength'];
            $c->precision = isset($column['colprecision']) ? (int) $column['colprecision'] : null;
            $c->scale = isset($column['colscale']) ? (int) $column['colscale'] : null;
        } elseif (preg_match('/(datetime|interval)/i', $c->dbType)) {
            $c->dbType .= ' ' . strtolower($column['collength']);
            if (isset($this->typeMap[$c->dbType])) {
                $c->type = $this->typeMap[$c->dbType];
            }
        }

        $c->phpType = $this->getColumnPhpType($c);

        $c->defaultValue = $c->phpTypecast($column['defvalue']);

        return $c;
    }

    /**
     * @throws \yii\db\Exception
     */
    protected function getColumnsNumber($tabid)
    {
        if (isset($this->_tabids[$tabid])) {
            return $this->_tabids[$tabid];
        }
        $qry = "SELECT colno, TRIM(colname) as colname FROM syscolumns where tabid = :tabid ORDER BY colno ";
        $command = $this->db->createCommand($qry, [':tabid' => $tabid]);

        $columns = [];
        foreach ($command->queryAll() as $row) {
            if ($this->db->slavePdo->getAttribute(PDO::ATTR_CASE) === PDO::CASE_UPPER) {
                $row = array_change_key_case($row, CASE_LOWER);
            }
            $columns[$row['colno']] = $row['colname'];
        }
        $this->_tabids[$tabid] = $columns;
        return $columns;
    }

    /**
     * Collects the primary and foreign key column details for the given table.
     * @param TableSchema $table the table metadata
     * @throws \yii\db\Exception
     */
    protected function findConstraints($table)
    {
        $sql = <<<EOD
SELECT sysconstraints.constrtype, sysconstraints.idxname
FROM systables
  INNER JOIN sysconstraints ON sysconstraints.tabid = systables.tabid
WHERE systables.tabname = :table;
EOD;
        $command = $this->db->createCommand($sql, [':table' => $table->name]);

        foreach ($command->queryAll() as $row) {
            if ($this->db->slavePdo->getAttribute(PDO::ATTR_CASE) === PDO::CASE_UPPER) {
                $row = array_change_key_case($row, CASE_LOWER);
            }
            if ($row['constrtype'] === 'P') { // primary key
                $this->findPrimaryKey($table, $row['idxname']);
            } elseif ($row['constrtype'] === 'R') { // foreign key
                $this->findForeignKey($table, $row['idxname']);
            }
        }
    }

    /**
     * Collects primary key information.
     * @param TableSchema $table the table metadata
     * @param string $indice Informix primary key index name
     * @throws \yii\db\Exception
     */
    protected function findPrimaryKey($table, $indice)
    {
        $sql = <<<EOD
SELECT tabid,
       part1,
       part2,
       part3,
       part4,
       part5,
       part6,
       part7,
       part8,
       part9,
       part10,
       part11,
       part12,
       part13,
       part14,
       part15,
       part16
FROM sysindexes
WHERE idxname = :indice;
EOD;

        $command = $this->db->createCommand($sql, [':indice' => $indice]);
        foreach ($command->queryAll() as $row) {
            if ($this->db->slavePdo->getAttribute(PDO::ATTR_CASE) === PDO::CASE_UPPER) {
                $row = array_change_key_case($row, CASE_LOWER);
            }

            $columns = $this->getColumnsNumber($row['tabid']);
            for ($x = 1; $x < 16; $x++) {
                $colno = (isset($row["part$x"])) ? abs($row["part$x"]) : 0;
                if ($colno === 0) {
                    continue;
                }
                $colname = $columns[$colno];
                if (isset($table->columns[$colname])) {
                    $table->columns[$colname]->isPrimaryKey = true;
                    $table->primaryKey[] = $colname;
                }
            }
        }
        foreach ($table->columns as $c) {
            if ($c->autoIncrement && $c->isPrimaryKey) {
                $table->sequenceName = $c->name;
                break;
            }
        }
    }

    /**
     * Collects foreign key information.
     * @param TableSchema $table the table metadata
     * @param string $indice Informix foreign key index name
     * @throws \yii\db\Exception
     */
    protected function findForeignKey($table, $indice)
    {
        $sql = <<<EOD
SELECT sysconstraints.constrname,
       sysindexes.tabid AS basetabid,
       sysindexes.part1 AS basepart1,
       sysindexes.part2 as basepart2,
       sysindexes.part3 as basepart3,
       sysindexes.part4 as basepart4,
       sysindexes.part5 as basepart5,
       sysindexes.part6 as basepart6,
       sysindexes.part7 as basepart7,
       sysindexes.part8 as basepart8,
       sysindexes.part9 as basepart9,
       sysindexes.part10 as basepart10,
       sysindexes.part11 as basepart11,
       sysindexes.part12 as basepart12,
       sysindexes.part13 as basepart13,
       sysindexes.part14 as basepart14,
       sysindexes.part15 as basepart15,
       sysindexes.part16 as basepart16,
       stf.tabid AS reftabid,
       TRIM(stf.tabname) AS reftabname,
       TRIM(stf.owner) AS refowner,
       sif.part1 as refpart1,
       sif.part2 as refpart2,
       sif.part3 as refpart3,
       sif.part4 as refpart4,
       sif.part5 as refpart5,
       sif.part6 as refpart6,
       sif.part7 as refpart7,
       sif.part8 as refpart8,
       sif.part9 as refpart9,
       sif.part10 as refpart10,
       sif.part11 as refpart11,
       sif.part12 as refpart12,
       sif.part13 as refpart13,
       sif.part14 as refpart14,
       sif.part15 as refpart15,
       sif.part16 as refpart16
FROM sysindexes
  INNER JOIN sysconstraints ON sysconstraints.idxname = sysindexes.idxname
  INNER JOIN sysreferences ON sysreferences.constrid = sysconstraints.constrid
  INNER JOIN systables AS stf ON stf.tabid = sysreferences.ptabid
  INNER JOIN sysconstraints AS scf ON scf.constrid = sysreferences. 'primary'
  INNER JOIN sysindexes AS sif ON sif.idxname = scf.idxname
WHERE sysindexes.idxname = :indice;
EOD;

        $command = $this->db->createCommand($sql, [':indice' => $indice]);
        foreach ($command->queryAll() as $row) {
            if ($this->db->slavePdo->getAttribute(PDO::ATTR_CASE) === PDO::CASE_UPPER) {
                $row = array_change_key_case($row, CASE_LOWER);
            }

            $foreignKey = [$row['reftabname']];
            $columnsbase = $this->getColumnsNumber($row['basetabid']);
            $columnsrefer = $this->getColumnsNumber($row['reftabid']);
            for ($x = 1; $x < 16; $x++) {
                $colnobase = (isset($row["basepart$x"])) ? abs($row["basepart$x"]) : 0;
                if ($colnobase === 0) {
                    continue;
                }
                $colnamebase = $columnsbase[$colnobase];
                $colnoref = (isset($row["refpart$x"])) ? abs($row["refpart$x"]) : 0;
                if ($colnoref === 0) {
                    continue;
                }
                $colnameref = $columnsrefer[$colnoref];
                $foreignKey[$colnameref] = $colnamebase;
            }

            $table->foreignKeys[$row['constrname']] = $foreignKey;
        }
    }

    /**
     * Returns all table names in the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception.
     * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
     * @return array all table names in the database. The names have NO schema name prefix.
     * @throws \yii\db\Exception
     */
    protected function findTableNames($schema = '')
    {
        $sql = <<<SQL
SELECT TRIM(tabname) AS tabname
FROM systables
WHERE systables.tabid >= 100
SQL;
        if ($schema !== '') {
            $sql .= " AND systables.owner=:schema";
        }
        $sql .= " ORDER BY systables.tabname;";
        $command = $this->db->createCommand($sql);
        if ($schema !== '') {
            $command->bindValue(':schema', $schema);
        }
        return $command->queryColumn();
    }

    protected function loadTablePrimaryKey($tableName)
    {
        // TODO: Implement loadTablePrimaryKey() method.
    }

    protected function loadTableForeignKeys($tableName)
    {
        // TODO: Implement loadTableForeignKeys() method.
    }

    protected function loadTableIndexes($tableName)
    {
        // TODO: Implement loadTableIndexes() method.
    }

    protected function loadTableUniques($tableName)
    {
        // TODO: Implement loadTableUniques() method.
    }

    protected function loadTableChecks($tableName)
    {
        // TODO: Implement loadTableChecks() method.
    }

    protected function loadTableDefaultValues($tableName)
    {
        // TODO: Implement loadTableDefaultValues() method.
    }
}
