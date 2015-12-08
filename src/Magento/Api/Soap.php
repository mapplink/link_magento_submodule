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
    public function init(Node $magentoNode)
    {
        if($this->_soapClient !== NULL){
            throw new \Magelink\Exception\MagelinkException('Tried to initialize Soap API twice!');
            $success = FALSE;
        }else{
            $username = $magentoNode->getConfig('soap_username');
            $password = $magentoNode->getConfig('soap_password');
            if (!$username || !$password) {
                // No auth passed, SOAP unavailable
                $success = FALSE;
            }else{
                $this->_soapClient = new Client(
                    $magentoNode->getConfig('web_url').'api/v2_soap?wsdl=1',
                    array('soap_version'=>SOAP_1_1)
                );

                $loginResult = $this->_soapClient->call(
                    'login', array($magentoNode->getConfig('soap_username'), $magentoNode->getConfig('soap_password'))
                );
                //$loginResult = $this->_processResponse($loginRes);
                $this->_sessionId = $loginResult;
                $success = (bool) $loginResult;
            }
        }

        return $success;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    public function call($call, $data)
    {
        if (!is_array($data)) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }else{
                $data = array($data);
            }
        }

        array_unshift($data, $this->_sessionId);

        try{
            $result = $this->_soapClient->call($call, $data);
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                    'soap_call',
                    'Successful SOAP call '.$call.'.',
                    array(
                        'data'=>$data,
                        'result'=>$result
                    )
                );
        }catch (\SoapFault $soapFault) {
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_ERROR,
                    'soap_fault',
                    'SOAP Fault with call '.$call.': '.$soapFault->getMessage(),
                    array(
                        'data'=>$data,
                        'code'=>$soapFault->getCode(),
                        'trace'=>$soapFault->getTraceAsString(),
                        'request'=>$this->_soapClient->getLastRequest(),
                        'response'=>$this->_soapClient->getLastResponse())
                );
            $this->forceStdoutDebug();
            throw new \Magelink\Exception\MagelinkException('Soap Fault - '.$soapFault->getMessage(), 0, $soapFault);
        }
        // $this->forceStdoutDebug(); // Uncomment for debugging

        $result = $this->_processResponse($result);
/* ToDo (maybe): Investigate if that could be centralised
        if (isset($result['result'])) {
            $result = $result['result'];
        }
*/
        return $result;
    }

    public function forceStdoutDebug(){
        echo PHP_EOL . $this->_soapClient->getLastRequest() . PHP_EOL . $this->_soapClient->getLastResponse() . PHP_EOL;
    }

    /**
     * Processes response from SOAP api to convert all std_class object structures to associative/numerical arrays
     * @param mixed $array
     * @return array
     */
    protected function _processResponse($array)
    {
        if (is_object($array)) {
            $array = get_object_vars($array);
        }

        $result = $array;
        if (is_array($array)) {
            foreach ($result as $key=>$value) {
                if (is_object($value) || is_array($value)){
                    $result[$key] = $this->_processResponse($value);
                }
            }
        }

        if (is_object($result)) {
            $result = get_object_vars($result);
        }

        return $result;
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
