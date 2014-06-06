<?php

namespace Magento\Api;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Magento\Node;
use Zend\Soap\Client;

/**
 * Implements SOAP access to Magento
 * @package Magento\Api
 */
class Soap implements ServiceLocatorAwareInterface {

    /**
     * @var string The Session ID provided by Magento after logging in
     */
    protected $_sessionId;

    /**
     * @var Client $_soapClient
     */
    protected $_soapClient = null;

    /**
     * Sets up the SOAP API, connects to Magento, and performs a login.
     *
     * @param Node $magentoNode The Magento node we are representing communications for
     * @return bool Whether we successfully connected
     * @throws \Magelink\Exception\MagelinkException If this API has already been initialized
     */
    public function init(Node $magentoNode){
        if($this->_soapClient !== null){
            throw new \Magelink\Exception\MagelinkException('Tried to initialize Soap API twice!');
        }

        $username = $magentoNode->getConfig('soap_username');
        $password = $magentoNode->getConfig('soap_password');
        if(!$username || !$password){
            // No auth passed, SOAP unavailable
            return false;
        }

        $this->_soapClient = new Client($magentoNode->getConfig('web_url').'api/v2_soap?wsdl=1', array('soap_version'=>SOAP_1_1));
        $loginRes = $this->_soapClient->call('login', array($magentoNode->getConfig('soap_username'), $magentoNode->getConfig('soap_password')));
        //$loginRes = $this->_processResponse($loginRes);
        $this->_sessionId = $loginRes;
        if($loginRes){
            return true;
        }
        return false;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    public function call($call, $data){
        if(!is_array($data)){
            if(is_object($data)){
                $data = get_object_vars($data);
            }else{
                $data = array($data);
            }
        }
        array_unshift($data, $this->_sessionId);
        try{
            $res = $this->_soapClient->call($call, $data);
        }catch(\SoapFault $sf){
            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_ERROR, 'soap_fault', 'SOAP Fault with call ' . $call . ': ' . $sf->getMessage(), array('keys'=>array_keys($data), 'code'=>$sf->getCode(), 'trace'=>$sf->getTraceAsString(), 'req'=>$this->_soapClient->getLastRequest(), 'resp'=>$this->_soapClient->getLastResponse()));
            throw new \Magelink\Exception\MagelinkException('Soap Fault - ' . $sf->getMessage(), 0, $sf);// throw $sf;
        }
        // NOTE: Uncomment the following for debugging
        //echo PHP_EOL . $this->_soapClient->getLastRequest() . PHP_EOL . $this->_soapClient->getLastResponse() . PHP_EOL;
        return $this->_processResponse($res);
    }

    /**
     * Processes response from SOAP api to convert all std_class object structures to associative/numerical arrays
     * @param mixed $array
     * @return array
     */
    protected function _processResponse($array){
        if(is_object($array)){
            $array = get_object_vars($array);
        }
        $res = $array;
        if(is_array($array)){
            foreach($res as $key=>$v){
                if(is_object($v) || is_array($v)){
                    $res[$key] = $this->_processResponse($v);
                }
            }
        }
        if(is_object($res)){
            $res = get_object_vars($res);
        }
        return $res;
    }

    protected $_serviceLocator;

    /**
     * Set service locator
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->_serviceLocator = $serviceLocator;
    }

    /**
     * Get service locator
     *
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->_serviceLocator;
    }
}