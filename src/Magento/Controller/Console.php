<?php

/* 
 * Copyright (c) 2014 Lero9 Limited
 * All Rights Reserved
 * This software is subject to our terms of trade and any applicable licensing agreements.
 */

namespace Magento\Controller;

use Application\Controller\AbstractConsole;
use Magelink\Exception\MagelinkException;
use Zend\ServiceManager\ServiceLocatorAwareInterface;

/**
 * Manages assorted router maintenance tasks
 */
class Console extends AbstractConsole
{

    protected $_tasks = array(
        'testorder',
        'eavtest',
    );

    protected function eavtestTask($nid){

        $nid = intval($nid);

        $params = $this->getRequest()->getParam('params');

        $nodeType = 'magento';
        /** @var \Node\Repository\NodeRepository $nodeRep */
        $nodeRep = $this->getServiceLocator()
            ->get('Doctrine\ORM\EntityManager')
            ->getRepository('Node\Entity\Node');
        $nodeEntity = $nodeRep->find($nid);
        if (!$nodeEntity) {
            throw new MagelinkException('Could not find Magento node');
        }

        $_node = new \Magento\Node();
        if ($_node instanceof ServiceLocatorAwareInterface) {
            $_node->setServiceLocator($this->getServiceLocator());
        }
        $_node->init($nodeEntity);

        /** @var \Magento\Api\Db $db */
        $db = $this->getServiceLocator()->get('magento_db');
        if(!$db->init($_node)){
            throw new MagelinkException('Failed to connect to DB');
        }

        $db->updateEntityEav('catalog_product', 9375, 1, array('price'=>'1500000', 'special_price'=>'1000000'));

        $data = $db->loadEntityEav('catalog_product', 9375, false, array('price', 'special_price'));

    }

