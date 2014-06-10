<?php

namespace Magento\Gateway;

use Node\AbstractNode;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Entity\Service\EntityService;

class CreditmemoGateway extends AbstractGateway {

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
        if($entity_type != 'creditmemo'){
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
        }

        $this->_node = $node;
        $this->_nodeEnt = $nodeEntity;

        $this->_soap = $node->getApi('soap');
        if(!$this->_soap){
            throw new MagelinkException('SOAP is required for Magento Creditmemos');
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

            $results = $this->_soap->call('salesOrderCreditmemoList', array(
                array(
                    'complex_filter'=>array(
                        array(
                            'key'=>'updated_at',
                            'value'=>array('key'=>'gt', 'value'=>date('Y-m-d H:i:s', $this->_ns->getTimestamp($this->_nodeEnt->getNodeId(), 'creditmemo', 'retrieve') + (intval($this->_node->getConfig('time_delta_creditmemo')) * 3600))),
                        ),
                    ),
                ), // filters
            ));

            foreach($results as $creditmemo){

                $creditmemo = $this->_soap->call('salesOrderCreditmemoInfo', array($creditmemo['increment_id']));
                if(isset($creditmemo['result'])){
                    $creditmemo = $creditmemo['result'];
                }

                $store_id = ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0);
                $unique_id = $creditmemo['increment_id'];
                $local_id = $creditmemo['creditmemo_id'];
                $parent_id = null;

                $map = array(
                    'order_currency'=>'order_currency_code',
                    'status'=>'creditmemo_status',
                    'tax_amount'=>'tax_amount',
                    'shipping_tax'=>'shipping_tax_amount',
                    'subtotal'=>'subtotal',
                    'discount_amount'=>'discount_amount',
                    'shipping_amount'=>'shipping_amount',
                    'adjustment'=>'adjustment',
                    'adjustment_positive'=>'adjustment_positive',
                    'adjustment_negative'=>'adjustment_negative',
                    'grand_total'=>'grand_total',
                    'hidden_tax'=>'hidden_tax_amount',
                );
                if($this->_node->getConfig('enterprise')){
                    $map = array_merge($map, array(
                        'customer_balance'=>'customer_balance_amount',
                        'customer_balance_ref'=>'customer_bal_total_refunded',
                        'gift_cards_amount'=>'gift_cards_amount',
                        'gw_price'=>'gw_price',
                        'gw_items_price'=>'gw_items_price',
                        'gw_card_price'=>'gw_card_price',
                        'gw_tax_amount'=>'gw_tax_amount',
                        'gw_items_tax_amount'=>'gw_items_tax_amount',
                        'gw_card_tax_amount'=>'gw_card_tax_amount',
                        'reward_currency_amount'=>'reward_currency_amount',
                        'reward_points_balance'=>'reward_points_balance',
                        'reward_points_refund'=>'reward_points_balance_refund',
                    ));
                }

                foreach($map as $att=>$key){
                    if(isset($creditmemo[$key])){
                        $data[$att] = $creditmemo[$key];
                    }else{
                        $data[$att] = null;
                    }
                }

                /**
                if(isset($creditmemo['invoice_id']) && $creditmemo['invoice_id']){
                    $ent = $entityService->loadEntityLocal($this->_node->getNodeId(), 'invoice', $store_id, $creditmemo['invoice_id']);
                    if($ent && $ent->getId()){
                        $data['invoice'] = $ent;
                    }else{
                        $data['invoice'] = null;
                    }
                }
                if(isset($creditmemo['billing_address_id']) && $creditmemo['billing_address_id']){
                    $ent = $entityService->loadEntityLocal($this->_node->getNodeId(), 'address', $store_id, $creditmemo['billing_address_id']);
                    if($ent && $ent->getId()){
                        $data['billing_address'] = $ent;
                    }else{
                        $data['billing_address'] = null;
                    }
                }
                if(isset($creditmemo['shipping_address_id']) && $creditmemo['shipping_address_id']){
                    $ent = $entityService->loadEntityLocal($this->_node->getNodeId(), 'address', $store_id, $creditmemo['shipping_address_id']);
                    if($ent && $ent->getId()){
                        $data['shipping_address'] = $ent;
                    }else{
                        $data['shipping_address'] = null;
                    }
                }*/
                if(isset($creditmemo['order_id']) && $creditmemo['order_id']){
                    $ent = $entityService->loadEntityLocal($this->_node->getNodeId(), 'order', $store_id, $creditmemo['order_id']);
                    if($ent){
                        $parent_id = $ent->getId();
                    }
                }
                if(isset($creditmemo['comments'])){
                    foreach($creditmemo['comments'] as $com){
                        if(preg_match('/FOR ORDER: ([0-9]+[a-zA-Z]*)/', $com['comment'], $matches)){
                            $ent = $entityService->loadEntity($this->_node->getNodeId(), 'order', $store_id, $matches[1]);
                            if(!$ent){
                                throw new MagelinkException('Comment referenced order ' . $matches[1] . ' on cm ' . $unique_id . ' but could not locate order!');
                            }else{
                                $parent_id = $ent->getId();
                            }
                        }
                    }
                }

                /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                $needsUpdate = true;

                $existingEntity = $entityService->loadEntityLocal($this->_node->getNodeId(), 'creditmemo', $store_id, $local_id);
                if(!$existingEntity){
                    $existingEntity = $entityService->loadEntity($this->_node->getNodeId(), 'creditmemo', $store_id, $unique_id);
                    if(!$existingEntity){
                        $entityService->beginEntityTransaction('magento-creditmemo-'.$unique_id);
                        try{
                            $existingEntity = $entityService->createEntity($this->_node->getNodeId(), 'creditmemo', $store_id, $unique_id, $data, $parent_id);
                            $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_INFO, 'ent_new', 'New creditmemo ' . $unique_id, array('sku'=>$unique_id), array('node'=>$this->_node, 'entity'=>$existingEntity));
                            $this->createItems($creditmemo, $existingEntity->getId(), $entityService);
                            $entityService->commitEntityTransaction('magento-creditmemo-'.$unique_id);
                        }catch(\Exception $e){
                            $entityService->rollbackEntityTransaction('magento-creditmemo-'.$unique_id);
                            throw $e;
                        }
                        $needsUpdate = false;
                    }else{
                        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'ent_link', 'Unlinked creditmemo ' . $unique_id, array('sku'=>$unique_id), array('node'=>$this->_node, 'entity'=>$existingEntity));
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                    }
                }else{
                    $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_INFO, 'ent_update', 'Updated creditmemo ' . $unique_id, array('sku'=>$unique_id), array('node'=>$this->_node, 'entity'=>$existingEntity));
                }
                if($needsUpdate){
                    $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, false);
                }
                $this->updateComments($creditmemo, $existingEntity, $entityService);
            }
        }else{
            // Nothing worked
            throw new \Magelink\Exception\NodeException('No valid API available for sync');
        }
        $this->_ns->setTimestamp($this->_nodeEnt->getNodeId(), 'creditmemo', 'retrieve', $timestamp);
    }

    /**
     * Insert any new comment entries as entity comments
     * @param array $order The full order data
     * @param \Entity\Entity $orderEnt The order entity to attach to
     * @param EntityService $es The EntityService
     */
    protected function updateComments($creditmemo, \Entity\Entity $cmEnt, EntityService $es){
        $comments = $es->loadEntityComments($cmEnt);
        $referenceIds = array();
        foreach($comments as $com){
            $referenceIds[] = $com->getReferenceId();
        }
        foreach($creditmemo['comments'] as $histEntry){
            if(in_array($histEntry['comment_id'], $referenceIds)){
                continue; // Comment already loaded
            }
            $es->createEntityComment($cmEnt, 'Magento', 'Comment: ' . $histEntry['created_at'], $histEntry['comment'], $histEntry['comment_id'], $histEntry['is_visible_on_front']);
        }
    }

    /**
     * Create all the CreditmemoItem entities for a given creditmemo
     * @param $creditmemo
     * @param $oid
     * @param EntityService $es
     */
    protected function createItems($creditmemo, $oid, EntityService $es){

        $parent_id = $oid;

        foreach($creditmemo['items'] as $item){
            $unique_id = $creditmemo['increment_id'].'-'.$item['sku'].'-'.$item['item_id'];

            $e = $es->loadEntity($this->_node->getNodeId(), 'creditmemoitem', ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0), $unique_id);
            if(!$e){
                $local_id = $item['item_id'];
                $product = $es->loadEntityLocal($this->_node->getNodeId(), 'product', ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0), $item['product_id']);
                $parent_item = $es->loadEntityLocal($this->_node->getNodeId(), 'creditmemoitem', ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0), $item['parent_id']);
                $order_item = $es->loadEntityLocal($this->_node->getNodeId(), 'orderitem', ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0), $item['order_item_id']);
                $data = array(
                    'product'=>($product ? $product->getId() : null),
                    'parent_item'=>($parent_item ? $parent_item->getId() : null),
                    'order_item'=>($order_item ? $order_item->getId() : null),
                    'tax_amount'=>(isset($item['tax_amount']) ? $item['tax_amount'] : null),
                    'discount_amount'=>(isset($item['discount_amount']) ? $item['discount_amount'] : null),
                    'sku'=>(isset($item['sku']) ? $item['sku'] : null),
                    'name'=>(isset($item['name']) ? $item['name'] : null),
                    'qty'=>(isset($item['qty']) ? $item['qty'] : null),
                    'row_total'=>(isset($item['row_total']) ? $item['row_total'] : null),
                    'price_incl_tax'=>(isset($item['price_incl_tax']) ? $item['price_incl_tax'] : null),
                    'price'=>(isset($item['price']) ? $item['price'] : null),
                    'row_total_incl_tax'=>(isset($item['row_total_incl_tax']) ? $item['row_total_incl_tax'] : null),
                    'additional_data'=>(isset($item['additional_data']) ? $item['additional_data'] : null),
                    'description'=>(isset($item['description']) ? $item['description'] : null),
                    'hidden_tax_amount'=>(isset($item['hidden_tax_amount']) ? $item['hidden_tax_amount'] : null),
                );
                $e = $es->createEntity($this->_node->getNodeId(), 'creditmemoitem', ($this->_node->isMultiStore() ? $creditmemo['store_id'] : 0), $unique_id, $data, $parent_id);
                $es->linkEntity($this->_node->getNodeId(), $e, $local_id);
            }
        }
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type=\Entity\Update::TYPE_UPDATE)
    {
        // We don't perform any direct updates to creditmemos in this manner.
        // TODO creation
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
                $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : null);
                $this->_soap->call('salesOrderCreditmemoAddComment', $entity->getUniqueId(), $status, $comment, $notify);
                return true;
                break;
            case 'cancel':
                $this->_soap->call('salesOrderCreditmemoCancel', $entity->getUniqueId());
                return true;
                break;
            default:
                throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Magento Creditmemos.');
        }
    }
}