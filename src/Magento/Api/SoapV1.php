<?php
/**
 * Implements SOAP access to Magento
 * @category Magento
 * @package Magento\Api
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Api;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magento\Node;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Soap\Client;


class SoapV1 extends Soap
{

    /**
     * @param Node $magentoNode
     * @return bool $success
     * @throws MagelinkException
     */
    public function init(Node $magentoNode)
    {
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
    public function call($call, array $data)
    {
        array_unshift($data, $call);
        array_unshift($data, $this->_sessionId);

        try{
            $result = $this->_soapClient->call('call', $data);
        }catch (\SoapFault $soapFault) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mag_soap1_fault',
                    'SOAP v1 Fault with call '.$call.': '.$soapFault->getMessage(),
                    array(
                        'data'=>$data, 'code'=>$soapFault->getCode(), 'trace'=>$soapFault->getTraceAsString(),
                        'request'=>$this->_soapClient->getLastRequest(), 'response'=>$this->_soapClient->getLastResponse())
                );
            throw new MagelinkException('Soap Fault - '.$soapFault->getMessage(), 0, $soapFault); //throw $soapFault;
        }

        return $this->_processResponse($result);
    }

}