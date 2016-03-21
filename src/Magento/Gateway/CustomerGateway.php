<?php

namespace Magento\Gateway;

use Entity\Service\EntityService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;
use Node\Entity;

class CustomerGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'customer';


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'customer') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }else{
            if ($this->_node->getConfig('customer_attributes')
                && strlen($this->_node->getConfig('customer_attributes'))) {

                $this->_soapv1 = $this->_node->getApi('soapv1');
                if (!$this->_soapv1) {
                    throw new GatewayException('SOAP v1 is required for extended customer attributes');
                }
            }

            try {
                $groups = $this->_soap->call('customerGroupList', array());
            }catch (\Exception $exception) {
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                $succes = FALSE;
            }

            $this->_custGroups = array();
            foreach ($groups as $groupArray) {
                $this->_custGroups[$groupArray['customer_group_id']] = $groupArray;
            }
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @throws MagelinkException
     * @throws NodeException
     * @throws GatewayException
     */
    public function retrieve()
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $this->getNewRetrieveTimestamp();
        $lastRetrieve = $this->getLastRetrieveDate();

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mag_cu_re_time',
                'Retrieving customers updated since '.$lastRetrieve,
                array('type'=>'customer', 'timestamp'=>$lastRetrieve)
            );

        if ($this->_soap) {
            try {
                $results = $this->_soap->call('customerCustomerList', array(
                    array('complex_filter'=>array(array(
                        'key'=>'updated_at',
                        'value'=>array('key'=>'gt', 'value'=>$lastRetrieve),
                    )))
                ));
            }catch (\Exception $exception) {
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }

            if (!is_array($results)) {
                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR,
                    'mag_cu_re_soap',
                    'SOAP (customerCustomerList) did not return an array but '.gettype($results).' instead.',
                    array('type'=>gettype($results), 'class'=>(is_object($results) ? get_class($results) : 'no object')),
                    array('soap result'=>$results)
                );
            }
