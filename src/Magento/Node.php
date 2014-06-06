<?php

namespace Magento;

use Node\AbstractNode;
use Node\AbstractGateway;
use Node\Entity;
use Magelink\Exception\MagelinkException;

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
    public function getApi($type){
        if(isset($this->_api[$type])){
            return $this->_api[$type];
        }

        $this->_api[$type] = $this->getServiceLocator()->get('magento_' . $type);
        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_INFO, 'init_api', 'Creating API instance ' . $type, array('type'=>$type), array('node'=>$this));
        $res = $this->_api[$type]->init($this);
        if($res){
            return $this->_api[$type];
        }else{
            $this->_api[$type] = false;
            return false;
        }
    }

    protected $_storeViews = null;

    /**
     * Return a data array of all store views
     * @return array
     */
    public function getStoreViews(){

        if($this->_storeViews != null){
            return $this->_storeViews;
        }
        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_INFO, 'storeviews', 'Loading store views', array(), array('node'=>$this));
        $this->_storeViews = array();
        $soap = $this->getApi('soap');

        if(!$soap){
            throw new \Magelink\Exception\SyncException('Failed to initialize SOAP api for store view fetch');
        }
        /** @var \Magento\Api|Soap $soap */
        $res = $soap->call('storeList', array());
        if(isset($res['result'])){
            $res = $res['result'];
        }
        foreach($res as $sV){
            $this->_storeViews[$sV['store_id']] = $sV;
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
            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_ERROR, 'multistore_single', 'Multi-store enabled but only one store view!', array(), array('node'=>$this));
        }else if($storeCount > 1 && !$this->isMultiStore()){
            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'multistore_missing', 'Multi-store disabled but multiple store views!', array(), array('node'=>$this));
        }

        if(!$this->isMultiStore()){
            $this->_storeViews = array(0=>array());
        }
    }

    /**
     * Implemented in each NodeModule
     * The opposite of _init - close off any connections / files / etc that were opened at the beginning.
     * This will always be the last call to the Node.
     * NOTE: This will be called even if the Node has thrown a NodeException, but NOT if a SyncException or other Exception is thrown (which represents an irrecoverable error)
     */
    protected function _deinit()
    {

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
        if($entity_type == 'product'){
            return new Gateway\ProductGateway;
        }
        if($entity_type == 'stockitem'){
            return new Gateway\StockGateway;
        }
        if($entity_type == 'order'){
            return new Gateway\OrderGateway;
        }
        if($entity_type == 'creditmemo'){
            return new Gateway\CreditmemoGateway;
        }
        if($entity_type == 'customer'){
            return new Gateway\CustomerGateway;
        }
        if($entity_type == 'address' || $entity_type == 'orderitem'){
            return null;
        }


        throw new MagelinkException('Unknown/invalid entity type ' . $entity_type);
    }
}