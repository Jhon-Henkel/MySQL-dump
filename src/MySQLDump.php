<?php

declare(strict_types=1);

namespace MySQLDump;

use Exception;
use MySQLDump\Mask\IMaskValue;
use mysqli;

/**
 * MySQL database dump.
 *
 * @author     David Grudl (http://davidgrudl.com)
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 */
class MySQLDump
{
    public const NONE = 0;
    public const DROP = 1;
    public const CREATE = 2;
    public const DATA = 4;
    public const TRIGGERS = 8;
    public const ALL = 15; // DROP | CREATE | DATA | TRIGGERS

    private const MAX_SQL_SIZE = 1e6;

    /** @var array */
    public $tables = [
        '*' => self::ALL,
    ];

    /** @var mysqli */
    private $connection;
    /** @var array */
    private $maskList = [];


    /**
     * Connects to database.
     */
    public function __construct(mysqli $connection, string $charset = 'utf8')
    {
        $this->connection = $connection;

        if ($connection->connect_errno) {
            throw new Exception($connection->connect_error);

        } elseif (!$connection->set_charset($charset)) { // was added in MySQL 5.0.7 and PHP 5.0.5, fixed in PHP 5.1.5)
            throw new Exception($connection->error);
        }
    }


    /**
     * Saves dump to the file.
     */
    public function save(string $file): void
    {
        $handle = strcasecmp(substr($file, -3), '.gz') ? fopen($file, 'wb') : gzopen($file, 'wb');
        if (!$handle) {
            throw new Exception("ERROR: Cannot write file '$file'.");
        }
        $this->write($handle);
    }


    /**
     * Writes dump to logical file.
     * @param  resource
     */
    public function write($handle = null): void
    {
        if ($handle === null) {
            $handle = fopen('php://output', 'wb');
        } elseif (!is_resource($handle) || get_resource_type($handle) !== 'stream') {
            throw new Exception('Argument must be stream resource.');
        }

        $tables = $views = [];

        $res = $this->connection->query('SHOW FULL TABLES');
        while ($row = $res->fetch_row()) {
            if ($row[1] === 'VIEW') {
                $views[] = $row[0];
            } else {
                $tables[] = $row[0];
            }
        }
        $res->close();

        $tables = array_merge($tables, $views); // views must be last

        $this->connection->query('LOCK TABLES `' . implode('` READ, `', $tables) . '` READ');

        $db = $this->connection->query('SELECT DATABASE()')->fetch_row();
        fwrite($handle, '-- Created at ' . date('j.n.Y G:i') . " using David Grudl MySQL Dump Utility\n"
            . (isset($_SERVER['HTTP_HOST']) ? "-- Host: $_SERVER[HTTP_HOST]\n" : '')
            . '-- MySQL Server: ' . $this->connection->server_info . "\n"
            . '-- Database: ' . $db[0] . "\n"
            . "\n"
            . "SET NAMES utf8;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET UNIQUE_CHECKS=0;\n"
            . "SET AUTOCOMMIT=0;\n"
        );

        foreach ($tables as $table) {
            $this->dumpTable($handle, $table);
        }

        fwrite($handle, "COMMIT;\n");
        fwrite($handle, "-- THE END\n");

        $this->connection->query('UNLOCK TABLES');
    }


