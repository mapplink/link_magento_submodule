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

use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use \Zend\ServiceManager\ServiceLocatorAwareInterface;
use \Zend\ServiceManager\ServiceLocatorInterface;
use \Zend\Db\TableGateway\TableGateway;


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

}