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


class Soap implements ServiceLocatorAwareInterface
{

    /** @var Node|NULL $this->_node */
    protected $_node = NULL;
    /** @var string $this->_sessionId The Session ID provided by Magento after logging in */
    protected $_sessionId;
    /** @var Client|NULL $this->_soapClient */
    protected $_soapClient = NULL;

    /** @var ServiceLocatorInterface $_serviceLocator */
    protected $_serviceLocator;

    /**
     * Get service locator
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->_serviceLocator;
    }

    /**
     * Set service locator
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->_serviceLocator = $serviceLocator;
    }

    /**
     * @param Node $magentoNode The Magento node we are representing communications for
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    public function init(Node $magentoNode)
    {
        $this->_node = $magentoNode;
        return $this->_init();
    }

    /**
     * @return string $initLogCode
     */
    protected function getInitLogCode()
    {
        return 'mag_isoap';
    }

    /**
     * @return NULL|Client $this->_soapClient
     */
    protected function getAndStoreSoapClient()
    {
        $this->_soapClient = new Client(
            $this->_node->getConfig('web_url').'api/v2_soap?wsdl=1',
            array('soap_version'=>SOAP_1_1)
        );

        return $this->_soapClient;
    }

    /**
     * Sets up the SOAP API, connects to Magento, and performs a login.
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    protected function _init()
    {
        $success = FALSE;

        if (is_null($this->_node)) {
            throw new MagelinkException('Magento node is not available on the SOAP API!');

        }elseif (!is_null($this->_soapClient)) {
            throw new MagelinkException('Tried to initialize Soap API twice!');

        }else{
            $username = $this->_node->getConfig('soap_username');
            $password = $this->_node->getConfig('soap_password');

            $logLevel = LogService::LEVEL_ERROR;
            $logCode = $this->getInitLogCode();
            $logData = array('username'=>$username, 'password'=>$password);
            $logEntities = array();

            if (!$username || !$password) {
                $logCode .= '_fail';
                $message = 'SOAP initialisation failed: Please check user name and password.';
            }else{
                $message = '.';
                $this->getAndStoreSoapClient();
                $logData['wsdl'] = $this->_soapClient->getWSDL();

                try{
                    $loginResult = $this->_soapClient->call('login',
                        array($this->_node->getConfig('soap_username'), $this->_node->getConfig('soap_password'))
                    );
// ToDo: Review the next line and remove line or comment
//                $loginResult = $this->_processResponse($loginResult);
                    $this->_sessionId = $loginResult;
                    $success = (bool) $loginResult;
                    $message = '.';
                }catch (\Exception $exception) {
                    $message = ': '.$exception->getMessage();
                    $logData['response'] = $this->_soapClient->getSoapClient()->__getLastResponse();
                    $logData['response headers'] = $this->_soapClient->getSoapClient()->__getLastResponseHeaders();
                    $logEntities['exception'] = $exception;
                    $logEntities['\SoapClient'] = $this->_soapClient->getSoapClient();
                }

                if ($success) {
                    $logLevel = LogService::LEVEL_INFO;
                    $message = trim('SOAP was sucessfully initialised'.$message);
                }else{
                    $logCode .= '_err';
                    $message = 'SOAP initialisation error'.$message;
                    $logEntities['\Zend\Soap\Client'] = $this->_soapClient;
                }
            }

            $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $message, $logData, $logEntities);
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
        $retry = FALSE;
        do {
            try{
                $result = $this->_call($call, $data);
                $success = TRUE;
            }catch(MagelinkException $exception) {
                $success = FALSE;
                $retry = !$retry;
                $soapFault = $exception->getPrevious();
                if ($retry === TRUE && (strpos(strtolower($soapFault->getMessage()), 'session expired') !== FALSE
                    || strpos(strtolower($soapFault->getMessage()), 'try to relogin') !== FALSE)) {

                    $this->_soapClient = NULL;
                    $this->_init();
                }
            }
        }while ($retry === TRUE && $success === FALSE);

        if ($success !== TRUE) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, 'mag_soap_fault', $exception->getMessage(),
                    array(
                        'data'=>$data,
                        'code'=>$soapFault->getCode(),
                        'trace'=>$soapFault->getTraceAsString(),
                        'request'=>$this->_soapClient->getLastRequest(),
                        'response'=>$this->_soapClient->getLastResponse())
                );
            // ToDo: Check if this additional logging is necessary
            $this->forceStdoutDebug();
            throw $exception;
            $result = NULL;
        }else{
            $result = $this->_processResponse($result);
            /* ToDo: Investigate if that could be centralised
            if (isset($result['result'])) {
                $result = $result['result'];
            }*/

            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'mag_soap_success', 'Successfully soap call: '.$call,
                    array('call'=>$call, 'data'=>$data, 'result'=>$result));
        }

        return $result;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    protected function _call($call, $data)
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
                ->log(LogService::LEVEL_DEBUGEXTRA,
                    'mag_soap_call',
                    'Successful SOAP call '.$call.'.',
                    array('data'=>$data, 'result'=>$result)
                );
        }catch (\SoapFault $soapFault) {
            throw new MagelinkException('SOAP Fault with call '.$call.': '.$soapFault->getMessage(), 0, $soapFault);
        }

        return $result;
    }

    /**
     * Forced debug output to command line
     */
    public function forceStdoutDebug()
    {
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

}