    /**
     * Dumps table to logical file.
     * @param  resource
     */
    public function dumpTable($handle, $table): void
    {
        $mode = isset($this->tables[$table]) ? $this->tables[$table] : $this->tables['*'];
        if ($mode === self::NONE) {
            return;
        }

        $delTable = $this->delimite($table);
        $res = $this->connection->query("SHOW CREATE TABLE $delTable");
        $row = $res->fetch_assoc();
        $res->close();

        fwrite($handle, "-- --------------------------------------------------------\n\n");

        $view = isset($row['Create View']);

        if ($mode & self::DROP) {
            fwrite($handle, 'DROP ' . ($view ? 'VIEW' : 'TABLE') . " IF EXISTS $delTable;\n\n");
        }

        if ($mode & self::CREATE) {
            fwrite($handle, $row[$view ? 'Create View' : 'Create Table'] . ";\n\n");
        }

        if (!$view && ($mode & self::DATA)) {
            fwrite($handle, 'ALTER ' . ($view ? 'VIEW' : 'TABLE') . ' ' . $delTable . " DISABLE KEYS;\n\n");
            $numeric = [];
            $res = $this->connection->query("SHOW COLUMNS FROM $delTable");
            $cols = [];
            while ($row = $res->fetch_assoc()) {
                $col = $row['Field'];
                $cols[] = $this->delimite($col);
                $numeric[$col] = (bool) preg_match('#^[^(]*(BYTE|COUNTER|SERIAL|INT|LONG$|CURRENCY|REAL|MONEY|FLOAT|DOUBLE|DECIMAL|NUMERIC|NUMBER)#i', $row['Type']);
            }
            $cols = '(' . implode(', ', $cols) . ')';
            $res->close();


            $size = 0;
            $res = $this->connection->query("SELECT * FROM $delTable", MYSQLI_USE_RESULT);
            while ($row = $res->fetch_assoc()) {
                $s = '(';
                foreach ($row as $key => $value) {
                    $value = $this->maskData($delTable, $key, $value, $row);
                    if ($value === null) {
                        $s .= "NULL,\t";
                    } elseif ($numeric[$key]) {
                        $s .= $value . ",\t";
                    } else {
                        $s .= "'" . $this->connection->real_escape_string($value) . "',\t";
                    }
                }

                if ($size == 0) {
                    $s = "INSERT INTO $delTable $cols VALUES\n$s";
                } else {
                    $s = ",\n$s";
                }

                $len = strlen($s) - 1;
                $s[$len - 1] = ')';
                fwrite($handle, $s, $len);

                $size += $len;
                if ($size > self::MAX_SQL_SIZE) {
                    fwrite($handle, ";\n");
                    $size = 0;
                }
            }

            $res->close();
            if ($size) {
                fwrite($handle, ";\n");
            }
            fwrite($handle, 'ALTER ' . ($view ? 'VIEW' : 'TABLE') . ' ' . $delTable . " ENABLE KEYS;\n\n");
            fwrite($handle, "\n");
        }

        if ($mode & self::TRIGGERS) {
            $res = $this->connection->query("SHOW TRIGGERS LIKE '" . $this->connection->real_escape_string($table) . "'");
            if ($res->num_rows) {
                fwrite($handle, "DELIMITER ;;\n\n");
                while ($row = $res->fetch_assoc()) {
                    fwrite($handle, "CREATE TRIGGER {$this->delimite($row['Trigger'])} $row[Timing] $row[Event] ON $delTable FOR EACH ROW\n$row[Statement];;\n\n");
                }
                fwrite($handle, "DELIMITER ;\n\n");
            }
            $res->close();
        }

        fwrite($handle, "\n");
    }


    private function delimite(string $s): string
    {
        return '`' . str_replace('`', '``', $s) . '`';
    }

    public function addMask(string $table, string $column, IMaskValue $mask, $concatValue = null): void
    {
        $tableToMask = $this->removeCrasisFromString($table);
        $columnToMask = $this->removeCrasisFromString($column);
        if (! isset($this->maskList[$tableToMask])) {
            $this->maskList[$tableToMask] = [];
        }
        $this->maskList[$tableToMask][$columnToMask] = $mask;
    }

    protected function maskData(string $table, string $column, $value, array $row = [])
    {
        $mask = $this->getMask($table, $column);
        return $mask ? $mask->maskValue($value, $row) : $value;
    }

    protected function getMask(string $table, string $column): ?IMaskValue
    {
        $tableToMask = $this->removeCrasisFromString($table);
        $columnToMask = $this->removeCrasisFromString($column);
        if (isset($this->maskList[$tableToMask], $this->maskList[$tableToMask][$columnToMask])) {
            return $this->maskList[$tableToMask][$columnToMask];
        }
        return null;
    }

    protected function removeCrasisFromString(string $string): string
    {
        return str_replace('`', '', $string);
    }
}
