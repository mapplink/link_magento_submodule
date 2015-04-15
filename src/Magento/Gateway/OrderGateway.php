<?php
/**
 * Magento\Gateway\OrderGateway
 *
 * @category Magento
 * @package Magento\Gateway
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Entity\Comment;
use Entity\Service\EntityService;
use Entity\Wrapper\Order;
use Entity\Wrapper\Orderitem;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Node\AbstractNode;
use Node\Entity;
use Zend\Stdlib\ArrayObject;


class OrderGateway extends AbstractGateway
{
    const MAGENTO_STATUS_COMPLETE = 'complete';
    const MAGENTO_STATUS_CANCELED = 'canceled';
    const MAGENTO_STATUS_PROCESSING = 'processing';
    const MAGENTO_STATUS_PROCESSING_DPS_PAID = 'processing_dps_paid';
    const MAGENTO_STATUS_PROCESSING_OGONE = 'processed_ogone';
    const MAGENTO_STATUS_PROCESSING_DPS_AUTH = 'processing_dps_auth';
    const MAGENTO_STATUS_PAYPAL_REVERSAL = 'paypal_canceled_reversal';

    protected static $magentoProcessingStatusses = array(
        self::MAGENTO_STATUS_PROCESSING,
        self::MAGENTO_STATUS_PROCESSING_DPS_PAID,
        self::MAGENTO_STATUS_PROCESSING_OGONE,
        self::MAGENTO_STATUS_PROCESSING_DPS_AUTH,
        self::MAGENTO_STATUS_PAYPAL_REVERSAL
    );
    /** @var int $lastRetrieveTimestamp */
    protected $lastRetrieveTimestamp = NULL;

    /** @var int $newRetrieveTimestamp */
    protected $newRetrieveTimestamp = NULL;

    /** @var array $notRetrievedOrderIncrementIds */
    protected $notRetrievedOrderIncrementIds = NULL;


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param AbstractNode $node
     * @param Entity\Node $nodeEntity
     * @param string $entity_type
     * @throws \Magelink\Exception\MagelinkException
     * @return boolean
     */
    public function init(AbstractNode $node, Entity\Node $nodeEntity, $entityType)
    {
        if ($entityType != 'order') {
            $success = FALSE;
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
        }else{
            $success = parent::init($node, $nodeEntity, $entityType);
        }

        return $success;
    }

    /**
     * @param int $timestamp
     * @return bool|string $date
     */
    protected function convertTimestampToMagentoDateFormat($timestamp)
    {
        $deltaInSeconds = intval($this->_node->getConfig('time_delta_order')) * 3600;
        $date = date('Y-m-d H:i:s', $timestamp + $deltaInSeconds);
        return $date;
    }

    /**
     * Get new retrieve timestamp
     * @return int
     */
    protected function getNewRetrieveTimestamp()
    {
        if ($this->newRetrieveTimestamp === NULL) {
            $this->newRetrieveTimestamp = time() - $this->apiOverlappingSeconds;
        }

        return $this->newRetrieveTimestamp;
    }

    /**
     * Get last retrieve date from the database
     * @return bool|string
     */
    protected function getLastRetrieveTimestamp()
    {
        if ($this->lastRetrieveTimestamp === NULL) {
            $this->lastRetrieveTimestamp =
                $this->_nodeService->getTimestamp($this->_nodeEntity->getNodeId(), 'order', 'retrieve');
        }

        return $this->lastRetrieveTimestamp;
    }

    /**
     * Get last retrieve date from the database
     * @return bool|string
     */
    protected function getLastRetrieveDate()
    {
        $lastRetrieve = $this->convertTimestampToMagentoDateFormat($this->getLastRetrieveTimestamp());
        return $lastRetrieve;
    }

    /**
     * Get last retrieve date from the database
     * @return bool|string
     */
    protected function getRetrieveDateForForcedSynchronisation()
    {
        if ($this->newRetrieveTimestamp !== NULL) {
            $intervalsBefore = 3;
            $retrieveInterval = $this->newRetrieveTimestamp - $this->getLastRetrieveTimestamp();

            $retrieveTimestamp = $this->getLastRetrieveTimestamp() - $retrieveInterval * $intervalsBefore;
            $date = $this->convertTimestampToMagentoDateFormat($retrieveTimestamp);
        }else{
            $date = FALSE;
        }

        return $date;
    }

    /**
     * Check, if the order should be ignored or imported
     * @param array $orderData
     * @return bool
     */
    protected function isOrderToBeRetrieved(array $orderData)
    {
        // Check if order has a magento increment id
        if (intval($orderData['increment_id']) > 100000000) {
            $retrieve = TRUE;
        }else{
            $retrieve =  FALSE;
        }

        return $retrieve;
    }

    protected static function isOrderStateProcessing($orderStatus)
    {
        $isOrderStateProcessing = in_array($orderStatus, self::$magentoProcessingStatusses);
        return $isOrderStateProcessing;
    }

    /**
     * @param Order $order
     * @param Orderitem $orderitem
     * @return bool|NULL
     * @throws MagelinkException
     */
    protected function updateQtyPreTransit(Order $order, Orderitem $orderitem)
    {
        $qtyPreTransit = NULL;
        $orderStatus = $order->getData('status');

        if (self::isOrderStateProcessing($orderStatus)) {
            $storeId = ($this->_node->isMultiStore() ? $order->getData('store_id') : 0);

            $stockitem = $this->_entityService->loadEntity(
                $this->_node->getNodeId(),
                'stockitem',
                $storeId,
                $orderitem->getData('sku')
            );

            $success = FALSE;
            if ($stockitem) {
                try{
                    $qtyPreTransit = $stockitem->getData('qty_pre_transit', 0) + $orderitem->getData('quantity', 0);
                    $updateData = array('qty_pre_transit'=>$qtyPreTransit);
                    $this->_entityService->updateEntity($this->_node->getNodeId(), $stockitem, $updateData, FALSE);
                    $success = TRUE;
                }catch (\Exception $exception) {
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_ERROR,
                            'upd_pre_trans_fail',
                            'Update of qty_pre_transit failed on stockitem '.$stockitem->getEntityId(),
                            array('sku'=>$stockitem->getUniqueId(), 'qty_pre_transit'=>$qtyPreTransit),
                            array('node'=>$this->_node, 'stockitem'=>$stockitem, 'orderitem'=>$orderitem)
                        );
                }
            }else {
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR,
                        'stocki_noxis',
                        'Stockitem '.$orderitem->getData('sku').' does not exist.',
                        array('sku'=>$orderitem->getData('sku')),
                        array('node'=>$this->_node, 'orderitem'=>$orderitem)
                    );
            }
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUGEXTRA,
                    'upd_pre_trans_fail',
                    'No update of qty_pre_transit. Order '.$order->getUniqueId().' has wrong status: '.$orderStatus,
                    array('order id'=>$order->getId()),
                    array('node'=>$this->_node, 'order'=>$order)
                );
            $success = NULL;
        }

        return $success;
    }

    /**
     * Store order with provided order data
     * @param array $orderData
     * @throws MagelinkException
     * @throws \Exception
     * @throws \Magelink\Exception\NodeException
     */
    protected function storeOrderData(array $orderData, $forced = FALSE)
    {
        if ($forced) {
            $logLevel = \Log\Service\LogService::LEVEL_WARN;
            $logCodeSuffix = '_forced';
            $logMessageSuffix = ' (out of sync - forced)';
        }else{
            $logLevel = \Log\Service\LogService::LEVEL_INFO;
            $logMessageSuffix = $logCodeSuffix = '';
        }
        $correctionHours = sprintf('%+d hours', intval($this->_node->getConfig('time_correction_order')));

        $storeId = ($this->_node->isMultiStore() ? $orderData['store_id'] : 0);
        $uniqueId = $orderData['increment_id'];
        $localId = isset($orderData['entity_id']) ? $orderData['entity_id'] : $orderData['order_id'];
        $createdAtTimestamp = strtotime($orderData['created_at']);

        $data = array(
            'customer_email'=>array_key_exists('customer_email', $orderData)
                ? $orderData['customer_email'] : NULL,
            'customer_name'=>(
                array_key_exists('customer_firstname', $orderData) ? $orderData['customer_firstname'].' ' : '')
                .(array_key_exists('customer_lastname', $orderData) ? $orderData['customer_lastname'] : ''
                ),
            'status'=>$orderData['status'],
            'placed_at'=>date('Y-m-d H:i:s', strtotime($correctionHours, $createdAtTimestamp)),
            'grand_total'=>$orderData['base_grand_total'],
            'weight_total'=>(array_key_exists('weight', $orderData)
                ? $orderData['weight'] : 0),
            'discount_total'=>(array_key_exists('base_discount_amount', $orderData)
                ? $orderData['base_discount_amount'] : 0),
            'shipping_total'=>(array_key_exists('base_shipping_amount', $orderData)
                ? $orderData['base_shipping_amount'] : 0),
            'tax_total'=>(array_key_exists('base_tax_amount', $orderData)
                ? $orderData['base_tax_amount'] : 0),
            'shipping_method'=>(array_key_exists('shipping_method', $orderData)
                ? $orderData['shipping_method'] : NULL)
        );

        if (array_key_exists('base_gift_cards_amount', $orderData)) {
            $data['giftcard_total'] = $orderData['base_gift_cards_amount'];
        }elseif (array_key_exists('base_gift_cards_amount_invoiced', $orderData)) {
            $data['giftcard_total'] = $orderData['base_gift_cards_amount_invoiced'];
        }else{
            $data['giftcard_total'] = 0;
        }
        if (array_key_exists('base_reward_currency_amount', $orderData)) {
            $data['reward_total'] = $orderData['base_reward_currency_amount'];
        }elseif (array_key_exists('base_reward_currency_amount_invoiced', $orderData)) {
            $data['reward_total'] = $orderData['base_reward_currency_amount_invoiced'];
        }else{
            $data['reward_total'] = 0;
        }
        if (array_key_exists('base_customer_balance_amount', $orderData)) {
            $data['storecredit_total'] = $orderData['base_customer_balance_amount'];
        }elseif (array_key_exists('base_customer_balance_amount_invoiced', $orderData)) {
            $data['storecredit_total'] = $orderData['base_customer_balance_amount_invoiced'];
        }else{
            $data['storecredit_total'] = 0;
        }

        $payments = array();
        if (isset($orderData['payment'])) {
            if (is_array($orderData['payment']) && !isset($orderData['payment']['payment_id'])) {
                foreach ($orderData['payment'] as $payment) {
                    $payments = $this->_entityService->convertPaymentData(
                        $payment['method'], $payment['base_amount_ordered'], $payment['cc_type']);
                }
            }elseif (isset($orderData['payment']['payment_id'])) {
                $payments = $this->_entityService->convertPaymentData(
                    $orderData['payment']['method'],
                    $orderData['payment']['base_amount_ordered'],
                    (isset($orderData['payment']['cc_type']) ? $orderData['payment']['cc_type'] : '')
                );
            }else{
                throw new MagelinkException('Invalid payment details format for order '.$uniqueId);
            }
        }
        if (count($payments)) {
            $data['payment_method'] = $payments;
        }

        if (isset($orderData['customer_id']) && $orderData['customer_id'] ){
            $customer = $this->_entityService
                ->loadEntityLocal($this->_node->getNodeId(), 'customer', $storeId, $orderData['customer_id']);
            // $customer = $this->_entityService->loadEntity($this->_node->getNodeId(), 'customer', $storeId, $orderData['customer_email'])
            if ($customer && $customer->getId()) {
                $data['customer'] = $customer;
            }else{
                $data['customer'] = NULL;
            }
        }

        $needsUpdate = TRUE;

        $existingEntity = $this->_entityService->loadEntityLocal(
            $this->_node->getNodeId(),
            'order',
            $storeId,
            $localId
        );

        if (!$existingEntity) {
            $existingEntity = $this->_entityService->loadEntity(
                $this->_node->getNodeId(),
                'order',
                $storeId,
                $uniqueId
            );

            if (!$existingEntity) {
                $this->_entityService->beginEntityTransaction('magento-order-'.$uniqueId);
                try{
                    $data = array_merge(
                        $this->createAddresses($orderData),
                        $data
                    );
                    $existingEntity = $this->_entityService->createEntity(
                        $this->_node->getNodeId(),
                        'order',
                        $storeId,
                        $uniqueId,
                        $data,
                        NULL
                    );
                    $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);

                    $this->getServiceLocator()->get('logService')
                        ->log($logLevel,
                            'ent_new'.$logCodeSuffix,
                            'New order '.$uniqueId.$logMessageSuffix,
                            array('sku'=>$uniqueId),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );

                    $this->createItems($orderData, $existingEntity);

                    try{
                        // ToDo: Get new status from Magento to prevent overwrites (via db api?)
                        $status = $existingEntity->getData('status');
                        $comment = 'Order retrieved by MageLink, Entity #'.$existingEntity->getId();
                        $this->_soap->call('salesOrderAddComment', array($uniqueId, $status, $comment, FALSE));
                    }catch (\Exception $exception) {
                        $this->getServiceLocator()->get('logService')
                            ->log($logLevel,
                                'ent_comment_err'.$logCodeSuffix,
                                'Failed to write comment on order '.$uniqueId.$logMessageSuffix,
                                array(),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                    }
                    $this->_entityService->commitEntityTransaction('magento-order-'.$uniqueId);
                }catch(\Exception $exception){
                    $this->_entityService->rollbackEntityTransaction('magento-order-'.$uniqueId);
                    throw $exception;
                }
                $needsUpdate = FALSE;
            }else{
                $this->getServiceLocator()->get('logService')
                    ->log($logLevel,
                        'ent_link'.$logCodeSuffix,
                        'Unlinked order '.$uniqueId.$logMessageSuffix,
                        array('sku'=>$uniqueId),
                        array('node'=>$this->_node, 'entity'=>$existingEntity)
                    );
                $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
            }
        }else{
            $attributesNotToUpdate = array('grand_total');
            foreach ($attributesNotToUpdate as $code) {
                if ($existingEntity->getData($code, NULL) !== NULL) {
                    unset($data[$code]);
                }
            }
            $this->getServiceLocator()->get('logService')
                ->log($logLevel,
                    'ent_update'.$logCodeSuffix,
                    'Updated order '.$uniqueId.$logMessageSuffix,
                    array('sku'=>$uniqueId),
                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                );
        }

        if ($needsUpdate) {
            $movedToProcessing = self::isOrderStateProcessing($orderData['status'])
                && !self::isOrderStateProcessing($existingEntity->getData('status'));
            $this->_entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);

            $order = $this->_entityService->loadEntityId($this->_node->getNodeId(), $existingEntity->getId());
            if ($movedToProcessing) {
                /** @var Order $order */
                foreach ($order->getOrderitems() as $orderitem) {
                    $this->updateQtyPreTransit($order, $orderitem);
                }
            }
        }
        $this->updateStatusHistory($orderData, $existingEntity);
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @throws MagelinkException
     * @throws \Exception
     * @throws \Magelink\Exception\NodeException
     */
    public function retrieve()
    {
        $timestamp = $this->getNewRetrieveTimestamp();
        $lastRetrieve = $this->getLastRetrieveDate();

        $this->getServiceLocator()->get('logService')
            ->log(\Log\Service\LogService::LEVEL_INFO,
                'retr_time',
                'Retrieving orders updated since '.$lastRetrieve,
                array('type'=>'order', 'timestamp'=>$lastRetrieve)
            );

        $success = NULL;
        if (FALSE && $this->_db) {
            // ToDo (maybe): Implement
            $storeId = $orderIds = FALSE;
            $results = $this->_db->getOrders($storeId, $orderIds, $lastRetrieve);
            foreach ($results as $orderData) {
                $orderData = (array) $orderData;
                if ($this->isOrderToBeRetrieved($orderData)) {
                    $success = $this->storeOrderData($orderData);
                }
            }
        }elseif ($this->_soap) {
            $results = $this->_soap->call(
                'salesOrderList',
                array(array('complex_filter'=>array(
                    array(
                        'key'=>'updated_at',
                        'value'=>array('key'=>'gt', 'value'=>$lastRetrieve),
                    )
                )))
            );

            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_INFO,'salesOrderList','salesOrderList',array('results'=>$results));
            foreach ($results as $orderFromList) {
                if ($this->isOrderToBeRetrieved($orderFromList)) {
                    $orderData = $this->_soap->call('salesOrderInfo', array($orderFromList['increment_id']));
                    if (isset($orderData['result'])) {
                        $orderData = $orderData['result'];
                    }

                    unset ($orderFromList['status']); // Reduces risk overwriting an updated status when adding a comment
                    $missingFieldsInSalesOrderList = array_diff(array_keys($orderFromList), array_keys($orderData));

                    foreach ($missingFieldsInSalesOrderList as $key) {
                        $orderData[$key] = $orderFromList[$key];
                    }

                    $this->storeOrderData($orderData);
                }
            }
        }else{
            throw new \Magelink\Exception\NodeException('No valid API available for sync');
        }

        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'order', 'retrieve', $timestamp);

        $this->forceSynchronisation();
    }

    /**
     * Compare orders on Magento with the orders no Magelink and return increment id array of orders not retrieved
     * @return array|bool $notRetrievedOrderIncrementIds
     * @throws MagelinkException
     */
    protected function getNotRetrievedOrders()
    {
        if ($this->notRetrievedOrderIncrementIds === NULL) {
            $notRetrievedOrderIncrementIds = array();

            if ($this->_db) {
                $results = $this->_db->getOrders(FALSE, FALSE, $this->getRetrieveDateForForcedSynchronisation());
            }elseif ($this->_soap) {
                if ($this->getRetrieveDateForForcedSynchronisation()) {
                    $soapCallFilterData = array(array('complex_filter'=>array(
                        array(
                            'key'=>'updated_at',
                            'value'=>array('key'=>'gt', 'value'=>$this->getRetrieveDateForForcedSynchronisation()),
                        )
                    )));
                }else{
                    $soapCallFilterData = array();
                }
                $results = $this->_soap->call('salesOrderList', $soapCallFilterData);
            }else {
                throw new \Magelink\Exception\NodeException('No valid API available for synchronisation check');
            }

            foreach ($results as $magentoOrder) {
                if ($magentoOrder instanceof \ArrayObject) {
                    $magentoOrder = (array) $magentoOrder;
                }
                if ($this->isOrderToBeRetrieved((array) $magentoOrder)) {
                    $magelinkOrder = $this->_entityService->loadEntity(
                        $this->_nodeEntity->getNodeId(),
                        'order',
                        0,
                        $magentoOrder['increment_id']
                    );
                    if (!$magelinkOrder) {
                        $notRetrievedOrderIncrementIds[$magentoOrder['increment_id']] = $magentoOrder['increment_id'];
                    }
                }
            }

            if ($notRetrievedOrderIncrementIds) {
                $this->notRetrievedOrderIncrementIds = $notRetrievedOrderIncrementIds;
            }else {
                $this->notRetrievedOrderIncrementIds = FALSE;
            }
        }

        return $this->notRetrievedOrderIncrementIds;
    }

    /**
     * Check if all orders are retrieved from Magento into Magelink
     * @return bool
     */
    protected function areOrdersInSync()
    {
        if ($this->notRetrievedOrderIncrementIds === NULL) {
            $this->getNotRetrievedOrders();
        }
        $isInSync = !(bool) $this->notRetrievedOrderIncrementIds;

        return $isInSync;
    }

    /**
     * Check for orders out of sync; load, create and check them; return success/failure
     * @return bool
     * @throws MagelinkException
     * @throws \Exception
     */
    public function forceSynchronisation()
    {
        $success = TRUE;
        if (!$this->areOrdersInSync()) {
            $orderOutOfSyncList = implode(', ', $this->notRetrievedOrderIncrementIds);
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_WARN,
                    'forced_retrieve',
                    'Retrieving orders: '.$orderOutOfSyncList,
                    array(),
                    array('order increment ids out of sync'=>$orderOutOfSyncList)
                );

            foreach ($this->notRetrievedOrderIncrementIds as $orderIncrementId) {
                if (FALSE && $this->_db) {
                    // ToDo (maybe): Implemented
                    $orderData = (array) $this->_db->getOrderByIncrementId($orderIncrementId);
                }elseif ($this->_soap) {
                    $orderData = $this->_soap->call('salesOrderInfo', array($orderIncrementId));
                    if (isset($orderData['result'])) {
                        $orderData = $orderData['result'];
                    }

                    $results = $this->_soap->call(
                        'salesOrderList',
                        array(array('complex_filter'=>array(
                            array(
                                'key'=>'increment_id',
                                'value'=>array('key'=>'eq', 'value'=>$orderIncrementId),
                            )
                        )))
                    );
                    $orderFromList = array_shift($results);

                    foreach (array_diff(array_keys($orderFromList), array_keys($orderData)) as $key) {
                        $orderData[$key] = $orderFromList[$key];
                    }
                }else{
                    throw new \Magelink\Exception\NodeException('No valid API available for forced synchronisation');
                }

                $this->storeOrderData($orderData, TRUE);

                $magelinkOrder = $this->_entityService
                    ->loadEntity($this->_nodeEntity->getNodeId(), 'order', 0, $orderIncrementId);
                if ($magelinkOrder) {
                    unset($this->notRetrievedOrderIncrementIds[$magelinkOrder->getUniqueId()]);
                }
            }

            if (count($this->notRetrievedOrderIncrementIds)) {
                $success = FALSE;
                $orderOutOfSyncList = implode(', ', $this->notRetrievedOrderIncrementIds);
                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_ERROR,
                        'forced_retrieve_failed',
                        'Retrieval failed for orders: '.$orderOutOfSyncList,
                        array(),
                        array('order increment ids still out of sync'=>$orderOutOfSyncList)
                    );
            }
        }

        return $success;
    }

    /**
     * Insert any new status history entries as entity comments
     * @param array $orderData The full order data
     * @param \Entity\Entity $orderEnt The order entity to attach to
     */
    protected function updateStatusHistory(array $orderData, \Entity\Entity $orderEntity)
    {
        $referenceIds = array();
        $commentIds = array();
        $comments = $this->_entityService->loadEntityComments($orderEntity);

        foreach($comments as $com){
            $referenceIds[] = $com->getReferenceId();
            $commentIds[] = $com->getCommentId();
        }

        foreach ($orderData['status_history'] as $historyItem) {
            if (isset($historyItem['comment']) && preg_match('/{([0-9]+)} - /', $historyItem['comment'], $matches)) {
                if(in_array($matches[1], $commentIds)){
                    continue; // Comment already loaded through another means
                }
            }
            if (in_array($historyItem['created_at'], $referenceIds)) {
                continue; // Comment already loaded
            }

            if (!isset($historyItem['comment'])) {
                $historyItem['comment'] = '(no comment)';
            }
            if (!isset($historyItem['status'])) {
                $historyItem['status'] = '(no status)';
            }
            $notifyCustomer = isset($historyItem['is_customer_notified']) && $historyItem['is_customer_notified'] == '1';

            $addCustomerComment = $orderEntity->hasAttribute('delivery_instructions')
                && !$orderEntity->getData('delivery_instructions')
                && strpos(strtolower($historyItem['comment']), Comment::CUSTOMER_COMMENT_PREFIX) === 0;

            if ($addCustomerComment) {
                $instructions = trim(substr($historyItem['comment'], strlen(Comment::CUSTOMER_COMMENT_PREFIX)));
                if (strlen($instructions)) {
                    $this->_entityService->updateEntity(
                        $this->_node->getNodeId(),
                        $orderEntity,
                        array('delivery_instructions'=>$instructions)
                    );
                }
            }
            $this->_entityService->createEntityComment(
                $orderEntity,
                'Magento',
                'Status History Event: '.$historyItem['created_at'].' - '.$historyItem['status'],
                $historyItem['comment'],
                $historyItem['created_at'],
                $notifyCustomer
            );
        }
    }

    /**
     * Create all the OrderItem entities for a given order
     * @param array $orderData
     * @param $orderId
     */
    protected function createItems(array $orderData, Order $order)
    {
        $nodeId = $this->_node->getNodeId();
        $parentId = $order->getId();

        foreach ($orderData['items'] as $item) {
            $uniqueId = $orderData['increment_id'].'-'.$item['sku'].'-'.$item['item_id'];

            $entity = $this->_entityService
                ->loadEntity(
                    $this->_node->getNodeId(),
                    'orderitem',
                    ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                    $uniqueId
                );
            if (!$entity) {
                $localId = $item['item_id'];
                $product = $this->_entityService->loadEntity(
                    $this->_node->getNodeId(),
                    'product',
                    ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                    $item['sku']
                );
                $data = array(
                    'product'=>($product ? $product->getId() : null),
                    'sku'=>$item['sku'],
                    'product_name'=>isset($item['name']) ? $item['name'] : '',
                    'is_physical'=>((isset($item['is_virtual']) && $item['is_virtual']) ? 0 : 1),
                    'product_type'=>(isset($item['product_type']) ? $item['product_type'] : null),
                    'quantity'=>$item['qty_ordered'],
                    'item_price'=>(isset($item['base_price']) ? $item['base_price'] : 0),
                    'total_price'=>(isset($item['base_row_total']) ? $item['base_row_total'] : 0),
                    'total_tax'=>(isset($item['base_tax_amount']) ? $item['base_tax_amount'] : 0),
                    'total_discount'=>(isset($item['base_discount_amount']) ? $item['base_discount_amount'] : 0),
                    'weight'=>(isset($item['row_weight']) ? $item['row_weight'] : 0),
                );

                if (isset($item['base_price_incl_tax'])) {
                    $data['item_tax'] = $item['base_price_incl_tax'] - $data['item_price'];
                }elseif ($data['total_price'] && $data['total_price'] > 0) {
                    $data['item_tax'] = ($data['total_tax'] / $data['total_price']) * $data['item_price'];
                }elseif ($data['quantity'] && $data['quantity'] > 0){
                    $data['item_tax'] = $data['total_tax'] / $data['quantity'];
                }else{
                    $data['item_tax'] = 0;
                }

                $data['item_discount'] = ($data['quantity'] ? $data['total_discount'] / $data['quantity'] : 0);

                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_INFO,
                        'dataQuantity','dataQuantity',
                        array('data'=>$data, 'dataQuantity'=>$data['quantity'])
                    );

                $storeId = ($this->_node->isMultiStore() ? $orderData['store_id'] : 0);

                $entity = $this->_entityService->createEntity(
                    $nodeId,
                    'orderitem',
                    $storeId,
                    $uniqueId,
                    $data,
                    $parentId
                );
                $this->_entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);

                $this->updateQtyPreTransit($order, $entity);
            }
        }

    }

    /**
     * Create the Address entities for a given order and pass them back as the appropraite attributes
     * @param array $orderData
     * @return array
     */
    protected function createAddresses(array $orderData)
    {
        $data = array();
        if(isset($orderData['shipping_address'])){
            $data['shipping_address'] = $this->createAddressEntity($orderData['shipping_address'], $orderData, 'shipping');
        }
        if(isset($orderData['billing_address'])){
            $data['billing_address'] = $this->createAddressEntity($orderData['billing_address'], $orderData, 'billing');
        }
        return $data;
    }

    /**
     * Creates an individual address entity (billing or shipping)
     * @param array $addressData
     * @param array $orderData
     * @param string $type "billing" or "shipping"
     * @return \Entity\Entity|null
     */
    protected function createAddressEntity(array $addressData, array $orderData, $type)
    {
        if (!array_key_exists('address_id', $addressData) || $addressData['address_id'] == NULL) {
            return NULL;
        }

        $uniqueId = 'order-'.$orderData['increment_id'].'-'.$type;

        $entity = $this->_entityService->loadEntity(
            $this->_node->getNodeId(), 'address', ($this->_node->isMultiStore() ? $orderData['store_id'] : 0), $uniqueId
        );
/*
        // DISABLED: Generally doesn't work.
        if (!$entity) {
            $entity = $this->_entityService->loadEntityLocal(
                $this->_node->getNodeId(),
                'address',
                ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                $addressData['address_id']
            );
        }
*/
        if (!$entity) {
            $data = array(
                'first_name'=>(isset($addressData['firstname']) ? $addressData['firstname'] : null),
                'last_name'=>(isset($addressData['lastname']) ? $addressData['lastname'] : null),
                'street'=>(isset($addressData['street']) ? $addressData['street'] : null),
                'city'=>(isset($addressData['city']) ? $addressData['city'] : null),
                'region'=>(isset($addressData['region']) ? $addressData['region'] : null),
                'postcode'=>(isset($addressData['postcode']) ? $addressData['postcode'] : null),
                'country_code'=>(isset($addressData['country_id']) ? $addressData['country_id'] : null),
                'telephone'=>(isset($addressData['telephone']) ? $addressData['telephone'] : null),
                'company'=>(isset($addressData['company']) ? $addressData['company'] : null)
            );

            $entity = $this->_entityService->createEntity(
                $this->_node->getNodeId(),
                'address',
                ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                $uniqueId,
                $data
            );
            $this->_entityService->linkEntity($this->_node->getNodeId(), $entity, $addressData['address_id']);
        }

        return $entity;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type=\Entity\Update::TYPE_UPDATE)
    {
        // We don't perform any direct updates to orders in this manner.
        // TODO maybe creation
        return;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        /** @var \Entity\Wrapper\Order $order */
        $order = $action->getEntity();
        // Reload order because entity might have changed in the meantime
        $order = $this->_entityService->reloadEntity($order);
        $orderStatus = $order->getData('status');

        $success = TRUE;
        switch ($action->getType()) {
            case 'comment':
                $status = ($action->hasData('status') ? $action->getData('status') : $orderStatus);
                $comment = $action->getData('comment');
                if ($comment == NULL && $action->getData('body')) {
                    if ($action->getData('title') != NULL) {
                        $comment = $action->getData('title').' - ';
                    }else{
                        $comment = '';
                    }
                    if($action->hasData('comment_id')){
                        $comment .= '{'.$action->getData('comment_id').'} ';
                    }
                    $comment .= $action->getData('body');
                }

                if ($action->hasData('customer_visible')) {
                    $notify = $action->getData('customer_visible') ? 'true' : 'false';
                }else{
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                }

                $this->_soap->call('salesOrderAddComment', array(
                        $order->getOriginalOrder()->getUniqueId(),
                        $status,
                        $comment,
                        $notify
                    ));
                break;
            case 'cancel':
                $isCancelable = (strpos($orderStatus, 'pending') === 0)
                    || in_array($orderStatus, array('payment_review', 'fraud', 'fraud_dps'));
                if ($orderStatus !== self::MAGENTO_STATUS_CANCELED) {
                    if (!$isCancelable){
                        $message = 'Attempted to cancel non-pending order '.$order->getUniqueId().' ('.$orderStatus.')';
                        throw new MagelinkException($message);
                        $success = FALSE;
                    }elseif ($order->isSegregated()){
                        throw new MagelinkException('Attempted to cancel child order '.$order->getUniqueId().' !');
                        $success = FALSE;
                    }else{
                        $this->_soap->call('salesOrderCancel', $order->getUniqueId());

                        // Update status straight away
                        $changedOrder = $this->_soap->call('salesOrderInfo', array($order->getUniqueId()));
                        if (isset($changedOrder['result'])) {
                            $changedOrder = $changedOrder['result'];
                        }

                        $newStatus = $changedOrder['status'];
                        $changedOrderData = array('status'=>$newStatus);
                        $this->_entityService->updateEntity(
                            $this->_node->getNodeId(),
                            $order,
                            $changedOrderData,
                            FALSE
                        );
                        $changedOrderData['status_history'] = array(array(
                            'comment'=>'HOPS updated status from Magento after abandoning order to '.$newStatus.'.',
                            'created_at'=>date('Y/m/d H:i:s')
                        ));
                        $this->updateStatusHistory($changedOrderData, $order);
                    }
                }
                break;
            case 'hold':
                if ($order->isSegregated()) {
                    throw new MagelinkException('Attempted to hold child order!');
                    $success = FALSE;
                }else{
                    $this->_soap->call('salesOrderHold', $order->getUniqueId());
                }
                break;
            case 'unhold':
                if ($order->isSegregated()) {
                    throw new MagelinkException('Attempted to unhold child order!');
                    $success = FALSE;
                }else{
                    $this->_soap->call('salesOrderUnhold', $order->getUniqueId());
                }
                break;
            case 'ship':
                if (self::isOrderStateProcessing($orderStatus)) {
                    $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                    $sendComment = ($action->hasData('send_comment') ?
                        ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                    $itemsShipped = ($action->hasData('items') ? $action->getData('items') : NULL);
                    $trackingCode = ($action->hasData('tracking_code') ? $action->getData('tracking_code') : NULL);

                    $this->actionShip($order, $comment, $notify, $sendComment, $itemsShipped, $trackingCode);
                }else{
                    $message = 'Invalid order status for shipment: '
                        .$order->getUniqueId().' has '.$order->getData('status');
                    throw new MagelinkException($message);
                    $success = FALSE;
                }
                break;
            case 'creditmemo':
                if (self::isOrderStateProcessing($orderStatus) || $orderStatus == self::MAGENTO_STATUS_COMPLETE) {
                    $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                    $sendComment = ($action->hasData('send_comment') ?
                        ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                    $itemsRefunded = ($action->hasData('items') ? $action->getData('items') : NULL);
                    $shippingRefund = ($action->hasData('shipping_refund') ? $action->getData('shipping_refund') : 0);
                    $creditRefund = ($action->hasData('credit_refund') ? $action->getData('credit_refund') : 0);
                    $adjustmentPositive =
                        ($action->hasData('adjustment_positive') ? $action->getData('adjustment_positive') : 0);
                    $adjustmentNegative =
                        ($action->hasData('adjustment_negative') ? $action->getData('adjustment_negative') : 0);

                    $message = 'Magento, create creditmemo: Passing values orderIncrementId '.$order->getUniqueId()
                        .'creditmemoData: [qtys=>'.var_export($itemsRefunded, TRUE).', shipping_amount=>'.$shippingRefund
                        .', adjustment_positive=>'.$adjustmentPositive.', adjustment_negative=>'.$adjustmentNegative
                        .'], comment '.$comment.', notifyCustomer '.$notify.', includeComment '.$sendComment
                        .', refundToStoreCreditAmount '.$creditRefund.'.';
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                            'mag_cr_cmemo',
                            $message,
                            array(
                                'entity (order)'=>$order,
                                'action'=>$action,
                                'action data'=>$action->getData(),
                                'orderIncrementId'=>$order->getUniqueId(),
                                'creditmemoData'=>array(
                                    'qtys'=>$itemsRefunded,
                                    'shipping_amount'=>$shippingRefund,
                                    'adjustment_positive'=>$adjustmentPositive,
                                    'adjustment_negative'=>$adjustmentNegative
                                ),
                                'comment'=>$comment,
                                'notifyCustomer'=>$notify,
                                'includeComment'=>$sendComment,
                                'refundToStoreCreditAmount'=>$creditRefund
                            )
                        );
                    $this->actionCreditmemo($order, $comment, $notify, $sendComment,
                        $itemsRefunded, $shippingRefund, $creditRefund, $adjustmentPositive, $adjustmentNegative);
                }else{
                    $message = 'Invalid order status for creditmemo: '.$order->getUniqueId().' has '.$orderStatus;
                    throw new MagelinkException($message);
                    $success = FALSE;
            }
                break;
            default:
                throw new MagelinkException('Unsupported action type '.$action->getType().' for Magento Orders.');
                $success = FALSE;
        }

        return $success;
    }

    /**
     * Preprocesses order items array (key=orderitem entity id, value=quantity) into an array suitable for Magento (local item ID=>quantity), while also auto-populating if not specified.
     *
     * @param \Entity\Entity $order
     * @param array|NULL $rawItems
     * @return array
     * @throws \Magelink\Exception\MagelinkException
     */
    protected function preprocessRequestItems(\Entity\Entity $order, $rawItems = NULL)
    {
        $items = array();
        if($rawItems == null){
            $orderItems = $this->_entityService->locateEntity(
                $this->_node->getNodeId(),
                'orderitem',
                $order->getStoreId(),
                array(
                    'PARENT_ID'=>$order->getId(),
                ),
                array(
                    'PARENT_ID'=>'eq'
                ),
                array('linked_to_node'=>$this->_node->getNodeId()),
                array('quantity')
            );
            foreach($orderItems as $oi){
                $localid = $this->_entityService->getLocalId($this->_node->getNodeId(), $oi);
                $items[$localid] = $oi->getData('quantity');
            }
        }else{
            foreach($rawItems as $eid=>$qty){
                $ie = $this->_entityService->loadEntityId($this->_node->getNodeId(), $eid);
                if($ie->getTypeStr() != 'orderitem' || $ie->getParentId() != $order->getId() || $ie->getStoreId() != $order->getStoreId()){
                    throw new MagelinkException('Invalid item '.$eid.' passed to preprocessRequestItems for order '.$order->getId());
                }
                if($qty == null){
                    $qty = $ie->getData('quantity');
                }else if($qty > $ie->getData('quantity')){
                    throw new MagelinkException('Invalid item quantity '.$qty.' for item '.$eid.' in order '.$order->getId().' - max was '.$ie->getData('quantity'));
                }
                $localid = $this->_entityService->getLocalId($this->_node->getNodeId(), $ie);
                $items[$localid] = $qty;
            }
        }
        return $items;
    }

    /**
     * Handles refunding an order in Magento
     *
     * @param \Entity\Entity $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array $itemsRefunded Array of item entity id->qty to refund, or null if automatic (all)
     * @param int $shippingRefund
     * @param int $creditRefund
     * @param int $adjustmentPositive
     * @param int $adjustmentNegative
     * @throws MagelinkException
     */
    protected function actionCreditmemo(\Entity\Entity $order, $comment='', $notify = 'false', $sendComment = 'false',
        $itemsRefunded = NULL, $shippingRefund = 0, $creditRefund = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        $items = array();

        if (count($itemsRefunded)) {
            $processItems = $itemsRefunded;
        }else{
            $processItems = array();
            foreach ($order->getOrderitems() as $orderItem) {
                $processItems[$orderItem->getId()] = 0;
            }
        }

        foreach ($this->preprocessRequestItems($order, $processItems) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }

        $creditmemoData = array(
            'qtys'=>$items,
            'shipping_amount'=>$shippingRefund,
            'adjustment_positive'=>$adjustmentPositive,
            'adjustment_negative'=>$adjustmentNegative,
        );

        $originalOrder = $order->getOriginalOrder();
        $soapResult = $this->_soap->call('salesOrderCreditmemoCreate', array(
            $originalOrder->getUniqueId(),
            $creditmemoData,
            $comment,
            $notify,
            $sendComment,
            $creditRefund
        ));

        if (is_object($soapResult)) {
            $soapResult = $soapResult->result;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['result'])) {
                $soapResult = $soapResult['result'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if(!$soapResult){
            throw new MagelinkException('Failed to get creditmemo ID from Magento for order '.$order->getUniqueId());
        }

        $this->_soap->call('salesOrderCreditmemoAddComment', array(
            $soapResult,
            'FOR ORDER: '.$order->getUniqueId(),
            false,
            false
        ));
    }

    /**
     * Handles shipping an order in Magento
     *
     * @param \Entity\Entity $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array|null $itemsShipped Array of item entity id->qty to ship, or null if automatic (all)
     * @throws \Magelink\Exception\MagelinkException
     */
    protected function actionShip(\Entity\Entity $order, $comment='', $notify='false', $sendComment='false', $itemsShipped=null, $trackingCode = null){
        $items = array();
        foreach($this->preprocessRequestItems($order, $itemsShipped) as $local=>$qty){
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }
        if(count($items) == 0){
            $items = null;
        }

        $oid = ($order->getData('original_order') != null ? $order->resolve('original_order', 'order')->getUniqueId() : $order->getUniqueId());
        $this->getServiceLocator()->get('logService')
            ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                'ship_send',
                'Sending shipment for '.$oid,
                array(
                    'ord'=>$order->getId(),
                    'items'=>$items,
                    'comment'=>$comment,
                    'notify'=>$notify,
                    'sendComment'=>$sendComment
                ),
                array('node'=>$this->_node, 'entity'=>$order)
            );

        $soapResult = $this->_soap->call('salesOrderShipmentCreate', array(
            'orderIncrementId'=>$oid,
            'itemsQty'=>$items,
            'comment'=>$comment,
            'email'=>$notify,
            'includeComment'=>$sendComment
        ));

        if (is_object($soapResult)) {
            $soapResult = $soapResult->shipmentIncrementId;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['shipmentIncrementId'])) {
                $soapResult = $soapResult['shipmentIncrementId'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }
        if(!$soapResult){
            throw new MagelinkException('Failed to get shipment ID from Magento for order ' . $order->getUniqueId());
        }
        if($trackingCode != null){
            $this->_soap->call('salesOrderShipmentAddTrack',
                array(
                    'shipmentIncrementId'=>$soapResult,
                    'carrier'=>'custom',
                    'title'=>$order->getData('shipping_method', 'Shipping'),
                    'trackNumber'=>$trackingCode)
            );
        }
    }

}