/*
            $specialAtt = $this->_node->getConfig('customer_special_attributes');
            if (!strlen(trim($specialAtt))) {
                $specialAtt = false;
            }else{
                $specialAtt = trim(strtolower($specialAtt));
                if (!$entityConfigService->checkAttribute('customer', $specialAtt)) {
                    $entityConfigService->createAttribute(
                        $specialAtt, $specialAtt, 0, 'varchar', 'customer', 'Custom Magento attribute (special - taxvat)');
                    $this->getServiceLocator()->get('nodeService')
                        ->subscribeAttribute($this->_node->getNodeId(), $specialAtt, 'customer');
                }
            }
*/
            $additionalAttributes = $this->_node->getConfig('customer_attributes');
            if (is_string($additionalAttributes)) {
                $additionalAttributes = explode(',', $additionalAttributes);
            }
            if (!$additionalAttributes || !is_array($additionalAttributes)) {
                $additionalAttributes = array();
            }

            foreach ($additionalAttributes as $k=>&$attributeCode) {
                $attributeCode = trim(strtolower($attributeCode));
                if (!strlen($attributeCode)) {
                    unset($additionalAttributes[$k]);
                }else{
                    if (!$entityConfigService->checkAttribute('customer', $attributeCode)) {
                        $entityConfigService->createAttribute(
                            $attributeCode, $attributeCode, 0, 'varchar', 'customer', 'Custom Magento attribute');
                        $this->getServiceLocator()->get('nodeService')
                            ->subscribeAttribute($this->_node->getNodeId(), $attributeCode, 'customer');
                    }
                }
            }

            foreach ($results as $customer) {
                $data = array();

                $uniqueId = $customer['email'];
                $localId = $customer['customer_id'];
                $storeId = ($this->_node->isMultiStore() ? $customer['store_id'] : 0);
                $parentId = NULL;

                $data['first_name'] = (isset($customer['firstname']) ? $customer['firstname'] : NULL);
                $data['middle_name'] = (isset($customer['middlename']) ? $customer['middlename'] : NULL);
                $data['last_name'] = (isset($customer['lastname']) ? $customer['lastname'] : NULL);
                $data['date_of_birth'] = (isset($customer['dob']) ? $customer['dob'] : NULL);

                /**if($specialAtt){
                    $data[$specialAtt] = (isset($customer['taxvat']) ? $customer['taxvat'] : NULL);
                }**/
                if (count($additionalAttributes) && $this->_soapv1) {
                    try {
                        $extra = $this->_soapv1->call('customer.info',
                            array($customer['customer_id'], $additionalAttributes));
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }

                    foreach ($additionalAttributes as $attributeCode) {
                        if (array_key_exists($attributeCode, $extra)) {
                            $data[$attributeCode] = $extra[$attributeCode];
                        }else{
                            $data[$attributeCode] = NULL;
                        }
                    }
                }

                if(isset($this->_custGroups[intval($customer['group_id'])])){
                    $data['customer_type'] = $this->_custGroups[intval($customer['group_id'])]['customer_group_code'];
                }else{
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_WARN,
                            'mag_cu_ukwn_grp',
                            'Unknown customer group ID '.$customer['group_id'],
                            array('group'=>$customer['group_id'], 'unique'=>$customer['email'])
                        );
                }

                if ($this->_node->getConfig('load_full_customer')) {
                    $data = array_merge($data, $this->createAddresses($customer, $entityService));

                    if ($this->_db) {
                        try {
                            $data['enable_newsletter'] = $this->_db->getNewsletterStatus($localId);
                        }catch (\Exception $exception) {
                            // store as sync issue
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                }

                /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                $needsUpdate = TRUE;

                $existingEntity = $entityService
                    ->loadEntityLocal($this->_node->getNodeId(), 'customer', 0, $localId);
                if (!$existingEntity) {
                    $existingEntity = $entityService
                        ->loadEntity($this->_node->getNodeId(), 'customer', $storeId, $uniqueId);
                    if (!$existingEntity) {
                        $existingEntity = $entityService->createEntity(
                            $this->_node->getNodeId(),
                            'customer',
                            $storeId,
                            $uniqueId,
                            $data,
                            $parentId
                        );
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO,
                                'mag_cu_new',
                                'New customer '.$uniqueId,
                                array('code'=>$uniqueId),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $needsUpdate = false;
                    }elseif ($entityService->getLocalId($this->_node->getNodeId(), $existingEntity) != NULL) {
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO,
                                'mag_cu_relink',
                                'Incorrectly linked customer '.$uniqueId,
                                array('code'=>$uniqueId),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $entityService->unlinkEntity($this->_node->getNodeId(), $existingEntity);
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO,
                                'mag_cu_link',
                                'Unlinked customer '.$uniqueId,
                                array('code'=>$uniqueId),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
                    }
                }else{
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_INFO,
                            'mag_cu_upd',
                            'Updated customer '.$uniqueId,
                            array('code'=>$uniqueId),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );
                }
                if ($needsUpdate) {
                    $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, false);
                }
            }
        }else{
            // Nothing worked
            throw new NodeException('No valid API available for sync');
        }
        $this->_nodeService
            ->setTimestamp($this->_nodeEntity->getNodeId(), 'customer', 'retrieve', $this->newRetrieveTimestamp);
    }

    /**
     * Create the Address entities for a given customer and pass them back as the appropriate attributes
     * @param array $customerData
     * @param EntityService $entityService
     * @return array $data
     * @throws GatewayException
     */
    protected function createAddresses(array $customer, EntityService $entityService)
    {
        $data = array();

        try {
            $addressList = $this->_soap->call('customerAddressList', array($customer['customer_id']));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        foreach ($addressList as $address) {
            if ($address['is_default_billing']) {
                $data['billing_address'] = $this->createAddressEntity($address, $customer, 'billing', $entityService);
            }
            if ($address['is_default_shipping']) {
                $data['shipping_address'] = $this->createAddressEntity($address, $customer, 'shipping', $entityService);
            }
            if (!$address['is_default_billing'] && !$address['is_default_shipping']) {
                // TODO: Store this maybe? For now ignore
            }
        }
        return $data;
    }

    /**
     * Create an individual Address entity for a customer
     *
     * @param array $addressData
     * @param array $customer
     * @param string $type "billing" or "shipping"
     * @param EntityService $entityService
     * @return \Entity\Entity|NULL $addressEntity
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function createAddressEntity(array $addressData, array $customer, $type, EntityService $entityService)
    {
        $uniqueId = 'cust-'.$customer['customer_id'].'-'.$type;

        $addressEntity = $entityService->loadEntity(
            $this->_node->getNodeId(),
            'address',
            ($this->_node->isMultiStore() ? $customer['store_id'] : 0),
            $uniqueId
        );

        $data = array(
            'first_name'=>(isset($addressData['firstname']) ? $addressData['firstname'] : NULL),
            'middle_name'=>(isset($addressData['middlename']) ? $addressData['middlename'] : NULL),
            'last_name'=>(isset($addressData['lastname']) ? $addressData['lastname'] : NULL),
            'prefix'=>(isset($addressData['prefix']) ? $addressData['prefix'] : NULL),
            'suffix'=>(isset($addressData['suffix']) ? $addressData['suffix'] : NULL),
            'street'=>(isset($addressData['street']) ? $addressData['street'] : NULL),
            'city'=>(isset($addressData['city']) ? $addressData['city'] : NULL),
            'region'=>(isset($addressData['region']) ? $addressData['region'] : NULL),
            'postcode'=>(isset($addressData['postcode']) ? $addressData['postcode'] : NULL),
            'country_code'=>(isset($addressData['country_id']) ? $addressData['country_id'] : NULL),
            'telephone'=>(isset($addressData['telephone']) ? $addressData['telephone'] : NULL),
            'company'=>(isset($addressData['company']) ? $addressData['company'] : NULL)
        );

        if (!$addressEntity) {
            $addressEntity = $entityService->createEntity(
                $this->_node->getNodeId(),
                'address',
                ($this->_node->isMultiStore() ? $customer['store_id'] : 0),
                $uniqueId, $data
            );
            $entityService->linkEntity($this->_node->getNodeId(), $addressEntity, $addressData['customer_address_id']);
        }else{
            $entityService->updateEntity($this->_node->getNodeId(), $addressEntity, $data, FALSE);
        }
        return $addressEntity;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param \Entity\Attribute[] $attributes
     * @param int $type
     * @return bool
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        return FALSE;

        // TODO: Implement writeUpdates() method.

        /** @var \Entity\Service\EntityService $entityService */
/*        $entityService = $this->getServiceLocator()->get('entityService');

        $additionalAttributes = $this->_node->getConfig('customer_attributes');
        if(is_string($additionalAttributes)){
            $additionalAttributes = explode(',', $additionalAttributes);
        }
        if(!$additionalAttributes || !is_array($additionalAttributes)){
            $additionalAttributes = array();
        }

        $data = array(
            'additional_attributes'=>array(
                'single_data'=>array(),
                'multi_data'=>array(),
            ),
        );

        foreach($attributes as $att){
            $v = $entity->getData($att);
            if(in_array($att, $additionalAttributes)){
                // Custom attribute
                if(is_array($v)){
                    // TODO implement
                    throw new MagelinkException('This gateway does not yet support multi_data additional attributes');
                }else{
                    $data['additional_attributes']['single_data'][] = array(
                        'key'=>$att,
                        'value'=>$v,
                    );
                }
                continue;
            }
            // Normal attribute
            switch($att){
                case 'name':
                case 'description':
                case 'short_description':
                case 'price':
                case 'special_price':
                    // Same name in both systems
                    $data[$att] = $v;
                    break;
                case 'special_from':
                    $data['special_from_date'] = $v;
                    break;
                case 'special_to':
                    $data['special_to_date'] = $v;
                    break;
                case 'customer_class':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // TODO log error (but no exception)
                    }
                    break;
                case 'type':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // TODO log error (but no exception)
                    }
                    break;
                case 'enabled':
                    $data['status'] = ($v == 1 ? 2 : 1);
                    break;
                case 'visible':
                    $data['status'] = ($v == 1 ? 4 : 1);
                    break;
                case 'taxable':
                    $data['status'] = ($v == 1 ? 2 : 1);
                    break;
                default:
                    // Warn unsupported attribute
                    break;
            }
        }

        if($type == \Entity\Update::TYPE_UPDATE){
            $req = array(
                $entity->getUniqueId(),
                $data,
                $entity->getStoreId(),
                'sku'
            );
            $this->_soap->call('catalogCustomerUpdate', $req);
        }else if($type == \Entity\Update::TYPE_CREATE){

            $attSet = NULL;
            foreach($this->_attSets as $setId=>$set){
                if($set['name'] == $entity->getData('customer_class', 'default')){
                    $attSet = $setId;
                    break;
                }
            }
            $req = array(
                $entity->getData('type'),
                $attSet,
                $entity->getUniqueId(),
                $data,
                $entity->getStoreId()
            );
            $res = $this->_soap->call('catalogCustomerCreate', $req);
            if(!$res){
                throw new MagelinkException('Error creating customer in Magento (' . $entity->getUniqueId() . '!');
            }
            $entityService->linkEntity($this->_node->getNodeId(), $entity, $res);
        }
*/
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool
     */
    public function writeAction(\Entity\Action $action)
    {
        return FALSE;

        /** @var \Entity\Service\EntityService $entityService */
/*        $entityService = $this->getServiceLocator()->get('entityService');

        $entity = $action->getEntity();

        switch($action->getType()){
            case 'delete':
                $this->_soap->call('catalogCustomerDelete', array($entity->getUniqueId(), 'sku'));
                break;
            default:
                throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Magento Orders.');
        }
*/
    }

}
