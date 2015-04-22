<?php

namespace Magento\Gateway;

use Magelink\Exception\MagelinkException;
use Magelink\Exception\GatewayException;
use Node\AbstractGateway as BaseAbstractGateway;
use Node\AbstractNode;
use Node\Entity;

abstract class AbstractGateway extends BaseAbstractGateway
{
    /** @var \Magento\Node */
    protected $_node;

    /** @var \Node\Entity\Node */
    protected $_nodeEntity;

    /** @var \Node\Service\NodeService */
    protected $_nodeService = NULL;

    /** @var \Entity\Service\EntityService $entityService */
    protected $_entityService = NULL;

    /** @var \Entity\Service\EntityConfigService $entityConfigService */
    protected $entityConfigService = NULL;

    /** @var \Magento\Api\Soap */
    protected $_soap = NULL;

    /** @var int $apiOverlappingSeconds */
    protected $apiOverlappingSeconds = 3;

    /** @var \Magento\Api\Db */
    protected $_db = NULL;


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param AbstractNode $node
     * @param Entity\Node $nodeEntity
     * @param string $entityType
     * @throws \Magelink\Exception\MagelinkException
     * @return boolean
     */
    public function init(AbstractNode $node, Entity\Node $nodeEntity, $entityType)
    {
        $success = TRUE;

        if (!($node instanceof \Magento\Node)) {
            $success = FALSE;
            throw new MagelinkException('Invalid node type for this gateway');
        }else{
            $this->_node = $node;
            $this->_nodeEntity = $nodeEntity;
            $this->_nodeService = $this->getServiceLocator()->get('nodeService');
            $this->_entityService = $this->getServiceLocator()->get('entityService');
            //$this->_entityConfigService = $this->getServiceLocator()->get('entityConfigService');

            $this->_db = $node->getApi('db');
            $this->_soap = $node->getApi('soap');

            if (!$this->_soap) {
                $success = FALSE;
                throw new GatewayException('SOAP is required for Magento '.ucfirst($entityType));
            }else{
                $this->apiOverlappingSeconds += $this->_node->getConfig('api_overlapping_seconds');
            }
        }

        return $success;
    }
}