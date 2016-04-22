<?php
/**
 * Magento\Gateway\CreditmemoGateway
 *
 * @category Magento
 * @package Magento\Gateway
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Entity\Service\EntityService;
use Entity\Wrapper\Creditmemo;
use Log\Service\LogService;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;
use Node\Entity;


class CreditmemoGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'creditmemo';
    const GATEWAY_ENTITY_CODE = 'cm';

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */

    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'creditmemo') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     */
    public function retrieve()
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $this->getNewRetrieveTimestamp();
        $lastRetrieve = $this->getLastRetrieveDate();

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mag_cm_re_time',
                'Retrieving creditmemos updated since '.$lastRetrieve,
                array('type'=>'creditmemo', 'timestamp'=>$lastRetrieve)
            );

        if (FALSE && $this->_db) {
            // ToDo: Implement
        }elseif ($this->_soap) {
            try {
                $results = $this->_soap->call('salesOrderCreditmemoList', array(
                    array('complex_filter'=>array(array(
                        'key'=>'updated_at',
                        'value'=>array('key'=>'gt', 'value'=>$lastRetrieve)
                    )))
                ));
            }catch (\Exception $exception) {
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }

            foreach ($results as $creditmemo) {
                $data = array();

                try {
                    $creditmemo = $this->_soap->call('salesOrderCreditmemoInfo', array($creditmemo['increment_id']));
                }catch (\Exception $exception) {
                    // store as sync issue
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }

                if (isset($creditmemo['result'])) {
                    $creditmemo = $creditmemo['result'];
                }

                $storeId = ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0);
                $uniqueId = $creditmemo['increment_id'];
                $localId = $creditmemo['creditmemo_id'];
                $parentId = NULL;
                /** @var Creditmemo $existingEntity */
                $existingEntity = $entityService
                    ->loadEntityLocal($this->_node->getNodeId(), 'creditmemo', $storeId, $localId);

                if ($existingEntity) {
                    $noLocalId = FALSE;
                    $logLevel = LogService::LEVEL_INFO;
                    $logCode = 'mag_cm_re_upd';
                    $logMessage = 'Updated creditmemo '.$uniqueId.'.';
                }else{
                    $existingEntity = $entityService->loadEntity(
                        $this->_node->getNodeId(), 'creditmemo', $storeId, $uniqueId);

                    $noLocalId = TRUE;
                    $logLevel = LogService::LEVEL_WARN;
                    $logCode = 'mag_cm_re_updrl';
                    $logMessage = 'Updated and unlinked creditmemo '.$uniqueId.'. ';
                }
                $logData = array('creditmemo unique id'=>$uniqueId);

                $map = array(
                    'order_currency'=>'order_currency_code',
                    'status'=>'creditmemo_status',
                    'tax_amount'=>'base_tax_amount',
                    'shipping_tax'=>'base_shipping_tax_amount',
                    'subtotal'=>'base_subtotal',
                    'discount_amount'=>'base_discount_amount',
                    'shipping_amount'=>'base_shipping_amount',
                    'adjustment'=>'adjustment',
                    'adjustment_positive'=>'adjustment_positive',
                    'adjustment_negative'=>'adjustment_negative',
                    'grand_total'=>'base_grand_total',
                    'hidden_tax'=>'base_hidden_tax_amount',
                );

                if ($this->_node->getConfig('enterprise')) {
                    $map = array_merge($map, array(
                        'customer_balance'=>'base_customer_balance_amount',
                        'customer_balance_ref'=>'bs_customer_bal_total_refunded',
                        'gift_cards_amount'=>'base_gift_cards_amount',
                        'gw_price'=>'gw_base_price',
                        'gw_items_price'=>'gw_items_base_price',
                        'gw_card_price'=>'gw_card_base_price',
                        'gw_tax_amount'=>'gw_base_tax_amount',
                        'gw_items_tax_amount'=>'gw_items_base_tax_amount',
                        'gw_card_tax_amount'=>'gw_card_base_tax_amount',
                        'reward_currency_amount'=>'base_reward_currency_amount',
                        'reward_points_balance'=>'reward_points_balance',
                        'reward_points_refund'=>'reward_points_balance_refund',
                    ));
                }

                foreach ($map as $attributeCode=>$key) {
                    if (isset($creditmemo[$key])) {
                        $data[$attributeCode] = $creditmemo[$key];
                    }elseif ($existingEntity && is_null($existingEntity->getData($attributeCode))) {
                        $data[$attributeCode] = NULL;
                    }
                }

                if (isset($creditmemo['billing_address_id']) && $creditmemo['billing_address_id']) {
                    $billingAddress = $entityService->loadEntityLocal(
                        $this->_node->getNodeId(), 'address', $storeId, $creditmemo['billing_address_id']);
                    if($billingAddress && $billingAddress->getId()){
                        $data['billing_address'] = $billingAddress;
                    }else{
                        $data['billing_address'] = NULL;
                    }
                }

                if (isset($creditmemo['shipping_address_id']) && $creditmemo['shipping_address_id']) {
                    $shippingAddress = $entityService->loadEntityLocal(
                        $this->_node->getNodeId(), 'address', $storeId, $creditmemo['shipping_address_id']);
                    if($shippingAddress && $shippingAddress->getId()){
                        $data['shipping_address'] = $shippingAddress;
                    }else{
                        $data['shipping_address'] = NULL;
                    }
                }

                if (isset($creditmemo['order_id']) && $creditmemo['order_id']) {
                    $order = $entityService->loadEntityLocal(
                        $this->_node->getNodeId(), 'order', $storeId, $creditmemo['order_id']);
                    if ($order) {
                        $parentId = $order->getId();
                    }
                }

                if (isset($creditmemo['comments'])) {
                    foreach ($creditmemo['comments'] as $commentData){
                        $isOrderComment = isset($commentData['comment'])
                            && preg_match('#FOR ORDER: ([0-9]+[a-zA-Z]*)#', $commentData['comment'], $matches);
                        if ($isOrderComment) {
                            $originalOrderUniqueId = $matches[1];
                            $originalOrder = $entityService->loadEntity(
                                $this->_node->getNodeId(), 'order', $storeId, $originalOrderUniqueId);
                            if (!$order){
                                $message = 'Comment referenced order '.$originalOrderUniqueId
                                    .' on creditmemo '.$uniqueId.' but could not locate order!';
                                throw new GatewayException($message);
                            }else{
                                $parentId = $originalOrder->getId();
                            }
                        }
                    }
                }

                if ($existingEntity) {
                    if ($noLocalId) {
                        try{
                            $entityService->unlinkEntity($this->_node->getNodeId(), $existingEntity);
                        }catch(\Exception $exception) {}
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);

                        $localEntity = $entityService->loadEntityLocal(
                            $this->_node->getNodeId(), 'creditmemo', $storeId, $localId);
                        if ($localEntity) {
                            $logMessage .= 'Successfully relinked.';
                        }else{
                            $logCode .= '_err';
                            $logMessage .= 'Relinking failed.';
                        }
                    }

                    $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
                    $this->getServiceLocator()->get('logService')
                        ->log($logLevel, $logCode, $logMessage, $logData, array('creditmemo unique id'=>$uniqueId));

                    $this->createItems($creditmemo, $existingEntity->getId(), $entityService, FALSE);
                }else{
                    $entityService->beginEntityTransaction('magento-creditmemo-'.$uniqueId);
                    try{
                        $existingEntity = $entityService->createEntity(
                            $this->_node->getNodeId(), 'creditmemo', $storeId, $uniqueId, $data, $parentId);
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO, 'mag_cm_new', 'New creditmemo '.$uniqueId,
                                $logData, array('node'=>$this->_node, 'creditmemo'=>$existingEntity));

                        $this->createItems($creditmemo, $existingEntity->getId(), $entityService, TRUE);
                        $entityService->commitEntityTransaction('magento-creditmemo-'.$uniqueId);
                    }catch (\Exception $exception) {
                        $entityService->rollbackEntityTransaction('magento-creditmemo-'.$uniqueId);
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                }

                $this->updateComments($creditmemo, $existingEntity, $entityService);
            }
        }else{
            throw new NodeException('No valid API available for sync');
        }

        $this->_nodeService
            ->setTimestamp($this->_nodeEntity->getNodeId(), 'creditmemo', 'retrieve', $this->getNewRetrieveTimestamp());

        $seconds = ceil($this->getAdjustedTimestamp() - $this->getNewRetrieveTimestamp());
        $message = 'Retrieved '.count($results).' credimemos in '.$seconds.'s up to '
            .strftime('%H:%M:%S, %d/%m', $this->retrieveTimestamp).'.';
        $logData = array('type'=>'creditmemo', 'amount'=>count($results), 'period [s]'=>$seconds);
        if (count($results) > 0) {
            $logData['per entity [s]'] = round($seconds / count($results), 1);
        }
        $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_INFO, 'mag_cm_re_no', $message, $logData);
    }

    /**
     * Insert any new comment entries as entity comments
     * @param array $creditmemoData The full creditmemo data
     * @param \Entity\Entity $creditmemo The order entity to attach to
     * @param EntityService $entityService The EntityService
     */
    protected function updateComments(array $creditmemoData, \Entity\Entity $creditmemo, EntityService $entityService)
    {
        $comments = $entityService->loadEntityComments($creditmemo);
        $referenceIds = array();
        foreach ($comments as $comment) {
            $referenceIds[] = $comment->getReferenceId();
        }

        foreach ($creditmemoData['comments'] as $historyEntry) {
            if (!in_array($historyEntry['comment_id'], $referenceIds)) {
                $entityService->createEntityComment(
                    $creditmemo,
                    'Magento',
                    'Comment: '.$historyEntry['created_at'],
                    (isset($histEntry['comment']) ? $histEntry['comment'] : ''),
                    $historyEntry['comment_id'],
                    $historyEntry['is_visible_on_front']
                );
            }
        }
    }

    /**
     * Create all the Creditmemoitem entities for a given creditmemo
     * @param array $creditmemo
     * @param string $orderId
     * @param EntityService $entityService
     * @param bool $creationMode Whether this is for a newly created credit memo in magelink
     */
    protected function createItems(array $creditmemo, $orderId, EntityService $entityService, $creationMode){

        $parentId = $orderId;

        foreach ($creditmemo['items'] as $item) {
            $uniqueId = $creditmemo['increment_id'].'-'.$item['sku'].'-'.$item['item_id'];
            $localId = $item['item_id'];

            $product = $entityService->loadEntityLocal($this->_node->getNodeId(), 'product', 0, $item['product_id']);

            $parent_item = $entityService->loadEntityLocal(
                $this->_node->getNodeId(),
                'creditmemoitem',
                ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0),
                $item['parent_id']
            );

            $order_item = $entityService->loadEntityLocal(
                $this->_node->getNodeId(),
                'orderitem',
                ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0),
                $item['order_item_id']
            );

            $data = array(
                'product'=>($product ? $product->getId() : null),
                'parent_item'=>($parent_item ? $parent_item->getId() : null),
                'tax_amount'=>(isset($item['base_tax_amount']) ? $item['base_tax_amount'] : null),
                'discount_amount'=>(isset($item['base_discount_amount']) ? $item['base_discount_amount'] : null),
                'sku'=>(isset($item['sku']) ? $item['sku'] : null),
                'name'=>(isset($item['name']) ? $item['name'] : null),
                'qty'=>(isset($item['qty']) ? $item['qty'] : null),
                'row_total'=>(isset($item['base_row_total']) ? $item['base_row_total'] : null),
                'price_incl_tax'=>(isset($item['base_price_incl_tax']) ? $item['base_price_incl_tax'] : null),
                'price'=>(isset($item['base_price']) ? $item['base_price'] : null),
                'row_total_incl_tax'=>(isset($item['base_row_total_incl_tax']) ? $item['base_row_total_incl_tax'] : null),
                'additional_data'=>(isset($item['additional_data']) ? $item['additional_data'] : null),
                'description'=>(isset($item['description']) ? $item['description'] : null),
                'hidden_tax_amount'=>(isset($item['base_hidden_tax_amount']) ? $item['base_hidden_tax_amount'] : null),
            );

            $entity = $entityService->loadEntity(
                $this->_node->getNodeId(),
                'creditmemoitem',
                ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0),
                $uniqueId
            );
            if (!$entity){
                $logLevel = ($creationMode ? LogService::LEVEL_INFO : LogService::LEVEL_WARN);
                $this->getServiceLocator()->get('logService')
                    ->log($logLevel,
                        'mag_cmi_new',
                        'New creditmemo item '.$uniqueId.' : '.$localId,
                        array('uniq'=>$uniqueId, 'local'=>$localId),
                        array('node'=>$this->_node, 'entity'=>$entity)
                    );
                $data['order_item'] = ($order_item ? $order_item->getId() : NULL);
                $entity = $entityService->createEntity(
                    $this->_node->getNodeId(),
                    'creditmemoitem',
                    ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0),
                    $uniqueId,
                    $data,
                    $parentId
                );
                $entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);
            }else{
                $entityService->updateEntity($this->_node->getNodeId(), $entity, $data);
            }
        }
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     * @throws GatewayException
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        switch ($type) {
            case \Entity\Update::TYPE_UPDATE:
                // We don't update, ever
                break;
            case \Entity\Update::TYPE_DELETE:
                try {
                    $this->_soap->call('salesOrderCreditmemoCancel', array($entity->getUniqueId()));
                }catch (\Exception $exception) {
                    // store as sync issue
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }
                break;
            case \Entity\Update::TYPE_CREATE:
                /** @var \Entity\Service\EntityService $entityService */
                $entityService = $this->getServiceLocator()->get('entityService');

                $order = $entity->getParent();
                $originalOrder = $entity->getOriginalParent();
                if (!$order || $order->getTypeStr() != 'order') {
                    // store as sync issue
                    throw new GatewayException('Creditmemo parent not correctly set for creditmemo '.$entity->getId());
                }elseif (!$originalOrder || $originalOrder->getTypeStr() != 'order') {
                    $message = 'Creditmemo root parent not correctly set for creditmemo '.$entity->getId();
                    // store as sync issue
                    throw new GatewayException($message);
                }else{
                    /** @var \Entity\Entity[] $items */
                    $items = $entity->getItems();
                    if (!count($items)) {
                        $items = $originalOrder->getOrderitems();
                    }

                    $itemData = array();
                    foreach ($items as $item) {
                        switch ($item->getTypeStr()) {
                            case 'creditmemoitem':
                                $orderItemId = $item->getData('order_item');
                                $qty = $item->getData('qty', 0);
                                break;
                            case 'orderitem':
                                $orderItemId = $item->getId();
                                $qty = 0;
                                break;
                            default:
                                $message = 'Wrong type of the children of creditmemo '.$entity->getUniqueId().'.';
                                // store as sync issue
                                throw new GatewayException($message);
                        }

                        $itemLocalId = $entityService->getLocalId($this->_node->getNodeId(), $orderItemId);
                        if (!$itemLocalId) {
                            $message = 'Invalid order item local ID for creditmemo item '.$item->getUniqueId()
                                .' and creditmemo '.$entity->getUniqueId().' (orderitem '.$item->getData(
                                    'order_item'
                                ).')';
                            // store as sync issue
                            throw new GatewayException($message);
                        }
                        $itemData[] = array('order_item_id'=>$itemLocalId, 'qty'=>$qty);
                    }


                    $creditmemoData = array(
                        'qtys' => $itemData,
                        'shipping_amount' => $entity->getData('shipping_amount', 0),
                        'adjustment_positive' => $entity->getData('adjustment_positive', 0),
                        'adjustment_negative' => $entity->getData('adjustment_negative', 0)
                    );

                    try {
                        // Adjustment because of the conversion in Mage_Sales_Model_Order_Creditmemo_Api:165 (rounding issues likely)
                        $storeCreditRefundAdjusted = $entity->getData('customer_balance_ref', 0)
                            / $originalOrder->getData('base_to_currency_rate', 1);
                        $soapResult = $this->_soap->call(
                            'salesOrderCreditmemoCreate',
                            array(
                                $originalOrder->getUniqueId(),
                                $creditmemoData,
                                '',
                                false,
                                false,
                                $storeCreditRefundAdjusted
                            )
                        );
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }

                    if (is_object($soapResult)) {
                        $soapResult = $soapResult->result;
                    }elseif (is_array($soapResult)) {
                        if (isset($soapResult['result'])) {
                            $soapResult = $soapResult['result'];
                        }else {
                            $soapResult = array_shift($soapResult);
                        }
                    }

                    if (!$soapResult) {
                        $message = 'Failed to get creditmemo ID from Magento for order '.$originalOrder->getUniqueId()
                            .' (Hops order '.$order->getUniqueId().').';
                        // store as sync issue
                        throw new GatewayException($message);
                    }
                    $entityService->updateEntityUnique($this->_node->getNodeId(), $entity, $soapResult);

                    try {
                        $creditmemo = $this->_soap->call('salesOrderCreditmemoInfo', array($soapResult));
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }

                    if (isset($creditmemo['result'])) {
                        $creditmemo = $creditmemo['result'];
                    }
                    $localId = $creditmemo['creditmemo_id'];

                    try{
                        $entityService->unlinkEntity($this->_node->getNodeId(), $entity);
                    }catch (\Exception $exception) {} // Ignore errors

                    $entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);

                    // Update credit memo item local and unique IDs
                    foreach ($creditmemo['items'] as $item) {
                        foreach ($items as $itemEntity) {
                            $isItemSkuAndQtyTheSame = $itemEntity->getData('sku') == $item['sku']
                                && $itemEntity->getData('qty') == $item['qty'];
                            if ($isItemSkuAndQtyTheSame) {
                                $entityService->updateEntityUnique(
                                    $this->_node->getNodeId(),
                                    $itemEntity,
                                    $creditmemo['increment_id'].'-'.$item['sku'].'-'.$item['item_id']
                                );

                                try{
                                    $entityService->unlinkEntity($this->_node->getNodeId(), $itemEntity);
                                }catch (\Exception $exception) {} // Ignore errors

                                $entityService->linkEntity($this->_node->getNodeId(), $itemEntity, $item['item_id']);
                                break;
                            }
                        }
                    }

                    try {
                        $this->_soap->call(
                            'salesOrderCreditmemoAddComment',
                            array($soapResult, 'FOR ORDER: '.$order->getUniqueId(), FALSE, FALSE)
                        );
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                }

                break;
            default:
                throw new GatewayException('Invalid update type '.$type);
        }
        return;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws GatewayException
     */
    public function writeAction(\Entity\Action $action)
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $entity = $action->getEntity();

        $success = FALSE;
        if (stripos($entity->getUniqueId(), 'TMP-') === 0) {
            // Hold off for now
        }else {
            switch ($action->getType()) {
                case 'comment':
                    $comment = $action->getData('comment');
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false') : NULL);
                    $includeComment = ($action->hasData('includeComment')
                        ? ($action->getData('includeComment') ? 'true' : 'false') : NULL);

                    try{
                        $this->_soap->call(
                            'salesOrderCreditmemoAddComment',
                            array(
                                $entity->getUniqueId(),
                                $comment,
                                $notify,
                                $includeComment
                            )
                        );
                        $success = TRUE;
                    }catch( \Exception $exception ){
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                    break;
                case 'cancel':
                    try {
                        $this->_soap->call('salesOrderCreditmemoCancel', $entity->getUniqueId());
                        $success = TRUE;
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                    break;
                default:
                    $message = 'Unsupported action type '.$action->getType().' for Magento Credit Memos.';
                    throw new GatewayException($message);
            }
        }

        return $success;
    }

}
