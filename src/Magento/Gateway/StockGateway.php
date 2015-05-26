<?php

namespace Magento\Gateway;

use Node\AbstractNode;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Entity\Service\EntityService;

class StockGateway extends AbstractGateway
{

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     */
    public function _init($entityType)
    {
        if ($entityType != 'stockitem') {
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
            $success = FALSE;
        }else{
            $success = TRUE;
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     */
    public function retrieve()
    {
        if (!$this->_node->getConfig('load_stock')) {
            // No need to retrieve Stock from magento
            return;
        }

        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $timestamp = time() - $this->apiOverlappingSeconds;

        $products = $entityService->locateEntity(
            $this->_node->getNodeId(),
            'product',
            0,
            array(),
            array(),
            array('static_field'=>'unique_id')
        );
        $products = array_unique($products);

        if (FALSE && $this->_db) {
            // TODO: Implement
        }elseif($this->_soap) {
            $results = $this->_soap->call('catalogInventoryStockItemList', array(
                    $products
                ));

            foreach($results as $item){
                $data = array();
                $unique_id = $item['sku'];
                $local_id = $item['product_id'];

                $data = array('available'=>$item['qty']);

                foreach ($this->_node->getStoreViews() as $store_id=>$store_view) {
                    $product = $entityService->loadEntity($this->_node->getNodeId(), 'product', $store_id, $item['sku']);

                    if (!$product) {
                        // No product exists, leave for now. May not be in this store.
                        continue;
                    }

                    $parent_id = $product->getId();

                    /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                    $needsUpdate = TRUE;

                    $existingEntity = $entityService->loadEntityLocal(
                        $this->_node->getNodeId(),
                        'stockitem',
                        $store_id,
                        $local_id
                    );
                    if (!$existingEntity) {
                        $existingEntity = $entityService->loadEntity(
                            $this->_node->getNodeId(),
                            'stockitem',
                            $store_id,
                            $unique_id
                        );
                        if (!$existingEntity) {
                            $existingEntity = $entityService->createEntity(
                                $this->_node->getNodeId(),
                                'stockitem',
                                $store_id,
                                $unique_id,
                                $data,
                                $parent_id
                            );
                            $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);

                            $this->getServiceLocator()->get('logService')
                                ->log(\Log\Service\LogService::LEVEL_INFO,
                                    'ent_new',
                                    'New stockitem '.$unique_id,
                                    array('code'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );
                            $needsUpdate = FALSE;
                        }else{
                            $this->getServiceLocator()->get('logService')
                                ->log(\Log\Service\LogService::LEVEL_INFO,
                                    'ent_link',
                                    'Unlinked stockitem '.$unique_id,
                                    array('code'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );
                            $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                        }
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(\Log\Service\LogService::LEVEL_INFO,
                                'ent_update',
                                'Updated stockitem '.$unique_id,
                                array('code'=>$unique_id),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                    }
                    if ($needsUpdate) {
                        $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
                    }
                }
            }
        }else{
            // Nothing worked
            throw new \Magelink\Exception\NodeException('No valid API available for sync');
        }
        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'stockitem', 'retrieve', $timestamp);
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     * @throws MagelinkException
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type=\Entity\Update::TYPE_UPDATE)
    {
        if (in_array('available', $attributes)) {
            /** @var \Entity\Service\EntityService $entityService */
            $entityService = $this->getServiceLocator()->get('entityService');
            $local_id = $entityService->getLocalId($this->_node->getNodeId(), $entity);
            if (!$local_id) {
                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_WARN,
                        'stock_prodlocal',
                        'Stock update for '.$entity->getUniqueId().' had to use parent local!',
                        array('parent'=>$entity->getParentId()),
                        array('node'=>$this->_node, 'entity'=>$entity)
                    );
                $local_id = $entityService->getLocalId($this->_node->getNodeId(), $entity->getParentId());
                if (!$local_id) {
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_ERROR,
                            'stock_nolocal',
                            'Stock update for '.$entity->getUniqueId().' had no local ID!',
                            array('data'=>$entity->getAllSetData()),
                            array('node'=>$this->_node, 'entity'=>$entity)
                        );
                    return;
                }
            }

            $qty = $entity->getData('available');
            $is_in_stock = ($qty > 0);

            if ($this->_db) {
                $this->_db->updateStock($local_id, $qty, $is_in_stock ? 1 : 0);
            }else{
                $this->_soap->call('catalogInventoryStockItemUpdate', array(
                    'product'=>$local_id,
                    'productId'=>$local_id,
                    'data'=>array(
                        'qty'=>$qty,
                        'is_in_stock'=>($is_in_stock ? 1 : 0)
                    )
                ));
            }
        }else{
            // We don't care about any other attributes
        }
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Magento Stock Items.');
    }
}