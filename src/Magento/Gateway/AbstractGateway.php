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

    const GATEWAY_ENTITY = 'generic';
    const GATEWAY_ENTITY_CODE = 'gty';


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

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     */
/* MIGHT BE BETTER IMPLEMENTED IN BASEABSTRACTGATEWAY straight away or in a second step
    public function retrieve()
    {
        $this->getNewRetrieveTimestamp();
        $this->getLastRetrieveDate();

        $results = $this->retrieveEntities();

        $logCode = 'mag_'.static::GATEWAY_ENTITY_CODE.'_re_no'
        $seconds = ceil($this->getAdjustedTimestamp() - $this->getNewRetrieveTimestamp());
        $message = 'Retrieved '.$results.' '.static::GATEWAY_ENTITY.'s in '.$seconds.'s up to '
            .strftime('%H:%M:%S, %d/%m', $this->retrieveTimestamp).'.';
        $logData = array('type'=>static::GATEWAY_ENTITY, 'amount'=>$results, 'period [s]'=>$seconds);
        if (count($results) > 0) {
                $logData['per entity [s]'] = round($seconds / count($results), 3);
        }
        $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_INFO, 'mag_cu_re_no', $message, $logData);
    }

    abstract protected retrieveEntities();
*/

    /**
     * @return int $adjustedTimestamp
     */
    protected function getAdjustedTimestamp($timestamp = NULL)
    {
        if (is_null($timestamp) || intval($timestamp) != $timestamp || $timestamp == 0) {
            $timestamp = time();
        }

        return $timestamp - $this->apiOverlappingSeconds;
    }

    /**
     * @return int $this->newRetrieveTimestamp
     */
    protected function getNewRetrieveTimestamp()
    {
        if ($this->newRetrieveTimestamp === NULL) {
            $this->newRetrieveTimestamp = $this->getAdjustedTimestamp($this->getRetrieveTimestamp());
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
