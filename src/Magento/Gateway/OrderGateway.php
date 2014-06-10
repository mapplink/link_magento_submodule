<?php

namespace Magento\Gateway;

use Node\AbstractNode;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Entity\Service\EntityService;

class OrderGateway extends AbstractGateway
{
    /**
     * @var \Magento\Node
     */
    protected $_node;

    /**
     * @var \Node\Entity\Node
     */
    protected $_nodeEnt;

    /**
     * @var \Magento\Api\Soap
     */
    protected $_soap = null;
    /**
     * @var \Magento\Api\Db
     */
    protected $_db = null;

    /**
     * @var \Node\Service\NodeService
     */
    protected $_ns = null;

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param AbstractNode $node
     * @param Entity\Node $nodeEntity
     * @param string $entity_type
     * @throws \Magelink\Exception\MagelinkException
     * @return boolean
     */
    public function init(AbstractNode $node, Entity\Node $nodeEntity, $entity_type)
    {
        if(!($node instanceof \Magento\Node)){
            throw new \Magelink\Exception\MagelinkException('Invalid node type for this gateway');
        }
        if($entity_type != 'order'){
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
        }

        $this->_node = $node;
        $this->_nodeEnt = $nodeEntity;

        $this->_soap = $node->getApi('soap');
        if(!$this->_soap){
            throw new MagelinkException('SOAP is required for Magento Orders');
        }
        $this->_db = $node->getApi('db');

        $this->_ns = $this->getServiceLocator()->get('nodeService');

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

        $timestamp = time();

        if($this->_db && false){
            // TODO: Implement]
        }else if($this->_soap){
            $results = $this->_soap->call('salesOrderList', array(
                array(
                    'complex_filter'=>array(
                        array(
                            'key'=>'updated_at',
                            'value'=>array('key'=>'gt', 'value'=>date('Y-m-d H:i:s', $this->_ns->getTimestamp($this->_nodeEnt->getNodeId(), 'order', 'retrieve') + (intval($this->_node->getConfig('time_delta_order')) * 3600))),
                        ),
                    ),
                ), // filters
            ));

            foreach ($results as $orderFromList) {

                $order = $this->_soap->call('salesOrderInfo', array($orderFromList['increment_id']));
                if (isset($order['result'])) {
                    $order = $order['result'];
                }
                // Inserting missing fields from salesOrderList in the salesOrderInfo array
                foreach(array_diff(array_keys($orderFromList), array_keys($order)) as $key){
                    $order[$key] = $orderFromList[$key];
                }

                $store_id = ($this->_node->isMultiStore() ? $order['store_id'] : 0);
                $unique_id = $order['increment_id'];
                $local_id = $order['order_id'];

                $data = array(
                    'customer_email'=> $order['customer_email'],
                    'customer_name'=>$order['customer_firstname'].' '.$order['customer_lastname'],
                    'status'=>$order['status'],
                    'placed_at'=>$order['created_at'],
                    'grand_total'=>$order['grand_total'],
                    'weight_total'=>(array_key_exists('weight', $order) ? $order['weight'] : 0),
                    'discount_total'=>(array_key_exists('discount_amount', $order) ? $order['discount_amount'] : 0),
                    'shipping_total'=>(array_key_exists('shipping_amount', $order) ? $order['shipping_amount'] : 0),
                    'tax_total'=>(array_key_exists('tax_amount', $order) ? $order['tax_amount'] : 0),
                    'shipping_method'=>(array_key_exists('shipping_method', $order) ? $order['shipping_method'] : null)
                );
                if (array_key_exists('base_gift_cards_amount', $order)) {
                    $data['giftcard_total'] = $order['base_gift_cards_amount'];
                }elseif (array_key_exists('base_gift_cards_amount_invoiced', $order)) {
                    $data['giftcard_total'] = $order['base_gift_cards_amount_invoiced'];
                }else{
                    $data['giftcard_total'] = 0;
                }
                if (array_key_exists('base_reward_currency_amount', $order)) {
                    $data['reward_total'] = $order['base_reward_currency_amount'];
                }elseif (array_key_exists('base_reward_currency_amount_invoiced', $order)) {
                    $data['reward_total'] = $order['base_reward_currency_amount_invoiced'];
                }else{
                    $data['reward_total'] = 0;
                }
                if (array_key_exists('base_customer_balance_amount', $order)) {
                    $data['storecredit_total'] = $order['base_customer_balance_amount'];
                }elseif (array_key_exists('base_customer_balance_amount_invoiced', $order)) {
                    $data['storecredit_total'] = $order['base_customer_balance_amount_invoiced'];
                }else{
                    $data['storecredit_total'] = 0;
                }

                $payments = array();
                if (isset($order['payment'])) {
                    if (is_array($order['payment']) && !isset($order['payment']['payment_id'])) {
                        foreach ($order['payment'] as $payment) {
                            $methodExtended = $payment['method']
                                .($payment['cc_type'] ? '{{'.$payment['cc_type'].'}}' : '');
                            $payments[$methodExtended] = $payment['amount_ordered'];
                        }
                    }elseif (isset($order['payment']['payment_id'])) {
                        $methodExtended = $order['payment']['method']
                            .(isset($order['payment']['cc_type']) && $order['payment']['cc_type']
                                ? '{{'.$order['payment']['cc_type'].'}}' : '');
                        $payments[$methodExtended] = $order['payment']['amount_ordered'];
                    }else{
                        throw new MagelinkException('Invalid payment details format for order '.$unique_id);
                    }
                }
                if(count($payments)){
                    $data['payment_method'] = $payments;
                }

                if(isset($order['customer_id']) && $order['customer_id']){
                    $cust = $entityService
                        ->loadEntityLocal($this->_node->getNodeId(), 'customer', $store_id, $order['customer_id']);
                    //$cust = $entityService->loadEntity($this->_node->getNodeId(), 'customer', $store_id, $order['customer_email'])
                    if($cust && $cust->getId()){
                        $data['customer'] = $cust;
                    }else{
                        $data['customer'] = null;
                    }
                }

                /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                $needsUpdate = true;

                $existingEntity = $entityService->loadEntityLocal(
                    $this->_node->getNodeId(),
                    'order',
                    $store_id,
                    $local_id
                );
                if(!$existingEntity){
                    $existingEntity = $entityService->loadEntity(
                        $this->_node->getNodeId(),
                        'order',
                        $store_id,
                        $unique_id
                    );
                    if(!$existingEntity){
                        $entityService->beginEntityTransaction('magento-order-'.$unique_id);
                        try{
                            $data = array_merge(
                                $this->createAddresses($order, $entityService),
                                $data
                            );
                            $existingEntity = $entityService->createEntity(
                                $this->_node->getNodeId(),
                                'order',
                                $store_id,
                                $unique_id,
                                $data,
                                NULL
                            );
                            $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);

                            $this->getServiceLocator()->get('logService')
                                ->log(\Log\Service\LogService::LEVEL_INFO,
                                    'ent_new', 'New order '.$unique_id,
                                    array('sku'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );

                            $this->createItems($order, $existingEntity->getId(), $entityService);

                            try{
                                $this->_soap->call('salesOrderAddComment',
                                        array(
                                            $unique_id,
                                            $existingEntity->getData('status'),
                                            'Order retrieved by MageLink, Entity #'.$existingEntity->getId(),
                                            FALSE
                                        )
                                    );
                            }catch (\Exception $e) {
                                $this->getServiceLocator()->get('logService')
                                    ->log(\Log\Service\LogService::LEVEL_ERROR,
                                        'ent_comment_err',
                                        'Failed to write comment on order '.$unique_id,
                                        array(),
                                        array('node'=>$this->_node, 'entity'=>$existingEntity)
                                    );
                            }
                            $entityService->commitEntityTransaction('magento-order-'.$unique_id);
                        }catch(\Exception $e){
                            $entityService->rollbackEntityTransaction('magento-order-'.$unique_id);
                            throw $e;
                        }
                        $needsUpdate = FALSE;
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(\Log\Service\LogService::LEVEL_WARN,
                                'ent_link',
                                'Unlinked order '.$unique_id,
                                array('sku'=>$unique_id),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                    }
                }else{
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_INFO,
                            'ent_update',
                            'Updated order '.$unique_id,
                            array('sku'=>$unique_id),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );
                }

                if($needsUpdate){
                    $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
                }
                $this->updateStatusHistory($order, $existingEntity, $entityService);
            }
        }else{
            // Nothing worked
            throw new \Magelink\Exception\NodeException('No valid API available for sync');
        }
        $this->_ns->setTimestamp($this->_nodeEnt->getNodeId(), 'order', 'retrieve', $timestamp);
    }

