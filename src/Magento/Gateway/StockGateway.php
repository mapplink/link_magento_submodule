<?php

namespace Magento\Gateway;

use Entity\Service\EntityService;
use Log\Service\LogService;
use Node\AbstractNode;
use Node\Entity;
use Magelink\Exception\MagelinkException;


class StockGateway extends AbstractGateway
{

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'stockitem') {
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
            $success = FALSE;
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

        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $timestamp = time() - $this->apiOverlappingSeconds;

        $products = $this->_entityService->locateEntity(
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
                $localId = $item['product_id'];

                $data = array('available'=>$item['qty']);

                foreach ($this->_node->getStoreViews() as $store_id=>$store_view) {
                    $product = $this->_entityService->loadEntity($this->_node->getNodeId(), 'product', $store_id, $item['sku']);

                    if (!$product) {
                        // No product exists, leave for now. May not be in this store.
                        continue;
                    }

                    $parent_id = $product->getId();

                    /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                    $needsUpdate = TRUE;

                    $existingEntity = $this->_entityService->loadEntityLocal(
                        $this->_node->getNodeId(),
                        'stockitem',
                        $store_id,
                        $localId
                    );
                    if (!$existingEntity) {
                        $existingEntity = $this->_entityService->loadEntity(
                            $this->_node->getNodeId(),
                            'stockitem',
                            $store_id,
                            $unique_id
                        );
                        if (!$existingEntity) {
                            $existingEntity = $this->_entityService->createEntity(
                                $this->_node->getNodeId(),
                                'stockitem',
                                $store_id,
                                $unique_id,
                                $data,
                                $parent_id
                            );
                            $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);

                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_INFO,
                                    'mag_si_new',
                                    'New stockitem '.$unique_id,
                                    array('code'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );
                            $needsUpdate = FALSE;
                        }else{
                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_INFO,
                                    'mag_si_link',
                                    'Unlinked stockitem '.$unique_id,
                                    array('code'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );
                            $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
                        }
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO,
                                'mag_si_upd',
                                'Updated stockitem '.$unique_id,
                                array('code'=>$unique_id),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                    }
                    if ($needsUpdate) {
                        $this->_entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
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
        $logCode = 'mag_si';
        $logData = array('data'=>$entity->getAllSetData());
        $logEntities = array('node' => $this->_node, 'entity' => $entity);

        if (in_array('available', $attributes)) {

            $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $entity);
            if ($localId) {
                $logLevel = LogService::LEVEL_INFO;
                $logCode .= '_upd';
                $logMessage = 'Update stock '.$entity->getUniqueId().'.';
            }else{
                $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $entity->getParentId());
                if ($localId) {
                    $logLevel = LogService::LEVEL_WARN;
                    $logCode .= '_prnt_loc';
                    $logMessage = 'Stock update for '.$entity->getUniqueId().' had to use parent local!';
                    $logData['parent'] = $entity->getParentId();
                }else{
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode .= '_nolocal';
                    $logMessage = 'Stock update for '.$entity->getUniqueId().' had no local ID!';
                }
            }

            $this->getServiceLocator()->get('logService')
                ->log($logLevel, $logCode, $logMessage, $logData, $logEntities);

            if ($localId) {
                $qty = $entity->getData('available');
                $is_in_stock = ($qty > 0);

                if ($this->_db) {
                    $this->_db->updateStock($localId, $qty, $is_in_stock ? 1 : 0);
                }else{
                    $this->_soap->call('catalogInventoryStockItemUpdate',
                        // ToDo : Check if product can be removed
                        array('product'=>$localId, 'productId'=>$localId, 'data'=>array(
                            'qty' => $qty,
                            'is_in_stock' => ($is_in_stock ? 1 : 0)
                        ))
                    );
                }
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