<?php
/**
 * @category Magento
 * @package Magento
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento;

use Log\Service\LogService;
use Node\AbstractNode;
use Node\AbstractGateway;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\SyncException;

class Node extends AbstractNode
{

    /** @var array|NULL $_storeViews */
    protected $_storeViews = NULL;


    /**
     * @return bool Whether or not we should enable multi store mode
     */
    public function isMultiStore()
    {
        return (bool) $this->getConfig('multi_store');
    }

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
            ->log(LogService::LEVEL_INFO,
                'mag_init_api',
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

    /**
     * Return a data array of all store views
     * @return array $this->_storeViews
     */
    public function getStoreViews()
    {
        if ($this->_storeViews === NULL) {
            $soap = $this->getApi('soap');
            if (!$soap) {
                throw new SyncException('Failed to initialize SOAP api for store view fetch');
            }else{
                /** @var \Magento\Api|Soap $soap */
                $response = $soap->call('storeList', array());
                if (count($response)) {
                    if (isset($response['result'])) {
                        $response = $response['result'];
                    }

                    $this->_storeViews = array();
                    foreach ($response as $storeView) {
                        $this->_storeViews[$storeView['store_id']] = $storeView;
                    }
                }

                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_storeviews',
                        'Loaded store views',
                        array('soap response'=>$response, 'store views'=>$this->_storeViews),
                        array('node'=>$this)
                    );
            }
        }

        return $this->_storeViews;
    }

    /**
     * Should set up any initial data structures, connections, and open any required files that the node needs to operate.
     * In the case of any errors that mean a successful sync is unlikely, a Magelink\Exception\InitException MUST be thrown.
     */
    protected function _init()
    {
        $this->getStoreViews();
        $storeCount = count($this->_storeViews);

        if ($storeCount == 1 && $this->isMultiStore()) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mag_mstore_sng',
                    'Multi-store enabled but only one store view!',
                    array(),
                    array('node'=>$this)
                );
        }elseif ($storeCount > 1 && !$this->isMultiStore()) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_WARN,
                    'mag_mstore_miss',
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
     * This will always be the last call to the Node to close off any open connections, files, etc.
     */
    protected function _deinit() {}

    /**
     * Updates all data into the node’s source - should load and collapse all pending updates and call writeUpdates,
     *   as well as loading and sequencing all actions.
     * @throws NodeException
     */
    public function update()
    {
        $logCode = $this->logTimes('Magento\Node');

        $this->getPendingActions();
        $this->getPendingUpdates();

        $logMessage = 'Magento\Node update: '.count($this->updates).' updates, '.count($this->actions).' actions.';
        $logDataNumbers = array('updates'=>count($this->updates), 'actions'=>count($this->actions));
        $logEntities = array('node'=>$this, 'actions'=>$this->actions, 'updates'=>$this->updates);
        $this->_logService->log(LogService::LEVEL_INFO, $logCode.'_no', $logMessage, $logDataNumbers, $logEntities);

        $this->processActions();
        $this->processUpdates();

        $this->logTimes('Magento\Node', TRUE);
    }

    /**
     * Implemented in each NodeModule
     * Returns an instance of a subclass of AbstractGateway that can handle the provided entity type.
     *
     * @throws MagelinkException
     * @param string $entityType
     * @return AbstractGateway
     */
    protected function _createGateway($entityType)
    {
        switch ($entityType) {
            case 'customer':
                $gateway = new Gateway\CustomerGateway;
                break;
            case 'product':
                $gateway = new Gateway\ProductGateway;
                break;
            case 'order':
                $gateway = new Gateway\OrderGateway;
                break;
            case 'stockitem':
                $gateway = new Gateway\StockGateway;
                break;
            case 'creditmemo':
                $gateway = new Gateway\CreditmemoGateway;
                break;
            default:
                throw new SyncException('Unknown/invalid entity type '.$entityType);
        }

        return $gateway;
    }

}