    /**
     * Insert any new status history entries as entity comments
     * @param array $order The full order data
     * @param \Entity\Entity $orderEnt The order entity to attach to
     * @param EntityService $es The EntityService
     */
    protected function updateStatusHistory($order, \Entity\Entity $orderEnt, EntityService $es){
        $comments = $es->loadEntityComments($orderEnt);
        $referenceIds = array();
        $commentIds = array();
        foreach($comments as $com){
            $referenceIds[] = $com->getReferenceId();
            $commentIds[] = $com->getCommentId();
        }
        foreach($order['status_history'] as $histEntry){
            if(isset($histEntry['comment']) && preg_match('/{([0-9]+)} - /', $histEntry['comment'], $matches)){
                if(in_array($matches[1], $commentIds)){
                    continue; // Comment already loaded through another means
                }
            }
            if(in_array($histEntry['created_at'], $referenceIds)){
                continue; // Comment already loaded
            }
            if($orderEnt->hasAttribute('delivery_instructions') && !$orderEnt->getData('delivery_instructions') && strpos($histEntry['comment'], 'Comment by customer: ') === 0){
                $instructions = trim(substr($histEntry['comment'], strlen('Comment by customer: ')));
                if(strlen($instructions)){
                    $es->updateEntity($this->_node->getNodeId(), $orderEnt, array('delivery_instructions'=>$instructions));
                }
            }
            $es->createEntityComment(
                $orderEnt,
                'Magento',
                'Status History Event: ' . $histEntry['created_at'] . ' - ' . $histEntry['status'],
                (isset($histEntry['comment']) ? $histEntry['comment'] : '(no comment)'), $histEntry['created_at'],
                (isset($histEntry['is_customer_notified']) && $histEntry['is_customer_notified']=='1')
            );
        }
    }

