<?php

namespace edgardmessias\unit\db\informix;

use edgardmessias\db\informix\Connection;

/**
 * @group sphinx
 */
trait DatabaseTestTrait
{

    public function setUp()
    {
        if (static::$params === null) {
            static::$params = include __DIR__ . '/data/config.php';
        }

        parent::setUp();
    }

    public function prepareDatabase($config, $fixture, $open = true)
    {
        if (!isset($config['class'])) {
            $config['class'] = Connection::class;
        }
        /* @var $db Connection */
        $db = \Yii::createObject($config);
        if (!$open) {
            return $db;
        }
        $db->open();
        if ($fixture !== null) {
            $lines = explode(';', file_get_contents($fixture));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    try {
                        $db->pdo->exec($line);
                    } catch (\Exception $e) {
                        if (stripos($line, 'DROP') === false) {
                            $this->markTestSkipped("Something wrong when preparing database: " . $e->getMessage() . "\nSQL: " . $line);
                        }
                    }
                }
            }
        }
        return $db;
    }

    /**
     * adjust dbms specific escaping
     * @param $sql
     * @return mixed
     */
    protected function replaceQuotes($sql)
    {
        if ($this->database === null) {
            $this->setUp();
        }
        $connection = $this->getConnection(false, false);
        if (($connection->isDelimident())) {
            return str_replace(['[[', ']]'], '"', $sql);
        }

        return str_replace(['[[', ']]'], '', $sql);
    }
}
