<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\ObjectManagerInterface;
use Signifyd\Connect\Helper\PurchaseHelper;
use Signifyd\Connect\Helper\LogHelper;
use Signifyd\Connect\Helper\ConfigHelper;
use Signifyd\Connect\Model\CaseRetry;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Observer for purchase event. Sends order data to Signifyd service
 */
class Purchase implements ObserverInterface
{
    /**
     * @var \Signifyd\Connect\Helper\LogHelper
     */
    protected $logger;

    /**
     * @var \Signifyd\Connect\Helper\PurchaseHelper
     */
    protected $helper;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManagerInterface;

    /**
     * Methods that should wait e-mail sent to hold order
     * @var array
     */
    protected $specialMethods = ['payflow_express'];

    /**
     * List of methods that uses a different event for triggering case creation
     * This is useful when it's needed case creation to be delayed to wait for other processes like data return from
     * payment method
     *
     * @var array
     */
    protected $ownEventsMethods = ['authorizenet_directpost'];

    /**
     * @var array
     */
    protected $restrictedMethods = ['checkmo', 'banktransfer', 'purchaseorder', 'cashondelivery'];

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * Purchase constructor.
     * @param LogHelper $logger
     * @param PurchaseHelper $helper
     * @param ConfigHelper $configHelper
     * @param ObjectManagerInterface $objectManagerInterface
     * @param StoreManagerInterface|null $storeManager
     */
    public function __construct(
        LogHelper $logger,
        PurchaseHelper $helper,
        ConfigHelper $configHelper,
        ObjectManagerInterface $objectManagerInterface,
        StoreManagerInterface $storeManager = null,
        RequestInterface $request
    ) {
        $this->logger = $logger;
        $this->helper = $helper;
        $this->configHelper = $configHelper;
        $this->objectManagerInterface = $objectManagerInterface;
        $this->storeManager = empty($storeManager) ?
            $objectManagerInterface->get('Magento\Store\Model\StoreManagerInterface') :
            $storeManager;
        $this->request = $request;
    }

    /**
     * @param Observer $observer
     * @param bool $checkOwnEventsMethods
     */
    public function execute(Observer $observer, $checkOwnEventsMethods = true)
    {
        try {
            /** @var $order Order */
            $order = $observer->getEvent()->getOrder();

            if (!is_object($order)) {
                return;
            }

            if ($this->configHelper->isEnabled($order) == false) {
                return;
            }

            $saveOrder = false;

            // Saving store code to order, to know where the order is been created
            if (empty($order->getData('origin_store_code')) && is_object($this->storeManager)) {
                $storeCode = $this->storeManager->getStore($this->helper->isAdmin() ? 'admin' : true)->getCode();

                if (!empty($storeCode)) {
                    $order->setData('origin_store_code', $storeCode);
                    $saveOrder = true;
                }
            }

            // Fix for Magento bug https://github.com/magento/magento2/issues/7227
            // x_forwarded_for should be copied from quote, but quote does not have the field on database
            if (empty($order->getData('x_forwarded_for')) && is_object($this->request)) {
                $xForwardIp = $this->request->getServer('HTTP_X_FORWARDED_FOR');

                if (empty($xForwardIp) == false) {
                    $order->setData('x_forwarded_for', $xForwardIp);
                    $saveOrder = true;
                }
            }

            if ($saveOrder) {
                $order->save();
            }

            // Check if a payment is available for this order yet
            if ($order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
                return;
            }

            $paymentMethod = $order->getPayment()->getMethod();
            $this->logger->debug($paymentMethod);

            if ($checkOwnEventsMethods && in_array($paymentMethod, $this->ownEventsMethods)) {
                return;
            }

            if (in_array($paymentMethod, $this->restrictedMethods)){
                return;
            }

            // Check if case already exists for this order
            if ($this->helper->doesCaseExist($order)) {
                return;
            }

            $orderData = $this->helper->processOrderData($order);

            // Add order to database
            $case = $this->helper->createNewCase($order);

            // Post case to signifyd service
            $result = $this->helper->postCaseToSignifyd($orderData, $order);

            // Initial hold order
            $this->holdOrder($order);

            if ($result){
                $case->setCode($result);
                $case->setMagentoStatus(CaseRetry::IN_REVIEW_STATUS)->setUpdated(strftime('%Y-%m-%d %H:%M:%S', time()));
                try {
                    $case->getResource()->save($case);
                    $this->logger->debug('Case saved. Order No:' . $order->getIncrementId());
                } catch (\Exception $e) {
                    $this->logger->error('Exception in: ' . __FILE__ . ', on line: ' . __LINE__);
                    $this->logger->error('Exception:' . $e->__toString());
                }
            }
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
        }
    }

    /**
     * @param $order
     * @return bool
     */
    public function holdOrder($order)
    {
        $case = $this->helper->getCase($order);
        $positiveAction = $case->getPositiveAction();
        $negativeAction = $case->getNegativeAction();

        if (($positiveAction != 'nothing' || $negativeAction != 'nothing')) {
            if (!$order->canHold()) {
                $notHoldableStates = [
                    Order::STATE_CANCELED,
                    Order::STATE_PAYMENT_REVIEW,
                    Order::STATE_COMPLETE,
                    Order::STATE_CLOSED,
                    Order::STATE_HOLDED
                ];

                if (in_array($order->getState(), $notHoldableStates)) {
                    $reason = "order is on {$order->getState()} state";
                } elseif ($order->getActionFlag(Order::ACTION_FLAG_HOLD) === false) {
                    $reason = "order action flag is set to do not hold";
                } else {
                    $reason = "unknown reason";
                }

                $this->logger->debug("Order {$order->getIncrementId()} can not be held because {$reason}");

                return false;
            }

            if (in_array($order->getPayment()->getMethod(), $this->specialMethods)) {
                if (!$order->getEmailSent()) {
                    return false;
                }

                if ($this->helper->hasGuaranty($order)) {
                    return false;
                }
            }

            if (!$this->helper->hasGuaranty($order)) {
                $this->logger->debug('Purchase Observer Order Hold: No: ' . $order->getIncrementId());
                $order->hold()->getResource()->save($order);
            }
        }

        return true;
    }
}
