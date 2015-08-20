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

    /** @var int $lastRetrieveTimestamp */
    protected $lastRetrieveTimestamp = NULL;

    /** @var int $newRetrieveTimestamp */
    protected $newRetrieveTimestamp = NULL;


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

    /** @return int $this->newRetrieveTimestamp */
    protected function getNewRetrieveTimestamp()
    {
        if ($this->newRetrieveTimestamp === NULL) {
            $this->newRetrieveTimestamp = time() - $this->apiOverlappingSeconds;
        }

        return $this->newRetrieveTimestamp;
    }

    /** @param int $timestamp
     * @return bool|string $date */
    protected function convertTimestampToMagentoDateFormat($timestamp)
    {
        $deltaInSeconds = intval($this->_node->getConfig('time_delta_'.static::GATEWAY_ENTITY)) * 3600;
        $date = date('Y-m-d H:i:s', $timestamp + $deltaInSeconds);

        return $date;
    }

    /** @return bool|string $lastRetrieve */
    protected function getLastRetrieveDate()
    {
        $lastRetrieve = $this->convertTimestampToMagentoDateFormat($this->getLastRetrieveTimestamp());
        return $lastRetrieve;
    }

    /** @return bool|int $this->lastRetrieveTimestamp */
    protected function getLastRetrieveTimestamp()
    {
        if ($this->lastRetrieveTimestamp === NULL) {
            $this->lastRetrieveTimestamp =
                $this->_nodeService->getTimestamp($this->_nodeEntity->getNodeId(), static::GATEWAY_ENTITY, 'retrieve');
        }

        return $this->lastRetrieveTimestamp;
    }

}