    /**
     * Create all the OrderItem entities for a given order
     * @param $order
     * @param $oid
     * @param EntityService $es
     */
    protected function createItems($order, $oid, EntityService $es)
    {
        $parent_id = $oid;

        foreach($order['items'] as $item){
            $unique_id = $order['increment_id'].'-'.$item['sku'].'-'.$item['item_id'];

            $e = $es->loadEntity($this->_node->getNodeId(), 'orderitem', ($this->_node->isMultiStore() ? $order['store_id'] : 0), $unique_id);
            if(!$e){
                $local_id = $item['item_id'];
                $product = $es->loadEntity($this->_node->getNodeId(), 'product', ($this->_node->isMultiStore() ? $order['store_id'] : 0), $item['sku']);
                $data = array(
                    'product'=>($product ? $product->getId() : null),
                    'sku'=>$item['sku'],
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

                $e = $es->createEntity($this->_node->getNodeId(), 'orderitem', ($this->_node->isMultiStore() ? $order['store_id'] : 0), $unique_id, $data, $parent_id);
                $es->linkEntity($this->_node->getNodeId(), $e, $local_id);
            }
        }

    }

    /**
     * Create the Address entities for a given order and pass them back as the appropraite attributes
     * @param $order
     * @param EntityService $es
     * @return array
     */
    protected function createAddresses($order, EntityService $es){
        $data = array();
        if(isset($order['shipping_address'])){
            $data['shipping_address'] = $this->createAddressEntity($order['shipping_address'], $order, 'shipping', $es);
        }
        if(isset($order['billing_address'])){
            $data['billing_address'] = $this->createAddressEntity($order['billing_address'], $order, 'billing', $es);
        }
        return $data;
    }

    /**
     * Creates an individual address entity (billing or shipping)
     * @param array $addressData
     * @param array $order
     * @param string $type "billing" or "shipping"
     * @param EntityService $es
     * @return \Entity\Entity|null
     */
    protected function createAddressEntity($addressData, $order, $type, EntityService $es){

        if(!array_key_exists('address_id', $addressData) || $addressData['address_id'] == null){
            return null;
        }

        $unique_id = 'order-'.$order['increment_id'].'-'.$type;

        $e = $es->loadEntity($this->_node->getNodeId(), 'address', ($this->_node->isMultiStore() ? $order['store_id'] : 0), $unique_id);
        // DISABLED: Generally doesn't work.
        //if(!$e){
        //    $e = $es->loadEntityLocal($this->_node->getNodeId(), 'address', ($this->_node->isMultiStore() ? $order['store_id'] : 0), $addressData['address_id']);
        //}

        if(!$e){
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

            $e = $es->createEntity($this->_node->getNodeId(), 'address', ($this->_node->isMultiStore() ? $order['store_id'] : 0), $unique_id, $data);
            $es->linkEntity($this->_node->getNodeId(), $e, $addressData['address_id']);
        }
        return $e;
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
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $entity = $action->getEntity();

        switch($action->getType()){
            case 'comment':
                $status = ($action->hasData('status') ? $action->getData('status') : $entity->getData('status'));
                $comment = $action->getData('comment');
                if($comment == null && $action->getData('body')){
                    if($action->getData('title') != null){
                        $comment = $action->getData('title').' - ';
                    }else{
                        $comment = '';
                    }
                    if($action->hasData('comment_id')){
                        $comment .= '{'.$action->getData('comment_id').'} ';
                    }
                    $comment .= $action->getData('body');
                }
                if($action->hasData('customer_visible')){
                    $notify = $action->getData('customer_visible') ? 'true' : 'false';
                }else{
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : null);
                }
                $this->_soap->call('salesOrderAddComment', array(
                    ($entity->getData('original_order') != null ? $entity->resolve('original_order', 'order')->getUniqueId() : $entity->getUniqueId()),
                    $status,
                    $comment,
                    $notify
                ));
                return true;
                break;
            case 'cancel':
                if(!in_array($entity->getData('status'), array('pending', 'pending_dps', 'pending_ogone', 'pending_payment', 'payment_review', 'fraud', 'fraud_dps', 'pending_paypal'))){
                    throw new MagelinkException('Attempted to cancel non-pending order ' . $entity->getUniqueId() . ' (' . $entity->getData('status') . ')');
                }
                if($entity->getData('original_order') != null){
                    throw new MagelinkException('Attempted to cancel child order!');
                }
                $this->_soap->call('salesOrderCancel', $entity->getUniqueId());
                return true;
                break;
            case 'hold':
                if($entity->getData('original_order') != null){
                    throw new MagelinkException('Attempted to hold child order!');
                }
                $this->_soap->call('salesOrderHold', $entity->getUniqueId());
                return true;
                break;
            case 'unhold':
                if($entity->getData('original_order') != null){
                    throw new MagelinkException('Attempted to unhold child order!');
                }
                $this->_soap->call('salesOrderUnhold', $entity->getUniqueId());
                return true;
                break;
            case 'ship':
                if($entity->getData('status') != 'processing'){
                    throw new MagelinkException('Invalid order status for shipment: ' . $entity->getUniqueId() . ' has ' . $entity->getData('status'));
                }
                $comment = ($action->hasData('comment') ? $action->getData('comment') : null);
                $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : null);
                $sendComment = ($action->hasData('send_comment') ? ($action->getData('send_comment') ? 'true' : 'false' ) : null);
                $itemsShipped = ($action->hasData('items') ? $action->getData('items') : null);
                $trackingCode = ($action->hasData('tracking_code') ? $action->getData('tracking_code') : null);
                $this->actionShip($entity, $comment, $notify, $sendComment, $itemsShipped, $trackingCode);
                return true;
                break;
            case 'creditmemo':
                if($entity->getData('status') != 'processing' && $entity->getData('status') != 'complete'){
                    throw new MagelinkException('Invalid order status for creditmemo: ' . $entity->getUniqueId() . ' has ' . $entity->getData('status'));
                }
                $comment = ($action->hasData('comment') ? $action->getData('comment') : null);
                $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : null);
                $sendComment = ($action->hasData('send_comment') ? ($action->getData('send_comment') ? 'true' : 'false' ) : null);
                $itemsRefunded = ($action->hasData('items') ? $action->getData('items') : null);
                $shipping_refund = ($action->hasData('shipping_refund') ? $action->getData('shipping_refund') : 0);
                $credit_refund = ($action->hasData('credit_refund') ? $action->getData('credit_refund') : 0);
                $adjustment_positive = ($action->hasData('adjustment_positive') ? $action->getData('adjustment_positive') : 0);
                $adjustment_negative = ($action->hasData('adjustment_negative') ? $action->getData('adjustment_negative') : 0);

