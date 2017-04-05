<?php
/**
 * Implements SOAP access to Magento
 * @category Magento
 * @package Magento\Api
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
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
     * @return string $initLogCode
     */
    protected function getInitLogCode()
    {
        return 'mag_isoap1';
    }

    /**
     * @return NULL|Client $this->_soapClient
     */
    protected function getAndStoreSoapClient()
    {
        $this->_soapClient = new Client(
            $this->_node->getConfig('web_url').'api/soap/?wsdl',
            array('soap_version'=>SOAP_1_1)
        );

        return $this->_soapClient;
    }

    /**
     * Make a call to SOAP v1 API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    protected function _call($call, $data)
    {
        array_unshift($data, $call);
        array_unshift($data, $this->_sessionId);

        try{
            $result = $this->_soapClient->call('call', $data);
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                    'mag_soap_call_v1',
                    'Successful SOAP v1 call '.$call.'.',
                    array('data'=>$data, 'result'=>$result)
                );
        }catch (\SoapFault $soapFault) {
            throw new MagelinkException('SOAP v1 Fault with call '.$call.': '.$soapFault->getMessage(), 0, $soapFault);
        }

        return $result;
    }

}
