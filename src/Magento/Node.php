<?php

namespace Magento;

use Node\AbstractNode;
use Node\AbstractGateway;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\SyncException;

class Node extends AbstractNode {

    /**
     * @return bool Whether or not we should enable multi store mode
     */
    public function isMultiStore(){
        return (bool) $this->getConfig('multi_store');
    }

    protected $_api = array();

    /**
     * Returns an api instance set up for this node. Will return false if that type of API is unavailable.
     * @param string $type The type of API to establish - must be available as a service with the name "magento_{type}"
     * @return object|false
     */
    public function getApi($type)
    {
        if(isset($this->_api[$type])){
            return $this->_api[$type];
        }

        $this->_api[$type] = $this->getServiceLocator()->get('magento_' . $type);
        $this->getServiceLocator()->get('logService')
            ->log(\Log\Service\LogService::LEVEL_INFO,
                'init_api',
                'Creating API instance '.$type,
                array('type'=>$type),
                array('node'=>$this)
            );

        $apiExists = $this->_api[$type]->init($this);
        if (!$apiExists) {
            $this->_api[$type] = FALSE;
        }

        return $this->_api[$type];
    }

    protected $_storeViews = NULL;

    /**
     * Return a data array of all store views
     * @return array
     */
    public function getStoreViews()
    {
        if ($this->_storeViews === NULL) {
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_INFO,
                    'storeviews',
                    'Loading store views',
                    array(),
                    array('node'=>$this)
                );

            $soap = $this->getApi('soap');
            if (!$soap) {
                throw new SyncException('Failed to initialize SOAP api for store view fetch');
            }else{
                /** @var \Magento\Api|Soap $soap */
                $result = $soap->call('storeList', array());
                if (count($result)) {
                    if (isset($result['result'])) {
                        $result = $result['result'];
                    }

                    $this->_storeViews = array();
                    foreach ($result as $storeView) {
                        $this->_storeViews[$storeView['store_id']] = $storeView;
                    }
                }
            }
        }

        return $this->_storeViews;
    }

    /**
     * Implemented in each NodeModule
     * Should set up any initial data structures, connections, and open any required files that the node needs to operate.
     * In the case of any errors that mean a successful sync is unlikely, a Magelink\Exception\InitException MUST be thrown.
     *
     * @param Entity\Node $nodeEntity
     */
    protected function _init(\Node\Entity\Node $nodeEntity)
    {
        $this->getStoreViews();
        $storeCount = count($this->_storeViews);
        if($storeCount == 1 && $this->isMultiStore()){
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_ERROR,
                    'multistore_single',
                    'Multi-store enabled but only one store view!',
                    array(),
                    array('node'=>$this)
                );
        }else if($storeCount > 1 && !$this->isMultiStore()){
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_WARN,
                    'multistore_missing',
                    'Multi-store disabled but multiple store views!',
                    array(),
                    array('node'=>$this)
                );
        }

        if (!$this->isMultiStore()) {
            $this->_storeViews = array(0=>array());
        }
    }

    /**
     * Implemented in each NodeModule
     * The opposite of _init - close off any connections / files / etc that were opened at the beginning.
     * This will always be the last call to the Node.
     * NOTE: This will be called even if the Node has thrown a NodeException, but NOT if a SyncException or other Exception is thrown (which represents an irrecoverable error)
     */
    protected function _deinit() {}

    /**
     * Updates all data into the node’s source - should load and collapse all pending updates and call writeUpdates,
     *   as well as loading and sequencing all actions.
     * @throws NodeException
     */
    public function update()
    {
        $this->_nodeService = $this->getServiceLocator()->get('nodeService');

        $this->actions = $this->getPendingActions();
        $this->updates = $this->getPendingUpdates();

        $this->getServiceLocator()->get('logService')
            ->log(\Log\Service\LogService::LEVEL_INFO,
                'mag_node_upd',
                'AbstractNode update: '.count($this->actions).' actions, '.count($this->updates).' updates.',
                array(),
                array('node'=>$this, 'actions'=>$this->actions, 'updates'=>$this->updates)
            );

        $this->processActions();
        $this->processUpdates();
    }

    /**
     * Implemented in each NodeModule
     * Returns an instance of a subclass of AbstractGateway that can handle the provided entity type.
     *
     * @throws MagelinkException
     * @param string $entity_type
     * @return AbstractGateway
     */
    protected function _createGateway($entity_type)
    {
        if ($entity_type == 'product') {
            return new Gateway\ProductGateway;
        }
        if ($entity_type == 'stockitem') {
            return new Gateway\StockGateway;
        }
        if ($entity_type == 'order') {
            return new Gateway\OrderGateway;
        }
        if ($entity_type == 'creditmemo') {
            return new Gateway\CreditmemoGateway;
        }
        if ($entity_type == 'customer') {
            return new Gateway\CustomerGateway;
        }
        if ($entity_type == 'address' || $entity_type == 'orderitem') {
            return NULL;
        }

        throw new SyncException('Unknown/invalid entity type '.$entity_type);
    }
}