<?php
/**
 * Magento\Gateway\OrderGateway
 * @category Magento
 * @package Magento\Gateway
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright(c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Magento\Service\MagentoService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\SyncException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;
use Node\Entity;


class ProductGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'product';


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'product') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }else{
            try {
                $attributeSets = $this->_soap->call('catalogProductAttributeSetList',array());
            }catch (\Exception $exception) {
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                $success = FALSE;
            }

            $this->_attributeSets = array();
            foreach ($attributeSets as $attributeSetArray) {
                $this->_attributeSets[$attributeSetArray['set_id']] = $attributeSetArray;
            }
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records(either from polling, pushed data, or other sources).
     * @throws MagelinkException
     * @throws NodeException
     * @throws SyncException
     * @throws GatewayException
     */
    public function retrieve()
    {
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $timestamp = $this->getNewRetrieveTimestamp();
        $lastRetrieve = $this->getLastRetrieveDate();

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mag_p_re_time',
                'Retrieving products updated since '.$lastRetrieve,
               array('type'=>'product', 'timestamp'=>$lastRetrieve)
            );

        $additional = $this->_node->getConfig('product_attributes');
        if (is_string($additional)) {
            $additional = explode(',', $additional);
        }
        if (!$additional || !is_array($additional)) {
            $additional = array();
        }

        if ($this->_db) {
            try {
                $updatedProducts = $this->_db->getChangedEntityIds('catalog_product', $lastRetrieve);
            }catch (\Exception $exception) {
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }

            if (count($updatedProducts)) {
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
                    'special_to_date'
                );

                foreach ($additional as $key=>$attributeCode) {
                    if (!strlen(trim($attributeCode))) {
                        unset($additional[$key]);
                    }elseif (!$entityConfigService->checkAttribute('product', $attributeCode)) {
                        $entityConfigService->createAttribute(
                            $attributeCode,
                            $attributeCode,
                            FALSE,
                            'varchar',
                            'product',
                            'Magento Additional Attribute'
                        );
                        try{
                            $this->_nodeService->subscribeAttribute(
                                $this->_node->getNodeId(),
                                $attributeCode,
                                'product',
                                TRUE
                            );
                        }catch(\Exception $exception) {
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                }
                $attributes = array_merge($attributes, $additional);

                foreach ($updatedProducts as $localId) {
                    $sku = NULL;
                    $combinedData = array();
                    $storeIds = array_keys($this->_node->getStoreViews());

                    foreach ($storeIds as $storeId) {
                        if ($storeId == 0) {
                            $storeId = FALSE;
                        }

                        $brands = FALSE;
                        if (in_array('brand', $attributes)) {
                            try{
                                $brands = $this->_db->loadEntitiesEav('brand', NULL, $storeId, array('name'));
                                if (!is_array($brands) || count($brands) == 0) {
                                    $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_WARN,
                                        'mag_p_db_nobrnds',
                                        'Something is wrong with the brands retrieval.',
                                        array('brands'=>$brands)
                                    );
                                    $brands = FALSE;
                                }
                            }catch( \Exception $exception ){
                                $brands = FALSE;
                            }
                        }

                        try{
                            $productsData = $this->_db
                                ->loadEntitiesEav('catalog_product', array($localId), $storeId, $attributes);
                        }catch(\Exception $exception) {
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }

                        foreach ($productsData as $productId=>$rawData) {
                            // ToDo: Combine this two methods into one
                            $productData = $this->convertFromMagento($rawData, $additional);
                            $productData = $this->getServiceLocator()->get('magentoService')
                                ->mapProductData($productData, $storeId);

                            if (is_array($brands) && isset($rawData['brand']) && is_numeric($rawData['brand'])) {
                                if (isset($brands[intval($rawData['brand'])])) {
                                    $productData['brand'] = $brands[intval($rawData['brand'])]['name'];
                                }else{
                                    $productData['brand'] = NULL;
                                    $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_WARN,
                                        'mag_p_db_nomabra',
                                        'Could not find matching brand for product '.$sku.'.',
                                        array('brand (key)'=>$rawData['brand'], 'brands'=>$brands)
                                    );
                                }
                            }

                            if (isset($rawData['attribute_set_id'])
                                    && isset($this->_attributeSets[intval($rawData['attribute_set_id'])])) {
                                $productData['product_class'] = $this->_attributeSets[intval(
                                    $rawData['attribute_set_id']
                                )]['name'];
                            }else{
                                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_WARN,
                                    'mag_p_db_noset',
                                    'Issue with attribute set id on product '.$sku.'. Check $rawData[attribute_set_id].',
                                    array('raw data'=>$rawData)
                                );
                            }
                        }

                        if (count($combinedData) == 0) {
                            $sku = $rawData['sku'];
                            $combinedData = $productData;
                        }else {
                            $combinedData = array_replace_recursive($combinedData, $productData, $combinedData);
                        }
                    }

                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_DEBUGEXTRA, 'mag_p_db_comb',
                            'Combined data for Magento product id '.$localId.'.',
                            array('combined data'=>$combinedData)
                        );

                    $parentId = NULL; // TODO: Calculate

                    try{
                        $this->processUpdate($productId, $sku, $storeId, $parentId, $combinedData);
                    }catch( \Exception $exception ){
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
                   $storeId = NULL, // storeView
                ));
            }catch (\Exception $exception) {
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }

            foreach ($results as $productData) {
                $productId = $productData['product_id'];

                $productInfo = $this->_soap->call('catalogProductInfo', array($productId));
                $productInfoData = array();

                if ($productInfo) {
                    if (isset($productInfo['result'])) {
                        $productInfo = $productInfo['result'];
                    }

                    foreach ($additional as $attributeCode) {
                        if (strlen(trim($attributeCode)) && isset($productInfo[$attributeCode])) {
                            $productInfoData[$attributeCode] = $productInfo[$attributeCode];
                        }
                    }
                }

                if ($this->_node->getConfig('load_full_product')) {
                    $productData = array_merge($productData, $productInfoData);
                    $productData = $this->getServiceLocator()->get('magentoService')
                        ->mapProductData($productData, $storeId);

                    $productData = array_merge(
                        $productData,
                        $this->loadFullProduct($productId, $storeId, $entityConfigService)
                    );
                }

                if (isset($this->_attributeSets[intval($productData['set']) ])) {
                    $productData['product_class'] = $this->_attributeSets[intval($productData['set']) ]['name'];
                    unset($productData['set']);
                }else{
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_WARN,
                            'mag_p_soap_uset',
                            'Unknown attribute set ID '.$productData['set'],
                           array('set'=>$productData['set'], 'sku'=>$productData['sku'])
                        );
                }

                if (isset($productData[''])) {
                    unset($productData['']);
                }

                unset($productData['category_ids']); // TODO parse into categories
                unset($productData['website_ids']); // Not used

                $productId = $productData['product_id'];
                $parentId = NULL; // TODO: Calculate
                $sku = $productData['sku'];
                unset($productData['product_id']);
                unset($productData['sku']);

                try {
                    $this->processUpdate($productId, $sku, $storeId, $parentId, $productData);
                }catch (\Exception $exception) {
                    // store as sync issue
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }
            }
        }else{
            throw new NodeException('No valid API available for sync');
        }

        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'product', 'retrieve', $timestamp);
    }

    /**
     * @param int $productId
     * @param string $sku
     * @param int $storeId
     * @param int $parentId
     * @param array $data
     * @return \Entity\Entity|NULL
     */
    protected function processUpdate($productId, $sku, $storeId, $parentId, array $data)
    {
        /** @var boolean $needsUpdate Whether we need to perform an entity update here */
        $needsUpdate = TRUE;

        $existingEntity = $this->_entityService->loadEntityLocal($this->_node->getNodeId(), 'product', 0, $productId);
        if (!$existingEntity) {
            $existingEntity = $this->_entityService->loadEntity($this->_node->getNodeId(), 'product', 0, $sku);
            $noneOrWrongLocalId = $this->_entityService->getLocalId($this->_node->getNodeId(), $existingEntity);

            if (!$existingEntity) {
                $existingEntity = $this->_entityService
                    ->createEntity($this->_node->getNodeId(), 'product', 0, $sku, $data, $parentId);
                $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $productId);
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_p_new',
                        'New product '.$sku,
                       array('sku'=>$sku),
                       array('node'=>$this->_node, 'entity'=>$existingEntity) 
                    );
                try{
                    $stockEntity = $this->_entityService
                        ->createEntity($this->_node->getNodeId(), 'stockitem',0, $sku, array(), $existingEntity);
                    $this->_entityService->linkEntity($this->_node->getNodeId(), $stockEntity, $productId);
                }catch (\Exception $exception) {
                    $this->getServiceLocator() ->get('logService') 
                        ->log(LogService::LEVEL_WARN,
                            'mag_p_si_ex',
                            'Already existing stockitem for new product '.$sku,
                           array('sku'=>$sku),
                           array('node'=>$this->_node, 'entity'=>$existingEntity) 
                        );
                }
                $needsUpdate = FALSE;
            }elseif ($noneOrWrongLocalId != NULL) {
                $this->_entityService->unlinkEntity($this->_node->getNodeId(), $existingEntity);
                $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $productId);

                $stockEntity = $this->_entityService->loadEntity($this->_node->getNodeId(), 'stockitem', $storeId, $sku);
                if ($this->_entityService->getLocalId($this->_node->getNodeId(), $stockEntity) != NULL) {
                    $this->_entityService->unlinkEntity($this->_node->getNodeId(), $stockEntity);
                }
                $this->_entityService->linkEntity($this->_node->getNodeId(), $stockEntity, $productId);

                $this->getServiceLocator() ->get('logService')
                    ->log(LogService::LEVEL_ERROR,
                        'mag_p_relink',
                        'Incorrectly linked product '.$sku.' ('.$noneOrWrongLocalId.'). Re-linked now.',
                       array('code'=>$sku, 'wrong local id'=>$noneOrWrongLocalId),
                       array('node'=>$this->_node, 'entity'=>$existingEntity)
                    );
            }else{
                $this->getServiceLocator() ->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_p_link',
                        'Unlinked product '.$sku,
                       array('sku'=>$sku),
                       array('node'=>$this->_node, 'entity'=>$existingEntity) 
                    );
                $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $productId);
            }
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO,
                    'mag_p_upd',
                    'Updated product '.$sku,
                   array('sku'=>$sku),
                   array('node'=>$this->_node, 'entity'=>$existingEntity, 'data'=>$data)
                );
        }

        if ($needsUpdate) {
            $this->_entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
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
        }else{
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
                            $data = NULL;
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Converts Magento-named attributes into our internal Magelink attributes / formats.
     * @param array $rawData Input array of Magento attribute codes
     * @param array $additional Additional product attributes to load in
     * @return array
     */
    protected function convertFromMagento($rawData, $additional) {
        $data = array();
        if (isset($rawData['type_id'])) {
            $data['type'] = $rawData['type_id'];
        }else{
            if (isset($rawData['type'])) {
                $data['type'] = $rawData['type'];
            }else{
                $data['type'] = NULL;
            }
        }
        if (isset($rawData['name'])) {
            $data['name'] = $rawData['name'];
        }else{
            $data['name'] = NULL;
        }
        if (isset($rawData['description'])) {
            $data['description'] = $rawData['description'];
        }else{
            $data['description'] = NULL;
        }
        if (isset($rawData['short_description'])) {
            $data['short_description'] = $rawData['short_description'];
        }else{
            $data['short_description'] = NULL;
        }
        if (isset($rawData['status'])) {
            $data['enabled'] =($rawData['status'] == 1) ? 1 : 0;
        }else{
            $data['enabled'] = 0;
        }
        if (isset($rawData['visibility'])) {
            $data['visible'] =($rawData['visibility'] == 4) ? 1 : 0;
        }else{
            $data['visible'] = 0;
        }
        if (isset($rawData['price'])) {
            $data['price'] = $rawData['price'];
        }else{
            $data['price'] = NULL;
        }
        if (isset($rawData['tax_class_id'])) {
            $data['taxable'] =($rawData['tax_class_id'] == 2) ? 1 : 0;
        }else{
            $data['taxable'] = 0;
        }
        if (isset($rawData['special_price'])) {
            $data['special_price'] = $rawData['special_price'];

            if (isset($rawData['special_from_date'])) {
                $data['special_from_date'] = $rawData['special_from_date'];
            }else{
                $data['special_from_date'] = NULL;
            }
            if (isset($rawData['special_to_date'])) {
                $data['special_to_date'] = $rawData['special_to_date'];
            }else{
                $data['special_to_date'] = NULL;
            }
        }else{
            $data['special_price'] = NULL;
            $data['special_from_date'] = NULL;
            $data['special_to_date'] = NULL;
        }

        if (isset($rawData['additional_attributes'])) {
            foreach ($rawData['additional_attributes'] as $pair) {
                $attributeCode = trim(strtolower($pair['key']));
                if (!in_array($attributeCode, $additional)) {
                    throw new GatewayException('Invalid attribute returned by Magento: '.$attributeCode);
                }
                if (isset($pair['value'])) {
                    $data[$attributeCode] = $pair['value'];
                }else{
                    $data[$attributeCode] = NULL;
                }
            }
        }else{
            foreach ($additional as $code) {
                if (isset($rawData[$code])) {
                    $data[$code] = $rawData[$code];
                }
            }
        }

        return $data;
    }

    /**
     * Restructure data for soap call and return this array.
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
        $nodeId = $this->_node->getNodeId();
        $sku = $entity->getUniqueId();

        $customAttributes = $this->_node->getConfig('product_attributes');
        if (is_string($customAttributes)) {
            $customAttributes = explode(',', $customAttributes);
        }
        if (!$customAttributes || !is_array($customAttributes)) {
            $customAttributes = array();
        }

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA,
                'mag_p_wrupd',
                'Attributes for update of product '.$sku.': '.var_export($attributes, TRUE),
               array('attributes'=>$attributes, 'custom'=>$customAttributes),
               array('entity'=>$entity) 
            );

        $originalData = $entity->getFullArrayCopy();
        $attributeCodes = array_unique(array_merge(
            //array('special_price', 'special_from_date', 'special_to_date'), // force update off these attributes
            //$customAttributes,
            $attributes
        ));

        foreach ($originalData as $attributeCode=>$attributeValue) {
            if (!in_array($attributeCode, $attributeCodes)) {
                unset($originalData[$attributeCode]);
            }
        }

        $data = array();
        if (count($originalData) == 0) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO,
                    'mag_p_wrupd_non',
                    'No update required for '.$sku.' but requested was '.implode(', ', $attributes),
                    array('attributes'=>$attributes),
                    array('entity'=>$entity)
                );
        }else{
            /** @var MagentoService $magentoService */
            $magentoService = $this->getServiceLocator()->get('magentoService');
            foreach ($originalData as $code=>$value) {
                $mappedCode = $magentoService->getMappedCode('product', $code, FALSE);
                switch ($mappedCode) {
                    // Normal attributes
                    case 'price':
                    case 'special_price':
                    case 'special_from_date':
                    case 'special_to_date':
                        $value = ($value ? $value : NULL);
                    case 'name':
                    case 'description':
                    case 'short_description':
                    case 'weight':
                    // Custom attributes
                    case 'barcode':
                    case 'bin_location':
                    case 'msrp':
                        // Same name in both systems
                        $data[$code] = $value;
                        break;
                    case 'enabled':
                        $data['status'] =($value == 1 ? 2 : 1);
                        break;
                    case 'visible':
                        $data['visibility'] = ($value == 1 ? 4 : 1);
                        break;
                    case 'taxable':
                        $data['tax_class_id'] = ($value == 1 ? 2 : 1);
                        break;
                    // ToDo (maybe) : Add logic for this custom attributes
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
                                'mag_p_wr_invdata',
                                'Unsupported attribute for update of '.$sku.': '.$attributeCode,
                               array('attribute'=>$attributeCode),
                               array('entity'=>$entity)
                            );
                        // Warn unsupported attribute
                }
            }

            $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_DEBUGINTERNAL, 'mag_p_wrupdmap',
                'Mapped, filtered, prepared: '.json_encode($originalData).' to '.json_encode($data).'.', array());
            unset($originalData);

            $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $entity);

            $storeDataByStoreId = $this->_node->getStoreViews();
            if (count($storeDataByStoreId) > 0 && $type != \Entity\Update::TYPE_DELETE) {
                $dataPerStore[0] = $data;
                foreach (array('price', 'msrp') as $code) {
                    if (array_key_exists($code, $data)) {
                        $data[$code.'_default'] = $data[$code];
                        unset($data[$code]);
                    }
                }

                $websiteIds = array();
                foreach ($storeDataByStoreId as $storeId=>$storeData) {
                    $dataPerStore[$storeId] = $magentoService->mapProductData($data, $storeId, FALSE, TRUE);
                    if (isset($dataPerStore[$storeId]['price'])) {
                        $websiteIds[] = $storeData['website_id'];
                        $logCode = 'mag_p_wrupd_wen';
                        $logMessage = 'enabled';
                        $logData = array('store id'=>$storeId, 'data'=>$dataPerStore[$storeId]);
                    }else{
                        $logCode = 'mag_p_wrupd_wdis';
                        $logMessage = 'disabled';
                        $logData = array();
                    }
                    $logMessage = 'Product '.$sku.' is '.$logMessage.' on website '.$storeData['website_id'].'.';
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_DEBUGINTERNAL, $logCode, $logMessage, $logData);
                }

                $storeIds = array_merge(array(0), array_keys($storeDataByStoreId));
                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_DEBUGINTERNAL,
                    'mag_p_wrupd_stor',
                    'StoreIds '.json_encode($storeIds).' (type: '.$type.'), websiteIds '.json_encode($websiteIds).'.',
                    array('store data'=>$storeDataByStoreId)
                );

                foreach ($storeIds as $storeId) {
                    $productData = $dataPerStore[$storeId];
                    $productData['website_ids'] = $websiteIds;

                    $soapData = $this->getUpdateDataForSoapCall($productData, $customAttributes);
                    $logData = array(
                        'type'=>$entity->getData('type'),
                        'store id'=>$storeId,
                        'product data'=>$productData,
                    );
                    $soapResult = NULL;

                    $updateViaDbApi = ($this->_db && $localId && $storeId == 0);
                    if ($updateViaDbApi) {
                        $api = 'DB';
                    }else{
                        $api = 'SOAP';
                    }

                    if ($type == \Entity\Update::TYPE_UPDATE || $localId) {
                        if ($updateViaDbApi) {
                            try{
                                $tablePrefix = 'catalog_product';
                                $rowsAffected = $this->_db->updateEntityEav(
                                    $tablePrefix,
                                    $localId,
                                    $entity->getStoreId(),
                                    $productData
                                );

                                if ($rowsAffected != 1) {
                                    throw new MagelinkException($rowsAffected.' rows affected.');
                                }
                            }catch(\Exception $exception) {
                                $this->_entityService->unlinkEntity($nodeId, $entity);
                                $localId = NULL;
                                $updateViaDbApi = FALSE;
                            }
                        }

                        $logMessage = 'Updated product '.$sku.' on store '.$storeId.' ';
                        if ($updateViaDbApi) {
                            $logLevel = LogService::LEVEL_INFO;
                            $logCode = 'mag_p_wrupddb';
                            $logMessage .= 'successfully via DB api with '.implode(', ', array_keys($productData));
                        }else{
                            $request = array($sku, $soapData, $storeId, 'sku');
                            $soapResult = $this->_soap->call('catalogProductUpdate', $request);

                            $logLevel = ($soapResult ? LogService::LEVEL_INFO : LogService::LEVEL_ERROR);
                            $logCode = 'mag_p_wrupdsoap';
                            if ($api != 'SOAP') {
                                $logMessage = $api.' update failed. Removed local id '.$localId
                                    .' from node '.$nodeId.'. '.$logMessage;
                                if (isset($exception)) {
                                    $logData[strtolower($api.' error')] = $exception->getMessage();
                                }
                            }

                            $logMessage .= ($soapResult ? 'successfully' : 'without success').' via SOAP api.';
                            $logData['soap data'] = $soapData;
                        }
                        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
                    }elseif ($type == \Entity\Update::TYPE_CREATE) {

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

                        $message = 'Creating product(SOAP) : '.$sku.' with '.implode(', ', array_keys($productData));
                        $logData['set'] = $attributeSet;
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO, 'mag_p_wr_cr', $message, $logData);

                        $request = array(
                            $entity->getData('type'),
                            $attributeSet,
                            $sku,
                            $soapData,
                            $entity->getStoreId()
                        );

                        try{
                            $soapResult = $this->_soap->call('catalogProductCreate', $request);
                            $soapFault = NULL;
                        }catch(\SoapFault $soapFault){
                            $soapResult = FALSE;
                            if ($soapFault->getMessage() == 'The value of attribute "SKU" must be unique') {
                                $this->getServiceLocator()->get('logService')
                                    ->log(LogService::LEVEL_WARN,
                                        'mag_p_wr_duperr',
                                        'Creating product '.$sku.' hit SKU duplicate fault',
                                        array(),
                                        array('entity'=>$entity)
                                    );

                                $check = $this->_soap->call('catalogProductInfo', array($sku, 0, array(), 'sku'));
                                if (!$check || !count($check)) {
                                    throw new MagelinkException(
                                        'Magento complained duplicate SKU but we cannot find a duplicate!'
                                    );

                                }else{
                                    $found = FALSE;
                                    foreach ($check as $row) {
                                        if ($row['sku'] == $sku) {
                                            $found = TRUE;

                                            $this->_entityService->linkEntity($nodeId, $entity, $row['product_id']);
                                            $this->getServiceLocator()->get('logService')
                                                ->log(LogService::LEVEL_INFO,
                                                    'mag_p_wr_dupres',
                                                    'Creating product '.$sku.' resolved SKU duplicate fault',
                                                    array('local_id'=>$row['product_id']),
                                                    array('entity'=>$entity)
                                                );
                                        }
                                    }

                                    if (!$found) {
                                        $message = 'Magento found duplicate SKU '.$sku.' but we could not replicate. Database fault?';
                                        throw new MagelinkException($message);
                                    }
                                }
                            }
                        }

                        if ($soapResult) {
                            $this->_entityService->linkEntity($nodeId, $entity, $soapResult);
                            $this->getServiceLocator()->get('logService')->log(
                                LogService::LEVEL_INFO,
                                'mag_p_wr_loc_id',
                                'Added product local id for '.$sku.' ('.$nodeId.')',
                                $logData
                            );
                        }else{
                            $message = 'Error creating product '.$sku.' in Magento!';
                            throw new MagelinkException($message, 0, $soapFault);
                        }
                    }
                }
                unset($dataPerStore);
            }
        }
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action) 
    {
        $entity = $action->getEntity();
        switch($action->getType()) {
            case 'delete':
                $this->_soap->call('catalogProductDelete',array($entity->getUniqueId(), 'sku'));
                $success = TRUE;
                break;
            default:
                throw new MagelinkException('Unsupported action type '.$action->getType().' for Magento Orders.');
                $success = FALSE;
        }

        return $success;
    }

}