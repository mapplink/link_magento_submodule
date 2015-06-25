<?php
/**
 * Magento Abstract Gateway
 * @category Magento
 * @package Magento\Api
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Magelink\Exception\MagelinkException;
use Magelink\Exception\GatewayException;
use Node\AbstractGateway as BaseAbstractGateway;
use Node\AbstractNode;
use Node\Entity;


abstract class AbstractGateway extends BaseAbstractGateway
{

    /** @var \Entity\Service\EntityConfigService $entityConfigService */
    protected $entityConfigService = NULL;

    /** @var \Magento\Api\Db */
    protected $_db = NULL;
    /** @var \Magento\Api\Soap */
    protected $_soap = NULL;

    /** @var int $apiOverlappingSeconds */
    protected $apiOverlappingSeconds = 3;


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @throws MagelinkException
     * @return bool $success
     */
    protected function _init($entityType)
    {
        $this->_db = $this->_node->getApi('db');
        $this->_soap = $this->_node->getApi('soap');

        if (!$this->_soap) {
            throw new GatewayException('SOAP is required for Magento '.ucfirst($entityType));
            $success = FALSE;
        }else{
            $this->apiOverlappingSeconds += $this->_node->getConfig('api_overlapping_seconds');
            $success = TRUE;
        }

        return $success;
    }
}