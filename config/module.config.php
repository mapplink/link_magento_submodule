<?php

return array (
    'service_manager'=>array(
        'invokables'=>array(
            'magento_soap'=>'Magento\Api\Soap',
            'magento_soapv1'=>'Magento\Api\SoapV1',
            'magento_rest'=>'Magento\Api\Rest',
            'magento_db'=>'Magento\Api\Db',
            'magentoService'=>'Magento\Service\MagentoService',
            //'transform_order_total'=>'Magento\Transform\OrderTotalTransform'
        ),
        'shared'=>array(
            'magento_soap'=>FALSE,
            'magento_soapv1'=>FALSE,
            'magento_rest'=>FALSE,
            'magento_db'=>FALSE,
            'magentoService'=>FALSE
            //'transform_order_total'=>FALSE
        ),
    ),
	'node_types'=>array(
        'magento'=>array(
            'module'=>'Magento', // Module name used for this node
            'name'=>'Magento', // Human-readable node name
            'store_specific'=>FALSE, // TRUE if this node only operates with one store view, FALSE if on all at once
            'entity_type_support'=>array( // List of entity type codes that this module supports
                'product',
                'stockitem',
                'customer',
                'order',
                'creditmemo',
                #'address',
                #'orderitem',
            ),
            'config'=>array( // Config options to be displayed to the administrator
                'multi_store'=>array(
                    'label'=>'Enable Multi-Store support? (DO NOT CHANGE)',
                    'type'=>'Checkbox', 
                    'default'=>TRUE
                ),
                'web_url'=>array('label'=>'Base Web URL', 'type'=>'Text', 'required'=>TRUE),
                'enterprise'=>array(
                    'label'=>'Enterprise Edition? (DO NOT CHANGE)',
                    'type'=>'Checkbox',
                    'default'=>FALSE
                ),
                'soap_username'=>array('label'=>'SOAP Username','type'=>'Text', 'required'=>TRUE),
                'soap_password'=>array('label'=>'SOAP Password','type'=>'Text', 'required'=>TRUE),
                'rest_key'=>array('label'=>'REST Consumer Key','type'=>'Text', 'required'=>FALSE),
                'rest_secret'=>array('label'=>'REST Consumer Secret','type'=>'Text', 'required'=>FALSE),
                'db_hostname'=>array('label'=>'Database Host','type'=>'Text', 'required'=>FALSE),
                'db_schema'=>array('label'=>'Database Schema','type'=>'Text', 'required'=>FALSE),
                'db_username'=>array('label'=>'Database Username','type'=>'Text', 'required'=>FALSE),
                'db_password'=>array('label'=>'Database Password','type'=>'Text', 'required'=>FALSE),

                'load_full_product'=>array('label'=>'Load full product data?', 'type'=>'Checkbox', 'default'=>FALSE),
                'load_stock'=>array('label'=>'Load stock data? (SLOW)', 'type'=>'Checkbox', 'default'=>FALSE),
                'load_full_customer'=>array('label'=>'Load full customer data?', 'type'=>'Checkbox', 'default'=>FALSE),
                'load_full_order'=>array('label'=>'Load full order data?', 'type'=>'Checkbox', 'default'=>FALSE),

                'product_attributes'=>array(
                    'label'=>'Extra product attributes to load',
                    'type'=>'Text',
                    'default'=>array()
                ),
                'customer_attributes'=>array(
                    'label'=>'Extra customer attributes to load',
                    'type'=>'Text',
                    'default'=>array()
                ),
                /*'customer_special_attributes'=>array(
                    'label'=>'Extra customer attribute (stored in taxvat)',
                    'type'=>'Text',
                    'default'=>''
                ),*/
                'time_delta_customer'=>array(
                    'label'=>'CUSTOMER API : timezone delta in hours',
                    'type'=>'Text',
                    'default'=>'0'
                ),
                'time_delta_product'=>array(
                    'label'=>'PRODUCT API : timezone delta in hours',
                    'type'=>'Text', 
                    'default'=>'0'
                ),
                'time_delta_order'=>array(
                    'label'=>'ORDER API : timezone delta in hours',
                    'type'=>'Text',
                    'default'=>'0'
                ),
                'time_correction_order'=>array(
                    'label'=>'ORDER : time correction in hours on import into HOPS',
                    'type'=>'Text',
                    'default'=>'0'
                ),
                'time_delta_creditmemo'=>array(
                    'label'=>'CREDIT MEMO fetch : timezone delta in hours',
                    'type'=>'Text', 
                    'default'=>'0'
                ),
                'api_overlapping_seconds'=>array(
                    'label'=>'API calls : overlapping seconds to avoid missing information',
                    'type'=>'Text',
                    'default'=>'12'
                )
            ),
        )
    ),
    'controllers' => array(
        'invokables' => array(
            'Magento\Controller\Console' => 'Magento\Controller\Console',
        ),
    ),
    'console' => array(
        'router' => array(
            'routes' => array(
                'magento-console' => array(
                    'options' => array(
                        'route'    => 'magento <task> <id> [<params>]',
                        'defaults' => array(
                            'controller' => 'Magento\Controller\Console',
                            'action'     => 'run'
                        )
                    )
                )
            )
        )
    )
);
