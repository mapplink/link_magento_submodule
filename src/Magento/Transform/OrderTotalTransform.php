<?php
namespace Magento\Transform;

use \Router\Transform\AbstractTransform;

/**
 * A custom transform to initialize order data
 * Source attribute should be order grand_total, create and update
 *
 * @package Magento\Transform
 */
class OrderTotalTransform extends AbstractTransform
{

    /**
     * Perform any initialization/setup actions, and check any prerequisites.
     * @return boolean Whether this transform is eligible to run
     */
    protected function _init()
    {
        if($this->_entity->getTypeStr() != 'order'){
            return FALSE;
        }else{
            return TRUE;
        }
    }

    /**
     * Apply the transform on any necessary data
     * @return array New data changes to be merged into the update.
     */
    public function apply()
    {
        $data = $this->_entity->getArrayCopy();
        $orderTotal = $data['grand_total'] - $data['shipping_total']
            + $data['giftcard_total'] + $data['reward_total'] + $data['storecredit_total'];

        return array(
            'order_total'=> $orderTotal
        );
    }

}