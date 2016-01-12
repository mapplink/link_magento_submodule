<?php
/**
 * Magento\Service
 * @category Magento
 * @package Magento\Service
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Service;

use Application\Service\ApplicationConfigService;
use Log\Service\LogService;
use Magelink\Exception\GatewayException;


class MagentoConfigService extends ApplicationConfigService
{

    /**
     * @return array $storeBaseCurrencies
     */
    protected function getStoreCurrencies()
    {
        return $this->getConfigData('store_currencies');
    }

    /**
     * @param int $storeId
     * @return string $baseCurrencyString
     */
    public function getBaseCurrency($storeId)
    {
        $storeCurrencies = $this->getStoreCurrencies();

        if ($storeId != (int) $storeId) {
            $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR,
                'mag_csvc_bc_stid',
                'Invalid call of getBaseCurrency.',
                array('store id'=>$storeId, 'store currencies'=>$storeCurrencies)
            );
        }

        if (!is_array($storeCurrencies) && array_key_exists($storeId, $storeCurrencies) && $storeCurrencies[$storeId]) {
            $baseCurrencyString = $storeCurrencies[$storeId];
        }elseif (is_array($storeCurrencies) && array_key_exists(0, $storeCurrencies) && $storeCurrencies[0]) {
            $baseCurrencyString = $storeCurrencies[0];
        }else{
            $baseCurrencyString = NULL;
            new GatewayException('The store currency configuration is not valid. (Called with storeId '.$storeId.'.)');
        }

        return $baseCurrencyString;
    }

    /**
     * @return array $storeMap
     */
    protected function getStoreMap()
    {
        return $this->getConfigData('store_map');
    }

    /**
     * @param string $entityType
     * @param int $storeId
     * @param bool $readFromManento
     * @return array $productMap
     */
    public function getMap($entityType, $storeId, $readFromMagento)
    {
        $map = array();
        $storeMap = $this->getStoreMap();

        if (!is_numeric($storeId) && $readFromMagento ) {
            new GatewayException('That is not a valid call for store map with no store id and reading from Magento.');
        }else{
            foreach ($storeMap as $id=>$mapPreStore) {
                if ($storeId === FALSE || $storeId == $id && isset($storeMap[$id][$entityType])) {
                    $mapPerStoreAndEntityType = $storeMap[$id][$entityType];
                    $flippedMap = array_flip($mapPerStoreAndEntityType);

                    if (!is_array($mapPerStoreAndEntityType) || count($mapPerStoreAndEntityType) != count($flippedMap)) {
                        $message = 'There is no valid '.$entityType.' map';
                        if ($storeId !== FALSE) {
                            $message .= ' for store '.$storeId;
                        }
                        new GatewayException($message.'.');
                    }elseif ($readFromMagento) {
                        $map = array_replace_recursive($mapPerStoreAndEntityType, $map);
                    }else{
                        $map = array_replace_recursive($flippedMap, $map);
                    }
                }
            }
        }

        return $map;
    }

}