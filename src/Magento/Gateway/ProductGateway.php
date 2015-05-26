<?php
/**
 * Magento\Gateway\OrderGateway
 *
 * @category Magento
 * @package Magento\Gateway
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright(c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\SyncException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;
use Node\Entity;


class ProductGateway extends AbstractGateway
{

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */
    public function _init($entityType)
    {
        $success = FALSE;
        if ($entityType != 'product') {
            throw new GatewayException('Invalid entity type for this gateway');
        }else{
            try {
                $attributeSets = $this->_soap->call('catalogProductAttributeSetList',array());
                $success = TRUE;
            }catch (\Exception $exception) {
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }

            $this->_attributeSets = array();
            foreach ($attributeSets as $attributeSetArray) {
                $this->_attributeSets[$attributeSetArray['set_id']] = $attributeSetArray;
            }
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records(either from polling, pushed data, or other sources) .
     * @throws MagelinkException
     * @throws NodeException
     * @throws SyncException
     * @throws GatewayException
     */
    public function retrieve()
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');
        /** @var \Node\Service\NodeService $nodeService */
        $nodeService = $this->getServiceLocator()->get('nodeService');

        $timestamp = time() - $this->apiOverlappingSeconds;

        foreach ($this->_node->getStoreViews() as $storeId=>$storeView) {
            $lastRetrieve = date('Y-m-d H:i:s',
                $this->_nodeService->getTimestamp($this->_nodeEntity->getNodeId(), 'product', 'retrieve')
                    + (intval($this->_node->getConfig('time_delta_product')) * 3600)
            );

            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO,
                    'mag_retr_time',
                    'Retrieving products updated since '.$lastRetrieve,
                   array('type'=>'product', 'timestamp'=>$lastRetrieve)
                );

            if ($this->_db) {
                try {
                    $updatedProducts = $this->_db->getChangedEntityIds('catalog_product', $lastRetrieve);
                }catch (\Exception $exception) {
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }

                if (count($updatedProducts)) {

                    if ($storeId == 0) {
                        $storeId = FALSE;
                    }

                    $attributes = array(
                        'sku',
                        'name',
                        'attribute_set_id',
                        'type_id',
                        'description',
                        'short_description',
                        'status',
                        'visibility',
                        'price',
                        'tax_class_id',
                        'special_price',
                        'special_from_date',
                        'special_to_date',
                    );

                    $additional = $this->_node->getConfig('product_attributes');
                    if (is_string($additional)) {
                        $additional = explode(',', $additional);
                    }
                    if (!$additional || !is_array($additional)) {
                        $additional = array();
                    }

                    foreach ($additional as $key=>$attributeCode) {
                        if (!strlen(trim($attributeCode))) {
                            unset($additional[$key]);
                            continue;
                        }

                        if (!$entityConfigService->checkAttribute('product', $attributeCode)) {
                            $entityConfigService->createAttribute($attributeCode, $attributeCode, FALSE, 'varchar',
                                'product', 'Magento Additional Attribute');
                            try {
                                $nodeService->subscribeAttribute(
                                    $this->_node->getNodeId(), $attributeCode, 'product', TRUE);
                            }catch (\Exception $exception) {
                                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                            }
                        }
                    }

                    $attributes = array_merge($attributes, $additional);

                    $brands = FALSE;
                    if (in_array('brand', $attributes)) {
                        try{
                            $brands = $this->_db->loadEntitiesEav('brand', null, $storeId, array('name'));
                        }catch(\Exception $exception) {
                            $brands = FALSE;
                        }
                    }

                    try {
                        $productData = $this->_db->loadEntitiesEav('catalog_product',
                            $updatedProducts, $storeId, $attributes);
                    }catch (\Exception $exception) {
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }

                    foreach ($productData as $productId=>$rawData) {
                        $data = $this->convertFromMagento($rawData, $additional);

                        if ($brands && isset($data['brand']) && is_numeric($data['brand'])) {
                            if (isset($brands[intval($data['brand'])])) {
                                $data['brand'] = $brands[intval($data['brand'])]['name'];
                            }else {
                                $data['brand'] = null;
                            }
                        }

                        if (isset($this->_attributeSets[intval($rawData['attribute_set_id'])])) {
                            $data['product_class'] = $this->_attributeSets[intval(
                                $rawData['attribute_set_id']
                            )]['name'];
                        }else {
                            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN,
                                'mag_ukwn_set',
                                'Unknown attribute set ID '.$rawData['attribute_set_id'],
                                array('set' => $rawData['attribute_set_id'], 'sku' => $rawData['sku'])
                            );
                        }
                        $parentId = null; // TODO: Calculate
                        $sku = $rawData['sku'];

                        try {
                            $this->processUpdate($entityService, $productId, $sku, $storeId, $parentId, $data);
                        }catch (\Exception $exception) {
                            // store as sync issue
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                }

            }elseif ($this->_soap) {
                try {
                    $results = $this->_soap->call('catalogProductList', array(
                       array('complex_filter'=>array(array(
                            'key'=>'updated_at',
                            'value'=>array('key'=>'gt', 'value'=>$lastRetrieve),
                       ))),
                       $storeId, // storeView
                    ));
                }catch (\Exception $exception) {
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }

                foreach ($results as $product) {
                    $data = $product;
                    if ($this->_node->getConfig('load_full_product')) {
                        $data = array_merge(
                            $data,
                            $this->loadFullProduct($product['product_id'], $storeId, $entityConfigService)
                        );
                    }

                    if (isset($this->_attributeSets[intval($data['set']) ])) {
                        $data['product_class'] = $this->_attributeSets[intval($data['set']) ]['name'];
                        unset($data['set']);
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_WARN,
                                'mag_ukwn_set',
                                'Unknown attribute set ID '.$data['set'],
                               array('set'=>$data['set'], 'sku'=>$data['sku'])
                            );
                    }

                    if (isset($data[''])) {
                        unset($data['']);
                    }

                    unset($data['category_ids']); // TODO parse into categories
                    unset($data['website_ids']); // Not used

                    $productId = $data['product_id'];
                    $sku = $data['sku'];
                    unset($data['product_id']);
                    unset($data['sku']);

                    $parentId = null; // TODO: Calculate

                    try {
                        $this->processUpdate($entityService, $productId, $sku, $storeId, $parentId, $data);
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                }
            }else{
                throw new NodeException('No valid API available for sync');
            }
        }
        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'product', 'retrieve', $timestamp);
    }

    /**
     * @param \Entity\Service\EntityService $entityService
     * @param int $productId
     * @param string $sku
     * @param int $storeId
     * @param int $parentId
     * @param array $data
     * @return \Entity\Entity|null
     */
    protected function processUpdate(\Entity\Service\EntityService $entityService, $productId, $sku, $storeId,
        $parentId, $data) 
    {
        /** @var boolean $needsUpdate Whether we need to perform an entity update here */
        $needsUpdate = TRUE;

        $existingEntity = $entityService->loadEntityLocal($this->_node->getNodeId(), 'product', $storeId, $productId);
        if (!$existingEntity) {
            $existingEntity = $entityService->loadEntity($this->_node->getNodeId(), 'product', $storeId, $sku);
            if (!$existingEntity) {
                $existingEntity = $entityService->createEntity(
                    $this->_node->getNodeId(),
                    'product',
                    $storeId,
                    $sku,
                    $data,
                    $parentId
                );
                $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $productId);
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_ent_new',
                        'New product '.$sku,
                       array('sku'=>$sku),
                       array('node'=>$this->_node, 'entity'=>$existingEntity) 
                    );
                try{
                    $stockEntity = $entityService->createEntity(
                        $this->_node->getNodeId(),
                        'stockitem',
                        $storeId,
                        $sku,
                       array(),
                        $existingEntity
                    );
                    $entityService->linkEntity($this->_node->getNodeId(), $stockEntity, $productId);
                }catch (\Exception $exception) {
                    $this->getServiceLocator() ->get('logService') 
                        ->log(\Log\Service\LogService::LEVEL_WARN,
                            'mag_already_si',
                            'Already existing stockitem for new product '.$sku,
                           array('sku'=>$sku),
                           array('node'=>$this->_node, 'entity'=>$existingEntity) 
                        );
                }
                $needsUpdate = FALSE;
            }elseif ($entityService->getLocalId($this->_node->getNodeId(), $existingEntity) != NULL) {
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR,
                        'mag_ent_wronglink',
                        'Incorrectly linked product '.$sku,
                       array('code'=>$sku),
                       array('node'=>$this->_node, 'entity'=>$existingEntity) 
                    );
                $entityService->unlinkEntity($this->_node->getNodeId(), $existingEntity);
                $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $productId);

                $stockEntity = $entityService->loadEntity($this->_node->getNodeId(), 'stockitem', $storeId, $sku);
                if ($entityService->getLocalId($this->_node->getNodeId(), $stockEntity) != NULL) {
                    $entityService->unlinkEntity($this->_node->getNodeId(), $stockEntity);
                }
                $entityService->linkEntity($this->_node->getNodeId(), $stockEntity, $productId);
            }else{
                $this->getServiceLocator() ->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_INFO,
                        'mag_ent_link',
                        'Unlinked product '.$sku,
                       array('sku'=>$sku),
                       array('node'=>$this->_node, 'entity'=>$existingEntity) 
                    );
                $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $productId);
            }
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO,
                    'mag_ent_upd',
                    'Updated product '.$sku,
                   array('sku'=>$sku),
                   array('node'=>$this->_node, 'entity'=>$existingEntity) 
                );
        }

        if ($needsUpdate) {
            $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
        }

        return $existingEntity;
    }

    /**
     * Load detailed product data from Magento
     * @param $productId
     * @param $storeId
     * @param \Entity\Service\EntityConfigService $entityConfigService
     * @return array
     * @throws \Magelink\Exception\MagelinkException
     */
    public function loadFullProduct($productId, $storeId, \Entity\Service\EntityConfigService $entityConfigService) {

        $additional = $this->_node->getConfig('product_attributes');
        if (is_string($additional)) {
            $additional = explode(',', $additional);
        }
        if (!$additional || !is_array($additional)) {
            $additional = array();
        }

        $data = array(
            $productId,
            $storeId,
            array('additional_attributes'=>$additional),
            'id',
        );

        $productInfo = $this->_soap->call('catalogProductInfo', $data);

        if (!$productInfo && !$productInfo['sku']) {
            // store as sync issue
            throw new GatewayException('Invalid product info response');
            $data = NULL;
        }else {
            $data = $this->convertFromMagento($productInfo, $additional);

            foreach ($additional as $attributeCode) {
                $attributeCode = strtolower(trim($attributeCode));

                if (strlen($attributeCode)) {
                    if (!array_key_exists($attributeCode, $data)) {
                        $data[$attributeCode] = NULL;
                    }

                    if (!$entityConfigService->checkAttribute('product', $attributeCode)) {
                        $entityConfigService->createAttribute(
                            $attributeCode,
                            $attributeCode,
                            0,
                            'varchar',
                            'product',
                            'Custom Magento attribute'
                        );

                        try {
                            $this->getServiceLocator()->get('nodeService')->subscribeAttribute(
                                $this->_node->getNodeId(),
                                $attributeCode,
                                'product'
                            );
                        }catch (\Exception $exception) {
                            // Store as sync issue
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                }
            }
        }

        return $data;

    }

    /**
     * Converts Magento-named attributes into our internal Magelink attributes / formats
     * @param array $res Input array of Magento attribute codes
     * @param array $additional Additional product attributes to load in
     * @return array
     */
    protected function convertFromMagento($res, $additional) {
        $data = array();
        if (isset($res['type_id'])) {
            $data['type'] = $res['type_id'];
        }else{
            if (isset($res['type'])) {
                $data['type'] = $res['type'];
            }else{
                $data['type'] = null;
            }
        }
        if (isset($res['name'])) {
            $data['name'] = $res['name'];
        }else{
            $data['name'] = null;
        }
        if (isset($res['description'])) {
            $data['description'] = $res['description'];
        }else{
            $data['description'] = null;
        }
        if (isset($res['short_description'])) {
            $data['short_description'] = $res['short_description'];
        }else{
            $data['short_description'] = null;
        }
        if (isset($res['status'])) {
            $data['enabled'] =($res['status'] == 1) ? 1 : 0;
        }else{
            $data['enabled'] = 0;
        }
        if (isset($res['visibility'])) {
            $data['visible'] =($res['visibility'] == 4) ? 1 : 0;
        }else{
            $data['visible'] = 0;
        }
        if (isset($res['price'])) {
            $data['price'] = $res['price'];
        }else{
            $data['price'] = null;
        }
        if (isset($res['tax_class_id'])) {
            $data['taxable'] =($res['tax_class_id'] == 2) ? 1 : 0;
        }else{
            $data['taxable'] = 0;
        }
        if (isset($res['special_price'])) {
            $data['special_price'] = $res['special_price'];

            if (isset($res['special_from_date'])) {
                $data['special_from_date'] = $res['special_from_date'];
            }else{
                $data['special_from_date'] = null;
            }
            if (isset($res['special_to_date'])) {
                $data['special_to_date'] = $res['special_to_date'];
            }else{
                $data['special_to_date'] = null;
            }
        }else{
            $data['special_price'] = null;
            $data['special_from_date'] = null;
            $data['special_to_date'] = null;
        }

        if (isset($res['additional_attributes'])) {
            foreach ($res['additional_attributes'] as $pair) {
                $attributeCode = trim(strtolower($pair['key']));
                if (!in_array($attributeCode, $additional)) {
                    throw new GatewayException('Invalid attribute returned by Magento: '.$attributeCode);
                }
                if (isset($pair['value'])) {
                    $data[$attributeCode] = $pair['value'];
                }else{
                    $data[$attributeCode] = null;
                }
            }
        }else{
            foreach ($additional as $k) {
                if (isset($res[$k])) {
                    $data[$k] = $res[$k];
                }
            }
        }

        return $data;
    }

    /**
     * Restructure data for soap call and return this array
     * @param array $data
     * @param array $customAttributes
     * @return array $soapData
     * @throws \Magelink\Exception\MagelinkException
     */
    protected function getUpdateDataForSoapCall(array $data, array $customAttributes) 
    {
        // Restructure data for soap call
        $soapData = array(
            'additional_attributes'=>array(
                'single_data'=>array(),
                'multi_data'=>array() 
            ) 
        );
        $removeSingleData = $removeMultiData = TRUE;

        foreach ($data as $code=>$value) {
            $isCustomAttribute = in_array($code, $customAttributes);
            if ($isCustomAttribute) {
                if (is_array($data[$code])) {
                    // TODO(maybe) : Implement
                    throw new GatewayException("This gateway doesn't support multi_data custom attributes yet.");
                    $removeMultiData = FALSE;
                }else{
                    $soapData['additional_attributes']['single_data'][] = array(
                        'key'=>$code,
                        'value'=>$value,
                    );
                    $removeSingleData = FALSE;
                }
            }else{
                $soapData[$code] = $value;
            }
        }

        if ($removeSingleData) {
            unset($data['additional_attributes']['single_data']);
        }
        if ($removeMultiData) {
            unset($data['additional_attributes']['multi_data']);
        }
        if ($removeSingleData && $removeMultiData) {
            unset($data['additional_attributes']);
        }

        return $soapData;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE) 
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');

        $customAttributes = $this->_node->getConfig('product_attributes');
        if (is_string($customAttributes)) {
            $customAttributes = explode(',', $customAttributes);
        }
        if (!$customAttributes || !is_array($customAttributes)) {
            $customAttributes = array();
        }

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA,
                'mag_prod_write_update',
                'Attributes for update of product '.$entity->getUniqueId() .': '.var_export($attributes, TRUE),
               array('attributes'=>$attributes, 'custom'=>$customAttributes),
               array('entity'=>$entity) 
            );

        $data = array();
        $attributeCodes = array_unique(array_merge(
            //array('special_price', 'special_from_date', 'special_to_date'), // force update off these attributes
            //$customAttributes,
            $attributes
        ));

        foreach ($attributeCodes as $attributeCode) {

            if (strlen(trim($attributeCode)) == 0) {
                continue;
            }

            $value = $entity->getData($attributeCode);
            switch($attributeCode) {
                // Normal attributes
                case 'price':
                case 'special_price':
                case 'special_from_date':
                case 'special_to_date':
                    $value =($value ? $value : NULL);
                case 'name':
                case 'description':
                case 'short_description':
                case 'weight':
                // Custom attributes
                case 'barcode':
                case 'bin_location':
                case 'msrp':
                    // Same name in both systems
                    $data[$attributeCode] = $value;
                    break;

                case 'enabled':
                    $data['status'] =($value == 1 ? 2 : 1);
                    break;
                case 'visible':
                    $data['visibility'] =($value == 1 ? 4 : 1);
                    break;
                case 'taxable':
                    $data['tax_class_id'] =($value == 1 ? 2 : 1);
                    break;

                // ToDo(maybe) : Add logic for this custom attributes
                case 'brand':
                case 'size':
                    // Ignore attributes
                    break;

                case 'product_class':
                case 'type':
                    if ($type != \Entity\Update::TYPE_CREATE) {
                        // ToDo: Log error(but no exception) 
                    }else{
                        // Ignore attributes
                    }
                    break;
                default:
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_WARN,
                            'mag_prod_inv_data',
                            'Unsupported attribute for update of '.$entity->getUniqueId() .': '.$attributeCode,
                           array('attribute'=>$attributeCode),
                           array('entity'=>$entity) 
                        );
                    // Warn unsupported attribute
                    break;
            }
        }

        if (!count($data)) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_WARN,
                    'mag_prod_noupd',
                    'No update required for '.$entity->getUniqueId() .' but requested was '.implode(', ', $attributes),
                   array('attributes'=>$attributes),
                   array('entity'=>$entity) 
                );
        }

        $localId = $entityService->getLocalId($this->_node->getNodeId(), $entity);

        $data['website_ids'] = array($entity->getStoreId());
        if (count($this->_node->getStoreViews()) && $type != \Entity\Update::TYPE_DELETE) {

            foreach ($this->_node->getStoreViews() as $storeId=>$store_name) {
                if ($storeId == $entity->getStoreId()) {
                    continue;
                }

                $loadedEntity = $entityService->loadEntity(
                    $this->_node->getNodeId(), $entity->getType(), $storeId, $entity->getUniqueId());
                if ($loadedEntity) {
                    if (!in_array($loadedEntity->getStoreId(), $data['website_ids'])) {
                        $data['website_ids'][] = $loadedEntity->getStoreId();
                    }

                    if ($type == \Entity\Update::TYPE_CREATE && !$localId) {
                        $message = 'Product exists in other store, ';
                        $localId = $entityService->getLocalId($this->_node->getNodeId(), $loadedEntity);
                        if ($localId) {
                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_INFO,
                                    'prod_storedup',
                                    $message.'forcing local ID for '.$entity->getUniqueId() .' to '.$localId,
                                   array('local_id'=>$localId, 'loadedEntityId'=>$loadedEntity->getId()),
                                   array('entity'=>$entity) 
                                );
                            $entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);
                            $type = \Entity\Update::TYPE_UPDATE;
                            break;
                        }else{
                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_INFO,
                                    'prod_storenew',
                                    'Product exists in other store, but no local ID: '.$entity->getUniqueId(),
                                   array('loadedEntityId'=>$loadedEntity->getId()),
                                   array('entity'=>$entity) 
                                );
                        }
                    }
                }
            }
        }

        $soapData = $this->getUpdateDataForSoapCall($data, $customAttributes);
        if ($type == \Entity\Update::TYPE_UPDATE || $localId) {

            $logData = array(
                'type'=>$entity->getData('type'),
                'websites'=>$data['website_ids'],
                'data keys'=>array_keys($data),
                'data'=>$data
            );

            $updateViaDbApi =  $this->_db && $localId;
            if ($updateViaDbApi) {
                $message = 'DB';
            }else{
                $message = 'SOAP';
                $logData['soap data keys'] = array_keys($soapData);
                $logData['soap data'] = $soapData;

            }

            $message = 'Updating product('.$message.') : '
                .$entity->getUniqueId() .' with '.implode(', ', array_keys($data));
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO, 'mag_prod_update', $message, $logData);

            if ($updateViaDbApi) {
                $tablePrefix = 'catalog_product';
                $this->_db->updateEntityEav(
                    $tablePrefix,
                    $localId,
                    $entity->getStoreId(),
                    $data
                );
            }else{
                $request = array(
                    $entity->getUniqueId(),
                    $soapData,
                    $entity->getStoreId(),
                    'sku'
                );
                $this->_soap->call('catalogProductUpdate', $request);
            }
        }elseif ($type == \Entity\Update::TYPE_CREATE ) {

            $attributeSet = NULL;
            foreach ($this->_attributeSets as $setId=>$set) {
                if ($set['name'] == $entity->getData('product_class', 'default')) {
                    $attributeSet = $setId;
                    break;
                }
            }
            if ($attributeSet === NULL) {
                $message = 'Invalid product class '.$entity->getData('product_class', 'default');
                throw new \Magelink\Exception\SyncException($message);
            }

            $message = 'Creating product(SOAP) : '.$entity->getUniqueId() .' with '.implode(', ', array_keys($data));
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO,
                    'mag_prod_create',
                    $message,
                   array(
                        'type'=>$entity->getData('type'),
                        'websites'=>$data['website_ids'],
                        'set'=>$attributeSet,
                        'data keys'=>array_keys($data),
                        'data'=>$data,
                        'soap data keys'=>array_keys($soapData),
                        'soap data'=>$soapData
                    ) 
                );

            $request = array(
                $entity->getData('type'),
                $attributeSet,
                $entity->getUniqueId(),
                $soapData,
                $entity->getStoreId() 
            );

            try{
                $soapResult = $this->_soap->call('catalogProductCreate', $request);
                $soapFault = NULL;
            }catch(\SoapFault $soapFault) {
                $soapResult = FALSE;
                if ($soapFault->getMessage() == 'The value of attribute "SKU" must be unique') {
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_WARN,
                            'prod_dupfault',
                            'Creating product '.$entity->getUniqueId() .' hit SKU duplicate fault',
                           array(),
                           array('entity'=>$entity) 
                        );

                    $check = $this->_soap->call('catalogProductInfo',array(
                        $entity->getUniqueId(),
                        0, // store ID
                       array(),
                        'sku'
                    ));

                    if (!$check || !count($check)) {
                        throw new MagelinkException('Magento complained duplicate SKU but we cannot find a duplicate!');

                    }else{
                        $found = FALSE;
                        foreach ($check as $row) {
                            if ($row['sku'] == $entity->getUniqueId()) {
                                $found = TRUE;

                                $entityService->linkEntity($this->_node->getNodeId(), $entity, $row['product_id']);
                                $this->getServiceLocator()->get('logService')
                                    ->log(LogService::LEVEL_WARN,
                                        'prod_dupres',
                                        'Creating product '.$entity->getUniqueId() .' resolved SKU duplicate fault',
                                       array('local_id'=>$row['product_id']),
                                       array('entity'=>$entity) 
                                    );
                            }
                        }

                        if (!$found) {
                            $message = 'Magento found duplicate SKU '.$entity->getUniqueId() 
                                .' but we could not replicate. Database fault?';
                            throw new MagelinkException($message);
                        }
                    }
                }
            }

            if (!$soapResult) {
                $message = 'Error creating product in Magento('.$entity->getUniqueId() .'!';
                throw new MagelinkException($message, 0, $soapFault);
            }

            $entityService->linkEntity($this->_node->getNodeId(), $entity, $soapResult);
        }
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action) 
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $entity = $action->getEntity();

        switch($action->getType()) {
            case 'delete':
                $this->_soap->call('catalogProductDelete',array($entity->getUniqueId(), 'sku'));
                return TRUE;
                break;
            default:
                throw new MagelinkException('Unsupported action type '.$action->getType() .' for Magento Orders.');
        }
    }

}