    protected function testorderTask($nid){

        $nid = intval($nid);

        $params = $this->getRequest()->getParam('params');

        $nodeType = 'magento';
        /** @var \Node\Repository\NodeRepository $nodeRep */
        $nodeRep = $this->getServiceLocator()
            ->get('Doctrine\ORM\EntityManager')
            ->getRepository('Node\Entity\Node');
        $nodeEntity = $nodeRep->find($nid);
        if (!$nodeEntity) {
            throw new MagelinkException('Could not find Magento node');
        }

        $_node = new \Magento\Node();
        if ($_node instanceof ServiceLocatorAwareInterface) {
            $_node->setServiceLocator($this->getServiceLocator());
        }
        $_node->init($nodeEntity);

        /** @var \Magento\Api\Soap $soap */
        $soap = $this->getServiceLocator()->get('magento_soap');
        if(!$soap->init($_node)){
            throw new MagelinkException('Failed to connect to SOAP');
        }

        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $orderCount = max(intval($params), 1);

        echo 'Creating ' . $orderCount . ' orders'.PHP_EOL;

        $customerEmail = 'lero9@test.lero9.co.nz';
        $customer = $entityService->loadEntity($nid, 'customer', 0, $customerEmail);

        $shipAddress = $customer->resolve('shipping_address');
        $billAddress = $customer->resolve('billing_address');

        $customerData = array(
            'firstname'=>$customer->getData('first_name'),
            'lastname'=>$customer->getData('last_name'),
            'email'=>$customer->getUniqueId(),
            'website_id'=>1,
            'store_id'=>1,
            'customer_id'=>$entityService->getLocalId($nid, $customer),
            'mode'=>'customer',
        );

        $addressData = array(
            array(
                'mode' => 'shipping',
                'firstname' => $shipAddress->getData('first_name'),
                'lastname' => $shipAddress->getData('last_name'),
                'street' => $shipAddress->getData('street'),
                'city' => $shipAddress->getData('city'),
                'region' => $shipAddress->getData('region'),
                'region_id' => $shipAddress->getData('region'),
                'telephone' => $shipAddress->getData('telephone'),
                'postcode' => $shipAddress->getData('postcode'),
                'country_id' => $shipAddress->getData('country_code'),
                'is_default_shipping' => 1,
                'is_default_billing' => 0
            ),
            array(
                'mode' => 'billing',
                'firstname' => $billAddress->getData('first_name'),
                'lastname' => $billAddress->getData('last_name'),
                'street' => $billAddress->getData('street'),
                'city' => $billAddress->getData('city'),
                'region' => $billAddress->getData('region'),
                'region_id' => $billAddress->getData('region'),
                'telephone' => $billAddress->getData('telephone'),
                'postcode' => $billAddress->getData('postcode'),
                'country_id' => $billAddress->getData('country_code'),
                'is_default_shipping' => 0,
                'is_default_billing' => 1
            ),
        );

        if(gethostname() == 'photon'){
            $shippingMethod = 'flatrate_flatrate';
        }else{
            $shippingMethod = 'premiumrate_nz_nationwide';
        }

        $paymentData = array(
            'po_number' => null,
            'method' => 'banktransfer',
            'cc_cid' => null,
            'cc_owner' => null,
            'cc_number' => null,
            'cc_type' => null,
            'cc_exp_year' => null,
            'cc_exp_month' => null
        );
        $invoice = true;

        $availableProducts = $entityService->executeQueryAssoc('SELECT p.unique_id AS k, si.available AS v FROM {product:p:visible,enabled,type} JOIN {stockitem:si:available,pickable} ON si.parent_id = p.entity_id WHERE si.available > 1 AND p.visible = 1 AND p.enabled = 1 AND p.type = "simple"');

        if(gethostname() == 'photon'){
            $availableProducts = array();
            $availableProducts['1113'] = 5;
            $availableProducts['1112'] = 4;
            $avaialbleProducts['1111'] = 3;
        }

        $patterns = array(
            array(1),
            array(5, 2),
            array(1,1,1),
            array(2,3),
            array(3,3,2),
            array(3),
            array(9)
        );

        $preserveCart = false;

        $cid = false;

        for($i = 0; $i < $orderCount; $i++){

            if(!$preserveCart){
                echo 'Creating new cart'.PHP_EOL;
                // Set up new cart
                $cid = $soap->call('shoppingCartCreate', array(1));
                if(!intval($cid)){
                    throw new MagelinkException('Failed to create shopping cart');
                }

                $soap->call('shoppingCartCustomerSet', array($cid, $customerData, 1));
                $soap->call('shoppingCartCustomerAddresses', array($cid, $addressData, 1));
            }

            $patternId = array_rand($patterns);
            $pattern = $patterns[$patternId];

            $doneProd = array();

            $toAdd = array();

            foreach($pattern as $numProd){
                $sku = false;
                $stock = 0;
                $limit = count($availableProducts);
                $j = 0;
                do{
                    $j++;
                    if($j > $limit){
                        break;
                    }
                    $sku = array_rand($availableProducts);
                    if(in_array($sku, $doneProd)){
                        $sku = false;
                        continue;
                    }
                    $stock = $availableProducts[$sku];
                }while($stock < $numProd);

                if(!$sku){
                    continue;
                }
                $doneProd[] = $sku;

                $toAdd[] = array(
                    'sku'=>$sku,
                    'quantity'=>$numProd,
                );
                echo 'Adding product ' . $sku . ' with ' . $numProd.PHP_EOL;

            }

            if(!count($toAdd)){
                echo 'No products available, trying again'.PHP_EOL;
                $preserveCart = true;
                continue;
            }

            try{
                $soap->call('shoppingCartProductAdd', array($cid, $toAdd, 1));
            }catch(\Exception $e){
                echo 'Exception when adding products, skipping: ' . $e->getMessage() . PHP_EOL;
                $preserveCart = true;
                continue;
            }

            $soap->call('shoppingCartShippingMethod', array($cid, $shippingMethod, 1));

            $res = $soap->call('shoppingCartTotals', array($cid, 1));
            var_export($res);
            echo PHP_EOL;

            $soap->call('shoppingCartPaymentMethod', array($cid, $paymentData, 1));

            echo 'Placing order...'.PHP_EOL;
            $orderId = $soap->call('shoppingCartOrder', array($cid, 1));

            if(!$orderId){
                throw new MagelinkException('Invalid order ID returned');
            }

            if($invoice){
                echo 'Invoicing...'.PHP_EOL;

                $orderData = $soap->call('salesOrderInfo', array($orderId));

                $itemIds = array();
                foreach($orderData['items'] as $itm){
                    $itemIds[] = array('order_item_id'=>$itm['item_id'], 'qty'=>$itm['qty_ordered']);
                }

                $soap->call('salesOrderInvoiceCreate', array($orderId, $itemIds));
            }

            echo 'Placed order '.$orderId.PHP_EOL;

            $preserveCart = false;

        }


    }

}