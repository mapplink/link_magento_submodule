<?php
/**
 * A custom transform to initialize order data
 * Source attribute should be order grand_total, create and update
 *
 * @category Magento
 * @package Magento\Transform
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */


namespace Magento\Transform;

use Router\Transform\AbstractTransform;
use Entity\Wrapper\Order;


class OrderTotalTransform extends AbstractTransform
{

    /**
     * Perform any initialization/setup actions, and check any prerequisites.
     * @return boolean Whether this transform is eligible to run
     */
    protected function _init()
    {
        return $this->_entity->getTypeStr() == 'order';
    }

    /**
     * Apply the transform on any necessary data
     * @return array New data changes to be merged into the update.
     */
    public function _apply()
    {
        $order = $this->_entity;
        $data = $order->getArrayCopy();

        $orderTotal = $data['grand_total'] - $data['shipping_total'];
        foreach (Order::getNonCashPaymentCodes() as $code) {
            $orderTotal += $data[$code];
        }

        return array('order_total'=> $orderTotal);
    }

}
