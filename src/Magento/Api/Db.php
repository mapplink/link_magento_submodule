<?php

namespace Magento\Api;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Magento\Node;
use Magelink\Exception\MagelinkException;
use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGateway;


/**
 * Implements DB access to Magento - loading and updating
 * @package Magento\Api
 */
class Db implements ServiceLocatorAwareInterface
{
    /** @var bool $_debug */
    protected $_debug = TRUE;

    /** @var \Zend\Db\Adapter\Adapter $_adapter */
    protected $_adapter;

    /** @var bool $_enterprise */
    protected $_enterprise = FALSE;

    /** @var \Magento\Node */
    protected $_node;

    /** @var array  */
    protected $columns = NULL; /*array(
        'entity_id',
        'status',
        'store_id',
        'customer_id',
        'grand_total',
        'shipping_amount',
        'discount_amount',
        'discount_canceled',
        'discount_invoiced',
        'discount_refunded',
        'shipping_canceled',
        'shipping_invoiced',
        'shipping_refunded',
        'shipping_tax_amount',
        'shipping_tax_refunded',
        'subtotal',
        'tax_amount',
        'tax_canceled',
        'tax_invoiced',
        'tax_refunded',
        'total_canceled',
        'total_invoiced',
        'total_offline_refunded',
        'total_online_refunded',
        'total_paid',
        'total_qty_ordered',
        'customer_is_guest',
        'billing_address_id',
        'customer_group_id',
        'edit_increment',
        'quote_address_id',
        'quote_id',
        'shipping_address_id',
        'adjustment_negative',
        'adjustment_positive',
        'shipping_discount_amount',
        'total_due',
        'weight',
        'customer_dob',
        'increment_id',
        'applied_rule_ids',
        'base_currency_code',
        'customer_email',
        'customer_firstname',
        'customer_lastname',
        'customer_middlename',
        'customer_prefix',
        'customer_suffix',
        'discount_description',
        'order_currency_code',
        'original_increment_id',
        'shipping_method',
        'store_name',
        'store_currency_code',
        'customer_note',
        'created_at',
        'updated_at',
        'total_item_count',
        'customer_gender',
        'gift_message_id',
    );*/


    /**
     * Initialize the DB API
     * @param Node $magentoNode
     * @return bool Whether we succeeded
     */
    public function init(Node $magentoNode)
    {
        $success = TRUE;

        $this->_node = $magentoNode;
        $this->_enterprise = $magentoNode->getConfig('enterprise');

        $hostname = $magentoNode->getConfig('db_hostname');
        $schema = $magentoNode->getConfig('db_schema');
        $username = $magentoNode->getConfig('db_username');
        $password = $magentoNode->getConfig('db_password');

        if (!$schema || !$hostname){
            $success = FALSE;
        }else {
            try{
                $this->_adapter = new \Zend\Db\Adapter\Adapter(
                    array(
                        'driver' => 'Pdo',
                        'dsn' => 'mysql:dbname='.$schema.';host='.$hostname,
                        'username' => $username,
                        'password' => $password,
                        'driver_options' => array(
                            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''
                        ),
                    )
                );
                $this->_adapter->getCurrentSchema();

                /*if ($this->_enterprise) {
                    $this->columns = array_merge(
                        $this->columns,
                        array(
                            'customer_balance_amount',
                            'customer_balance_invoiced',
                            'customer_balance_refunded',
                            'customer_bal_total_refunded',
                            'gift_cards',
                            'gift_cards_amount',
                            'gift_cards_invoiced',
                            'gift_cards_refunded',
                            'gw_id',
                            'gw_add_card',
                            'gw_price',
                            'gw_items_price',
                            'gw_card_price',
                            'gw_tax_amount',
                            'gw_items_tax_amount',
                            'gw_card_tax_amount',
                            'gw_price_invoiced',
                            'gw_items_price_invoiced',
                            'gw_tax_amount_invoiced',
                            'gw_items_tax_invoiced',
                            'gw_card_tax_invoiced',
                            'gw_price_refunded',
                            'gw_items_price_refunded',
                            'gw_tax_amount_refunded',
                            'gw_items_tax_refunded',
                            'gw_card_tax_refunded',
                            'reward_points_balance',
                            'reward_currency_amount',
                            'rwrd_currency_amount_invoiced',
                            'rwrd_crrncy_amnt_refnded',
                            'reward_points_balance_refund',
                            'reward_points_balance_refunded',
                            'reward_salesrule_points',
                        )
                    );
                }*/
            }catch(\Exception $exception){
                $success = FALSE;
                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_DEBUG,
                        'init_fail',
                        'DB API init failed - '.$exception->getMessage(),
                        array('hostname'=>$hostname, 'schema'=>$schema, 'message'=>$exception->getMessage()),
                        array('node'=>$magentoNode->getNodeId(), 'exception'=>$exception)
                    );
            }
        }

