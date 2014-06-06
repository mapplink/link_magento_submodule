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
class SoapV1 extends Soap {

    public function init(Node $magentoNode){
        if($this->_soapClient !== null){
            throw new \Magelink\Exception\MagelinkException('Tried to initialize Soap v1 API twice!');
        }

        $username = $magentoNode->getConfig('soap_username');
        $password = $magentoNode->getConfig('soap_password');
        if(!$username || !$password){
            // No auth passed, SOAP unavailable
            return false;
        }

        $this->_soapClient = new Client($magentoNode->getConfig('web_url').'api/soap/?wsdl', array('soap_version'=>SOAP_1_1));
        $loginRes = $this->_soapClient->call('login', array($magentoNode->getConfig('soap_username'), $magentoNode->getConfig('soap_password')));
        //$loginRes = $this->_processResponse($loginRes);
        $this->_sessionId = $loginRes;
        if($loginRes){
            return true;
        }
        return false;
    }

    /**
     * Make a call to SOAP v1 API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    public function call($call, $data){
        array_unshift($data, $call);
        array_unshift($data, $this->_sessionId);
        try{
            $res = $this->_soapClient->call('call', $data);
        }catch(\SoapFault $sf){
            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_ERROR, 'soap_fault', 'SOAP v1 Fault with call ' . $call . ': ' . $sf->getMessage(), array('keys'=>array_keys($data), 'code'=>$sf->getCode(), 'trace'=>$sf->getTraceAsString(), 'req'=>$this->_soapClient->getLastRequest(), 'resp'=>$this->_soapClient->getLastResponse()));
            throw new \Magelink\Exception\MagelinkException('Soap Fault - ' . $sf->getMessage(), 0, $sf);//throw $sf;
        }
        //echo PHP_EOL . $this->_soapClient->getLastRequest() . PHP_EOL . $this->_soapClient->getLastResponse() . PHP_EOL;
        return $this->_processResponse($res);
    }
}