                $message = 'Magento, create creditmemo: Passing values orderIncrementId '.$entity->getUniqueId()
                    .'creditmemoData: [qtys=>'.var_export($itemsRefunded, TRUE).', shipping_amount=>'.$shipping_refund
                    .', adjustment_positive=>'.$adjustment_positive.', adjustment_negative=>'.$adjustment_negative
                    .'], comment '.$comment.', notifyCustomer '.$notify.', includeComment '.$sendComment
                    .', refundToStoreCreditAmount '.$credit_refund.'.';
                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                        'mag_cr_cmemo',
                        $message,
                        array(
                            'entity (order)' => $entity,
                            'action' => $action,
                            'action data' => $action->getData(),
                            'orderIncrementId' => $entity->getUniqueId(),
                            'creditmemoData' => array(
                                'qtys' => $itemsRefunded,
                                'shipping_amount' => $shipping_refund,
                                'adjustment_positive' => $adjustment_positive,
                                'adjustment_negative' => $adjustment_negative
                            ),
                            'comment' => $comment,
                            'notifyCustomer' => $notify,
                            'includeComment' => $sendComment,
                            'refundToStoreCreditAmount' => $credit_refund
                        )
                    );
                $this->actionCreditmemo($entity, $comment, $notify, $sendComment,
                    $itemsRefunded, $shipping_refund, $credit_refund, $adjustment_positive, $adjustment_negative);
                return true;
                break;
            default:
                throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Magento Orders.');
        }
    }

    /**
     * Preprocesses order items array (key=orderitem entity id, value=quantity) into an array suitable for Magento (local item ID=>quantity), while also auto-populating if not specified.
     *
     * @param \Entity\Entity $order
     * @param array $rawItems
     * @return array
     * @throws \Magelink\Exception\MagelinkException
     */
    protected function preprocessRequestItems(\Entity\Entity $order, $rawItems=null){
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');

        $items = array();
        if($rawItems == null){
            $orderItems = $entityService->locateEntity($this->_node->getNodeId(), 'orderitem', $order->getStoreId(),
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
                $localid = $entityService->getLocalId($this->_node->getNodeId(), $oi);
                $items[$localid] = $oi->getData('quantity');
            }
        }else{
            foreach($rawItems as $eid=>$qty){
                $ie = $entityService->loadEntityId($this->_node->getNodeId(), $eid);
                if($ie->getTypeStr() != 'orderitem' || $ie->getParentId() != $order->getId() || $ie->getStoreId() != $order->getStoreId()){
                    throw new MagelinkException('Invalid item ' . $eid . ' passed to preprocessRequestItems for order ' . $order->getId());
                }
                if($qty == null){
                    $qty = $ie->getData('quantity');
                }else if($qty > $ie->getData('quantity')){
                    throw new MagelinkException('Invalid item quantity ' . $qty . ' for item ' . $eid . ' in order ' . $order->getId() . ' - max was ' . $ie->getData('quantity'));
                }
                $localid = $entityService->getLocalId($this->_node->getNodeId(), $ie);
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
     */
    protected function actionCreditmemo(\Entity\Entity $order, $comment='', $notify = 'false', $sendComment = 'false',
        $itemsRefunded = NULL, $shippingRefund = 0, $creditRefund = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        $items = array();
        foreach($this->preprocessRequestItems($order, $itemsRefunded) as $local=>$qty){
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }

        $res = $this->_soap->call('salesOrderCreditmemoCreate', array(
            ($order->getData('original_order') != null ? $order->resolve('original_order', 'order')->getUniqueId() : $order->getUniqueId()),
            array(
                'qtys'=>$items,
                'shipping_amount'=>$shippingRefund,
                'adjustment_positive'=>$adjustmentPositive,
                'adjustment_negative'=>$adjustmentNegative,
            ),
            $comment,
            $notify,
            $sendComment,
            $creditRefund
        ));
        if(is_object($res)){
            $res = $res->result;
        }else if(is_array($res)){
            if(isset($res['result'])){
                $res = $res['result'];
            }else{
                $res = array_shift($res);
            }
        }
        if(!$res){
            throw new MagelinkException('Failed to get creditmemo ID from Magento for order ' . $order->getUniqueId());
        }
        $this->_soap->call('salesOrderCreditmemoAddComment', array(
            $res,
            'FOR ORDER: ' . $order->getUniqueId(),
            false,
            false
        ));
        $this->_node->retrieve(array('creditmemo'));
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
        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA, 'ship_send', 'Sending shipment for ' . $oid, array('ord'=>$order->getId(), 'items'=>$items, 'comment'=>$comment, 'notify'=>$notify, 'sendComment'=>$sendComment), array('node'=>$this->_node, 'entity'=>$order));

        $res = $this->_soap->call('salesOrderShipmentCreate', array(
            'orderIncrementId'=>$oid,
            'itemsQty'=>$items,
            'comment'=>$comment,
            'email'=>$notify,
            'includeComment'=>$sendComment
        ));
        if(is_object($res)){
            $res = $res->shipmentIncrementId;
        }else if(is_array($res)){
            if(isset($res['shipmentIncrementId'])){
                $res = $res['shipmentIncrementId'];
            }else{
                $res = array_shift($res);
            }
        }
        if(!$res){
            throw new MagelinkException('Failed to get shipment ID from Magento for order ' . $order->getUniqueId());
        }
        if($trackingCode != null){
            $this->_soap->call('salesOrderShipmentAddTrack', array('shipmentIncrementId'=>$res, 'carrier'=>'custom', 'title'=>$order->getData('shipping_method', 'Shipping'), 'trackNumber'=>$trackingCode));
        }
    }

}