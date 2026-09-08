<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\Db;

use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\DB\LoggerInterface;

/**
 * Quotes a value without opening the connection. Building a select quotes
 * its conditions, and Zend asks the PDO driver for that, so a request that
 * serves every select from the cache still connects to MySQL and runs the
 * three session statements. Until the connection exists, the value is
 * escaped as the driver escapes it for utf8 (the same bytes, so a select
 * cache id built from it does not change); once connected, the driver
 * quotes.
 */
class Mysql extends \Magento\Framework\DB\Adapter\Pdo\Mysql
{
    private const SWITCH = 'quote_without_connection';

    private bool $withoutConnection = true;

    public function __construct(
        StringUtils $string,
        DateTime $dateTime,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        array $config = [],
        ?SerializerInterface $serializer = null,
        ?DtoFactoriesTable $dtoFactoriesTable = null,
        ?Feature $feature = null
    ) {
        parent::__construct($string, $dateTime, $logger, $selectFactory, $config, $serializer, $dtoFactoriesTable);
        $this->withoutConnection = $feature === null || $feature->on(self::SWITCH);
    }

    public function quote($value, $type = null)
    {
        if ($this->_connection || !$this->withoutConnection) {
            return parent::quote($value, $type);
        }
        if ($value instanceof \Zend_Db_Select) {
            return '(' . $value->assemble() . ')';
        }
        if ($value instanceof \Zend_Db_Expr) {
            return $value->__toString();
        }
        if (is_array($value)) {
            foreach ($value as &$val) {
                $val = $this->quote($val, $type);
            }

            return implode(', ', $value);
        }
        if ($type !== null && array_key_exists(strtoupper((string)$type), $this->_numericDataTypes)) {
            return parent::quote($value, $type);
        }

        return $this->_quote($value ?? '');
    }

    protected function _quote($value)
    {
        if ($this->_connection || !$this->withoutConnection || is_int($value) || is_float($value)) {
            return parent::_quote($value);
        }

        return "'" . addcslashes((string)$value, "\000\n\r\\'\"\032") . "'";
    }
}
