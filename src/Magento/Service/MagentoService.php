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

use Log\Service\LogService;
use Magelink\Exception\GatewayException;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Zend\Db\TableGateway\TableGateway;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;


class MagentoService implements ServiceLocatorAwareInterface
{

    const PRODUCT_TYPE_VIRTUAL = 'virtual';
    const PRODUCT_TYPE_DOWNLOADABLE = 'downloadable';
    const PRODUCT_TYPE_GIFTCARD = 'giftcard';

    /** @var ServiceLocatorInterface */
    protected $_serviceLocator;

    /**
     * Set service locator
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->_serviceLocator = $serviceLocator;
    }

    /**
     * Get service locator
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->_serviceLocator;
    }

    /**
     * Check if product is shippable
     * @param string $productType
     * @return bool
     */
    public function isProductTypeShippable($productType)
    {
        $notShippableTypes = array(
            self::PRODUCT_TYPE_VIRTUAL,
            self::PRODUCT_TYPE_DOWNLOADABLE,
            self::PRODUCT_TYPE_GIFTCARD
        );

        $isShippable = !in_array($productType, $notShippableTypes);
        return $isShippable;
    }

    /**
     * @param string $entityType
     * @param array $data
     * @param int $storeId
     * @param bool $readFromMagento
     * @throws GatewayException
     * @return array $mappedData
     * @throws GatewayException
     */
    protected function mapData($entityType, array $data, $storeId, $readFromMagento)
    {
        /** @var \Magento\Service\MagentoConfigService $configService */
        $configService = $this->getServiceLocator()->get('magentoConfigService');
        $map = $configService->getMap($entityType, $storeId, $readFromMagento);

        foreach ($map as $mapFrom=>$mapTo)
        {
            if (array_key_exists($mapTo, $data)) {
                $message = 'Re-mapping from '.$mapFrom.' to '.$mapTo.' failed because key is already existing in '
                    .$entityType.' data: '.implode(', ', array_keys($data)).'.';
                throw new GatewayException($message);
            }elseif (array_key_exists($mapFrom, $data)) {
                $data[$mapTo] = $data[$mapFrom];
                unset($mapFrom);
            }
        }

        return $data;
    }

    /**
     * @param array $productData
     * @param int $storeId
     * @param bool|true $readFromMagento
     * @return array $mappedProductData
     */
    public function mapProductData(array $productData, $storeId, $readFromMagento = TRUE)
    {
        $mappedProductData = $this->mapData('product', $productData, $storeId, $readFromMagento);
        return $mappedProductData;
    }

}