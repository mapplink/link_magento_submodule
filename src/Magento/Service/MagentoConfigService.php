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
        $storeMap = $this->getStoreMap();

        if (isset($storeMap[$storeId][$entityType])) {
            $map = $storeMap[$storeId][$entityType];
            $flippedMap = array_flip($map);
            if (!is_array($map) || count($map) != count($flippedMap)) {
                new GatewayException('There is no valid '.$entityType.' map for store '.$storeId.'.');
            }elseif (!$readFromMagento) {
                $map = $flippedMap;
            }
        }else{
            $map = array();
        }

        return $map;
    }

}