        return $success;
    }

    /**
     * @param $sql
     */
    protected function debugSql($sql)
    {
        if ($this->_debug) {
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                    'dbapi_sql',
                    'DB API SQL: '.$sql,
                    array('sql'=>$sql),
                    array('node'=>$this->_node->getNodeId())
                );
        }
    }

    protected function getOrdersFromDatabase(\Zend\Db\Sql\Select $select)
    {
        if ($this->columns) {
            $select->columns($this->columns);
        }
        $results = $this->getTableGateway('sales_flat_order')->selectWith($select);

        $data = array();
        foreach ($results as $row) {
            $data[$row['entity_id']] = $row;
        }

        return $data;
    }

    /**
     * Retrieve one magento order by increment id
     * @param string $orderIncrementId
     * @return array
     */
    public function getOrderByIncrementId($orderIncrementId)
    {
        $select = new \Zend\Db\Sql\Select('sales_flat_order');
        $select->where(array('increment_id'=>array($orderIncrementId)));

        $data = $this->getOrdersFromDatabase($select);
        if (count($data)) {
            $data = array_shift($data);
        }else{
            $data = NULL;
        }

        return $data;
    }

    /**
     * Retrieve some or all magento orders, optionally filtering by an updated at date.
     *
     * @param int|bool $storeId The store ID to look at, or false if irrelevant
     * @param array|bool $orderIds
     * @param bool|string $updatedSince
     * @return array
     */
    public function getOrders($storeId = FALSE, $orderIds = FALSE, $updatedSince = FALSE)
    {
        $select = new \Zend\Db\Sql\Select('sales_flat_order');

        if ($storeId !== FALSE) {
            $select->where(array('store_id'=>$storeId));
        }
        if (is_array($orderIds)) {
            $select->where(array('entity_id'=>$orderIds));
        }
        if ($updatedSince) {
            $select->where("updated_at > '".$updatedSince."'");
        }

        $ordersDataArray = $this->getOrdersFromDatabase($select);

        return $ordersDataArray;
    }

    /**
     * Fetch stock levels for all or some products
     *
     * @param array|bool|false $product_ids An array of product entity IDs, or false if desiring all.
     * @return array
     */
    public function getStock($product_ids=false){
        $criteria = array(
            'stock_id'=>1,
        );
        if(is_array($product_ids)){
            $criteria['product_id'] = $product_ids;
        }
        $res = $this->getTableGateway('cataloginventory_stock_item')->select($criteria);

        $ret = array();
        foreach($res as $row){
            $ret[$row['product_id']] = $row['qty'];
        }

        return $ret;
    }

    /**
     * Update stock level for a single product
     *
     * @param int $product_id Product Entity ID
     * @param float $qty Quantity available
     * @param bool $is_in_stock Whether the product should be in stock
     */
    public function updateStock($product_id, $qty, $is_in_stock){
        $this->getTableGateway('cataloginventory_stock_item')->update(array('qty'=>$qty, 'is_in_stock'=>$is_in_stock), array('product_id'=>$product_id, 'stock_id'=>1));
    }

    /**
     * Returns whether or not the given customer is subscribed to the newsletter in Magento (unconfirmed or confirmed)
     *
     * @param int $customer_id The Magento customer ID to look up the status for
     * @return bool
     */
    public function getNewsletterStatus($customer_id){
        $sql = 'SELECT subscriber_id FROM newsletter_subscriber WHERE customer_id = ' . $customer_id . ' AND subscriber_status IN (1,4)';
        $this->debugSql($sql);

        $res = $this->_adapter->query($sql, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        foreach($res as $row){
            if($row['subscriber_id']){
                return true;
            }
        }
        return false;
    }

    /**
     * Get a list of entity IDs that have changed since the given timestamp. Relies on updated_at being set correctly.
     * @param string $entityType
     * @param string $changedSince A date in the MySQL date format (i.e. 2014-01-01 01:01:01)
     * @return array
     */
    public function getChangedEntityIds($entityType, $changedSince)
    {
        $sql = "SELECT entity_id FROM ".$this->getEntityPrefix($entityType)."_entity"
            ." WHERE updated_at >= '".$changedSince."';";

        $this->debugSql($sql);
        $localEntityIds = array();

        $result = $this->_adapter->query($sql, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        foreach ($result as $tableRow) {
            $localEntityIds[] = intval($tableRow['entity_id']);
        }

        return $localEntityIds;
    }

    /**
     * Update an entity in the Magento EAV system
     *
     * @todo Untested on multi-select / option type attributes.
     * @param string $entityType
     * @param int $entityId
     * @param int $storeId
     * @param array $data Key->value data to update, key is attribute ID.
     * @throws \Exception
     */
    public function updateEntityEav($entityType, $entityId, $storeId, $data)
    {
        $this->_adapter->getDriver()->getConnection()->beginTransaction();
        try{
            $staticUpdate = array();
            $attributes = array_keys($data);

            $entityTypeData = $this->getEntityType($entityType);
            $prefix = $this->getEntityPrefix($entityType);

            $attributesByType = $this->preprocessEavAttributes($entityType, $attributes);
            if (isset($attributesByType['static'])) {
                foreach ($attributesByType['static'] as $code) {
                    $staticUpdate[$code] = $data[$code];
                }
                unset($attributesByType['static']);
            }

            if (count($staticUpdate)) {
                $this->getTableGateway($prefix.'_entity')
                    ->update($staticUpdate, array('entity_id'=>$entityId));
            }

            $attributesById = array();
            foreach ($attributes as $code) {
                $attr = $this->getAttribute($entityType, $code);
                $attributesById[$attr['attribute_id']] = $attr;
            }

            foreach ($attributesByType as $type=>$singleTypeAttributes) {

                $doSourceTranslation = FALSE;
                $sourceTranslation = array();
                if($type == 'source_int'){
                    $type = $prefix . '_entity_int';
                    $doSourceTranslation = true;

                    foreach ($singleTypeAttributes as $code=>$attributeId) {
                        $sourceTranslation[$attributeId] =
                            array_flip($this->loadAttributeOptions($attributeId, $storeId));
                    }
                }

                foreach($singleTypeAttributes as $code=>$attributeId){

                    $value = $data[$code];
                    if($doSourceTranslation){
                        if(isset($sourceTranslation[$attributeId][$value])){
                            $value = $sourceTranslation[$attributeId][$value];
                        }else{
                            $message = 'DB API found unmatched value '.$value
                                .' for attribute '.$attributesById[$attributeId]['attribute_code'];
                            $this->getServiceLocator()->get('logService')
                                ->log(\Log\Service\LogService::LEVEL_WARN,
                                    'invalid_value',
                                    $message,
                                    array('value'=>$value, 'options'=>$sourceTranslation[$attributeId]),
                                    array()
                                );
                        }
                    }

                    if($storeId > 0){
                        $resultsDefault = $this->getTableGateway($type)
                            ->select(array(
                                'entity_id'=>$entityId,
                                'entity_type_id'=>$entityTypeData['entity_type_id'],
                                'store_id'=>0,
                                'attribute_id'=>$attributeId,
                            ));

                        if(!$resultsDefault || !count($resultsDefault)){
                            $this->getTableGateway($type)
                                ->insert(array(
                                    'entity_id'=>$entityId,
                                    'entity_type_id'=>$entityTypeData['entity_type_id'],
                                    'store_id'=>0,
                                    'attribute_id'=>$attributeId,
                                    'value'=>$value
                                ));
                        }
                    }

                    $resultsStore = $this->getTableGateway($type)->select(array(
                        'entity_id'=>$entityId,
                        'entity_type_id'=>$entityTypeData['entity_type_id'],
                        'store_id'=>$storeId,
                        'attribute_id'=>$attributeId,
                    ));

                    if(!$resultsStore || !count($resultsStore)){
                        $this->getTableGateway($type)->insert(array(
                            'entity_id'=>$entityId,
                            'entity_type_id'=>$entityTypeData['entity_type_id'],
                            'store_id'=>$storeId,
                            'attribute_id'=>$attributeId,
                            'value'=>$value)
                        );
                    }else{
                        $this->getTableGateway($type)->update(
                            array('value'=>$value),
                            array(
                                'entity_id'=>$entityId,
                                'entity_type_id'=>$entityTypeData['entity_type_id'],
                                'store_id'=>$storeId,
                                'attribute_id'=>$attributeId
                            )
                        );
                    }
                }
            }

            $this->_adapter->getDriver()->getConnection()->commit();

        }catch(\Exception $exception){
            $this->_adapter->getDriver()->getConnection()->rollback();
            throw $exception;
        }
    }

    /**
     * Load a single entity from the EAV tables, with the specified attributes
     * @param string $entity_type The type of entity to load
     * @param int $entity_id The entity ID to load
     * @param int|false $store_id The store to retrieve values for
     * @param array $attributes An array of attribute codes
     * @return array|null
     * @throws \Magelink\Exception\MagelinkException
     */
    public function loadEntityEav($entity_type, $entity_id, $store_id, $attributes){
        $entityTypeData = $this->getEntityType($entity_type);
        $prefix = $this->getEntityPrefix($entity_type);

        $entityRow = $this->getTableGateway($prefix.'_entity')->select(array('entity_id'=>$entity_id));
        if(!$entityRow || !count($entityRow)){
            return null;
        }
        $entityRow = array_shift($entityRow->toArray());

        $attributesByType = $this->preprocessEavAttributes($entity_type, $attributes);

        $attributesById = array();
        foreach($attributes as $code){
            $attr = $this->getAttribute($entity_type, $code);
            $attributesById[$attr['attribute_id']] = $attr;
        }

        $resultRow = array();

        $resultRow['entity_id'] = $entity_id;

        foreach($attributesByType as $type=>$atts){
            if($type == 'static'){
                foreach($atts as $code){
                    if(isset($entityRow[$code])){
                        $resultRow[$code] = $entityRow[$code];
                    }else{
                        throw new MagelinkException('Invalid static attribute ' . $code);
                    }
                }
                continue;
            }
            $doSourceTranslation = false;
            $sourceTranslation = array();
            if($type == 'source_int'){
                $type = $prefix . '_entity_int';
                $doSourceTranslation = true;

                foreach($atts as $code=>$aid){
                    $sourceTranslation[$aid] = $this->loadAttributeOptions($aid, $store_id);
                }

            }
            $resultsDefault = $this->getTableGateway($type)->select(array(
                'entity_id'=>$entity_id,
                'entity_type_id'=>$entityTypeData['entity_type_id'],
                'store_id'=>0,
                'attribute_id'=>array_values($atts),
            ));
            $resultsStore = array();
            if($store_id !== false){
                $resultsStore = $this->getTableGateway($type)->select(array(
                    'entity_id'=>$entity_id,
                    'entity_type_id'=>$entityTypeData['entity_type_id'],
                    'store_id'=>$store_id,
                    'attribute_id'=>array_values($atts),
                ));
            }
            foreach($resultsDefault as $row){
                $value = $row['value'];
                if($doSourceTranslation){
                    if(isset($sourceTranslation[$row['attribute_id']][$value])){
                        $value = $sourceTranslation[$row['attribute_id']][$value];
                    }else{
                        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'invalid_value', 'DB API found unmatched value ' . $value . ' for att ' . $attributesById[$row['attribute_id']]['attribute_code'], array('value'=>$value, 'row'=>$row, 'options'=>$sourceTranslation[$row['attribute_id']]), array());
                    }
                }
                $resultRow[$attributesById[$row['attribute_id']]['attribute_code']] = $value;
            }
            if($store_id !== false){
                foreach($resultsStore as $row){
                    $value = $row['value'];
                    if($doSourceTranslation){
                        if(isset($sourceTranslation[$row['attribute_id']][$value])){
                            $value = $sourceTranslation[$row['attribute_id']][$value];
                        }else{
                            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'invalid_value', 'DB API found unmatched value ' . $value . ' for att ' . $attributesById[$row['attribute_id']]['attribute_code'], array('value'=>$value, 'row'=>$row, 'options'=>$sourceTranslation[$row['attribute_id']]), array());
                        }
                    }
                    $resultRow[$attributesById[$row['attribute_id']]['attribute_code']] = $value;
                }
            }
        }

        return $resultRow;
    }

    /**
     * Identical to loadEntityEav() but takes an array of entity IDs
     * @param string $entity_type
     * @param array|null $entity_ids Entity IDs to fetch, or null if load all
     * @param int|false $store_id
     * @param array $attributes
     * @return array
     * @throws MagelinkException
     */
    public function loadEntitiesEav($entity_type, $entity_ids, $store_id, $attributes){
        $entityTypeData = $this->getEntityType($entity_type);
        $prefix = $this->getEntityPrefix($entity_type);

        if($entity_ids != null){
            $entityRowRaw = $this->getTableGateway($prefix.'_entity')->select(array('entity_id'=>$entity_ids));
        }else{
            $entityRowRaw = $this->getTableGateway($prefix.'_entity')->select();
        }
        if(!$entityRowRaw || !count($entityRowRaw)){
            return array();
        }

        $populateEntityIds = false;
        if($entity_ids == null){
            $entity_ids = array();
            $populateEntityIds = true;
        }
        $entityRow = array();
        foreach($entityRowRaw as $row){
            $entityRow[$row['entity_id']] = $row;
            if($populateEntityIds){
                $entity_ids[] = $row['entity_id'];
            }
        }

        $attributesByType = $this->preprocessEavAttributes($entity_type, $attributes);

        $attributesById = array();
        foreach($attributes as $code){
            $attr = $this->getAttribute($entity_type, $code);
            $attributesById[$attr['attribute_id']] = $attr;
        }

        $results = array();
        foreach($entity_ids as $id){
            $results[$id] = array('entity_id'=>$id);
        }

        foreach($attributesByType as $type=>$atts){
            if($type == 'static'){
                foreach($atts as $code=>$aid){
                    foreach($entity_ids as $entity_id){
                        if(!isset($entityRow[$entity_id])){
                            continue;
                        }
                        if(isset($entityRow[$entity_id][$code])){
                            $results[$entity_id][$code] = $entityRow[$entity_id][$code];
                        }else{
                            throw new MagelinkException('Invalid static attribute '.$code.' on entity with id '.$entity_id);
                        }
                    }
                }
                continue;
            }
            $doSourceTranslation = false;
            $sourceTranslation = array();
            if($type == 'source_int'){
                $type = $prefix . '_entity_int';
                $doSourceTranslation = true;

                foreach($atts as $code=>$aid){
                    $sourceTranslation[$aid] = $this->loadAttributeOptions($aid, $store_id);
                }

            }
            $resultsDefault = $this->getTableGateway($type)->select(array(
                'entity_id'=>$entity_ids,
                'entity_type_id'=>$entityTypeData['entity_type_id'],
                'store_id'=>0,
                'attribute_id'=>array_values($atts),
            ));
            $resultsStore = array();
            if($store_id !== false){
                $resultsStore = $this->getTableGateway($type)->select(array(
                    'entity_id'=>$entity_ids,
                    'entity_type_id'=>$entityTypeData['entity_type_id'],
                    'store_id'=>$store_id,
                    'attribute_id'=>array_values($atts),
                ));
            }
            foreach($resultsDefault as $row){
                $value = $row['value'];
                if($doSourceTranslation){
                    if(isset($sourceTranslation[$row['attribute_id']][$value])){
                        $value = $sourceTranslation[$row['attribute_id']][$value];
                    }else{
                        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'invalid_value', 'DB API found unmatched value ' . $value . ' for att ' . $attributesById[$row['attribute_id']]['attribute_code'], array('value'=>$value, 'row'=>$row, 'options'=>$sourceTranslation[$row['attribute_id']]), array());
                    }
                }
                $results[intval($row['entity_id'])][$attributesById[$row['attribute_id']]['attribute_code']] = $value;
            }
            if($store_id !== false){
                foreach($resultsStore as $row){
                    $value = $row['value'];
                    if($doSourceTranslation){
                        if(isset($sourceTranslation[$row['attribute_id']][$value])){
                            $value = $sourceTranslation[$row['attribute_id']][$value];
                        }else{
                            $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'invalid_value', 'DB API found unmatched value ' . $value . ' for att ' . $attributesById[$row['attribute_id']]['attribute_code'], array('value'=>$value, 'row'=>$row, 'options'=>$sourceTranslation[$row['attribute_id']]), array());
                        }
                    }
                    $results[intval($row['entity_id'])][$attributesById[$row['attribute_id']]['attribute_code']] = $value;
                }
            }
        }

        return $results;
    }

    /**
     * Returns a key-value array of option id -> value for the given attribute
     * @param int $att_id
     * @param int $store_id
     * @return array
     */
    protected function loadAttributeOptions($att_id, $store_id=0){
        $option_ids = array();
        $ret = array();
        $options = $this->getTableGateway('eav_attribute_option')->select(array('attribute_id'=>$att_id));
        foreach($options as $row){
            $option_ids[] = $row['option_id'];
        }
        $values = $this->getTableGateway('eav_attribute_option_value')->select(array('option_id'=>$option_ids, 'store_id'=>array(0, $store_id)));
        foreach($values as $row){
            if($row['store_id'] == 0 && !isset($ret[$row['option_id']])){
                $ret[$row['option_id']] = $row['value'];
            }else if($row['store_id'] > 0){
                $ret[$row['option_id']] = $row['value'];
            }
        }
        return $ret;
    }

    /**
     * Preprocess a list of attribute codes into the respective tables
     *
     * @param string $entity_type
     * @param array $attributes
     * @throws MagelinkException if an invalid attribute code is passed
     * @return array
     */
    protected function preprocessEavAttributes($entity_type, $attributes){
        $prefix = $this->getEntityPrefix($entity_type);

        if(!isset($attributesByType['static'])){
            $attributesByType['static'] = array();
        }

        $attributesByType = array();
        foreach($attributes as $code){
            if(in_array($code, array('attribute_set_id', 'type_id'))){
                $attributesByType['static'][$code] = $code;
                continue;
            }

            $code = trim($code);
            if(!strlen($code)){
                continue;
            }

            $attr = $this->getAttribute($entity_type, $code);
            if ($attr == NULL) {
                //*** ToDo throw new MagelinkException('Invalid Magento attribute code ' . $code . ' for ' . $entity_type);
            }else{
                $table = $this->getAttributeTable($prefix, $attr);

                if(!isset($attributesByType[$table])){
                    $attributesByType[$table] = array();
                }

                $attributesByType[$table][$code] = $attr['attribute_id'];
            }
        }

        return $attributesByType;
    }

    /**
     * Get the table used for storing a particular attribute, or "static" if it exists in the entity table.
     * @param string $prefix The table prefix to be used, e.g. "catalog_product".
     * @param array $attrData
     * @return string The table name or "static"
     */
    protected function getAttributeTable($prefix, $attrData){
        if($attrData['backend_type'] == 'static'){
            return 'static';
        }else if($attrData['backend_table'] != null){
            return $attrData['backend_table'];
        }else if($attrData['backend_type'] == 'int' && $attrData['source_model'] == 'eav/entity_attribute_source_table'){
            return 'source_int';
        } else{
            return $prefix . '_entity_' . $attrData['backend_type'];
        }
    }

    /**
     * Returns the table prefix for entities of the given type
     * @param $entity_type
     * @return string
     * @throws \Magelink\Exception\MagelinkException
     */
    protected function getEntityPrefix($entity_type){
        switch($entity_type){
            case 'catalog_product':
            case 'catalog_category':
            case 'customer':
            case 'customer_address':
                return $entity_type;
            case 'rma_item':
                return 'enterprise_rma_item';
            default:
                // Maybe warn? This should be a safe default
                return $entity_type;
        }
    }

    /**
     * @var array Cache for getEntityType
     */
    protected $_entityTypeCache = array();

    /**
     * Returns the entity type table entry for the given type
     * @param $entity_type_code
     * @return null
     */
    protected function getEntityType($entity_type_code){
        if(isset($this->_entityTypeCache[$entity_type_code])){
            return $this->_entityTypeCache[$entity_type_code];
        }
        $res = $this->getTableGateway('eav_entity_type')->select(array('entity_type_code'=>$entity_type_code));
        foreach($res as $row){
            $this->_entityTypeCache[$entity_type_code] = $row;
            return $row;
        }
        $this->_entityTypeCache[$entity_type_code] = null;
        return null;
    }

    /**
     * @var array Cache for getAttribute
     */
    protected $_attributeCache = array();

    /**
     * Returns the eav attribute table entry for the given code
     * @param $entity_type
     * @param $attribute_code
     * @return null
     */
    protected function getAttribute($entity_type, $attribute_code){
        $entity_type = $this->getEntityType($entity_type);
        $entity_type = $entity_type['entity_type_id'];

        if(isset($this->_attributeCache[$entity_type])){
            if(isset($this->_attributeCache[$entity_type][$attribute_code])){
                return $this->_attributeCache[$entity_type][$attribute_code];
            }
        }else{
            $this->_attributeCache[$entity_type] = array();
        }


        $res = $this->getTableGateway('eav_attribute')->select(array('entity_type_id'=>$entity_type, 'attribute_code'=>$attribute_code));
        foreach($res as $row){
            $this->_attributeCache[$entity_type][$attribute_code] = $row;
            return $row;
        }

        $this->_attributeCache[$entity_type][$attribute_code] = null;
        return null;
    }

    /**
     * @var array
     */
    protected $_tgCache = array();

    /**
     * Returns a new TableGateway instance for the requested table
     * @param string $table
     * @return \Zend\Db\TableGateway\TableGateway
     */
    protected function getTableGateway($table){
        if(isset($this->_tgCache[$table])){
            return $this->_tgCache[$table];
        }
        $this->_tgCache[$table] = new TableGateway($table, $this->_adapter);
        return $this->_tgCache[$table];
    }

    /**
     * @var ServiceLocatorInterface Local service locator instance
     */
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
