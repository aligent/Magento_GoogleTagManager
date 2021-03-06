<?php
/**
 * Google Tag Manager Block
 *
 * @category    CVM
 * @package     CVM_GoogleTagManager
 * @author      Chris Martin <chris@chris-martin.net>
 * @copyright   Copyright (c) 2013 Chris Martin
 * @license     http://opensource.org/licenses/osl-3.0.php Open Software License 3.0 (OSL-3.0)
 */
class CVM_GoogleTagManager_Block_Gtm extends Mage_Core_Block_Template
{
    /**
     * Generate JavaScript for the container snippet.
     *
     * @return string
     */
    protected function _getContainerSnippet()
    {
        // Get the container ID.
        $containerId = Mage::helper('googletagmanager')->getContainerId();

        // Render the container snippet JavaScript.
        return "<noscript><iframe src=\"//www.googletagmanager.com/ns.html?id=".$containerId."\"
height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'//www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','".$containerId."');</script>\n";
    }

    /**
     * Generate JavaScript for the data layer.
     *
     * @return string|null
     */
    protected function _getDataLayer()
    {
        // Initialise our data source.
        $data = array();

        // Get transaction and visitor data, if desired.
        if (Mage::helper('googletagmanager')->isDataLayerTransactionsEnabled()) $data = $data + $this->_getTransactionData();
        if (Mage::helper('googletagmanager')->isDataLayerVisitorsEnabled()) $data = $data + $this->_getVisitorData();

        // Enable modules to add custom data to the data layer
        $data_layer = new Varien_Object();
        $data_layer->setData($data);
        Mage::dispatchEvent('cvm_googletagmanager_get_datalayer',
            array('data_layer' => $data_layer)
        );
        $data = $data_layer->getData();

        // Generate the data layer JavaScript.
        if (!empty($data)) return "<script>dataLayer = [".json_encode($data)."];</script>\n\n";
        else return '';
    }

    /**
     * Get transaction data for use in the data layer.
     *
     * @link https://developers.google.com/tag-manager/reference
     * @return array
     */
    protected function _getTransactionData()
    {
        $data = array();

        $orderIds = $this->getOrderIds();
        if (empty($orderIds) || !is_array($orderIds)) return array();

        $collection = Mage::getResourceModel('sales/order_collection')->addFieldToFilter('entity_id', array('in' => $orderIds));

        $i = 0;
        $products = array();
        $allCategoryIds = array();
        foreach ($collection as $order) {
            if ($order->getShippingMethod() && $order->getShippingCarrier()) {
                $transactionShippingMethod = $order->getShippingCarrier()->getCarrierCode();
            } else {
                $transactionShippingMethod = null;
            }
            if ($i == 0) {
                // Build all fields for first order.
                $data = array(
                    'event' => 'transaction',
                    'transactionId' => $order->getIncrementId(),
                    'transactionDate' => date("Y-m-d"),
                    'transactionType' => Mage::helper('googletagmanager')->getTransactionType(),
                    'transactionAffiliation' => Mage::helper('googletagmanager')->getTransactionAffiliation(),
                    'transactionTotal' => round($order->getBaseGrandTotal(),2),
                    'transactionShipping' => round($order->getBaseShippingAmount(),2),
                    'transactionTax' => round($order->getBaseTaxAmount(),2),
                    'transactionPaymentType' => $order->getPayment()->getMethodInstance()->getTitle(),
                    'transactionCurrency' => $order->getOrderCurrencyCode(),
                    'transactionShippingMethod' => $transactionShippingMethod,
                    'transactionPromoCode' => $order->getCouponCode(),
                    'transactionProducts' => array()
                );
            } else {
                // For subsequent orders, append to order ID, totals and shipping method.
                $data['transactionId'] .= '|'.$order->getIncrementId();
                $data['transactionTotal'] += $order->getBaseGrandTotal();
                $data['transactionShipping'] += $order->getBaseShippingAmount();
                $data['transactionTax'] += $order->getBaseTaxAmount();
                $data['transactionShippingMethod'] .= '|'.$transactionShippingMethod;
            }

            // Build products array.
            foreach ($order->getAllVisibleItems() as $item) {
                $product = Mage::getModel('catalog/product')->load($item->getProductId());

                // Add the categories to a collection so they can be loaded later
                $allCategoryIds = array_merge($allCategoryIds, $product->getCategoryIds());

                if (empty($products[$item->getSku()])) {
                    // Build all fields the first time we encounter this item.
                    $products[$item->getSku()] = array(
                        'category_ids' => $product->getCategoryIds(),
                        'name' => $this->jsQuoteEscape(Mage::helper('core')->escapeHtml($item->getName())),
                        'sku' => $this->jsQuoteEscape(Mage::helper('core')->escapeHtml($item->getSku())),
                        'price' => (double)number_format($item->getBasePrice(),2,'.',''),
                        'quantity' => (int)$item->getQtyOrdered()
                    );

                    $productData = new Varien_Object($products[$item->getSku()]);
                    // Event to allow other module to add additional product data
                    Mage::dispatchEvent('cvm_googletagmanager_trans_data_product_before_load',
                        array('product_data' => $productData, 'product' => $item));
                    $products[$item->getSku()] = $productData->getData();
                } else {
                    // If we already have the item, update quantity.
                    $products[$item->getSku()]['quantity'] += (int)$item->getQtyOrdered();
                }
            }
            $i++;
        }

        // Load all used categories in a collection to improve performance
        $categoryCollection = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('entity_id', array('in' => $allCategoryIds))->load();
        foreach($products as $sku => $product) {
            $product['category'] = null;
            foreach($product['category_ids'] as $categoryId) {
                $categoryModel = $categoryCollection->getItemById($categoryId);
                if($categoryModel) {
                    $categoryName = $categoryModel->getName();
                    if($product['category']) {
                        $categoryName = '|' . $categoryName;
                    }
                    $product['category'] = $product['category'] . $categoryName;
                }
            }

            $productData = new Varien_Object($product);
            // Event to allow other module to add additional product data (after category collection load)
            Mage::dispatchEvent('cvm_googletagmanager_trans_data_product_after_load',
                array('product_data' => $productData, 'all_categories' => $categoryCollection));
            $productData->unsetData('category_ids');
            // Push products into main data array.
            $data['transactionProducts'][] = $productData->getData();
        }

        // Trim empty fields from the final output.
        foreach ($data as $key => $value) {
            if (!is_numeric($value) && empty($value)) unset($data[$key]);
        }

        return $data;
    }

    /**
     * Get visitor data for use in the data layer.
     *
     * @link https://developers.google.com/tag-manager/reference
     * @return array
     */
    protected function _getVisitorData()
    {
        $data = array();
        $customer = Mage::getSingleton('customer/session');

        // visitorId
        if ($customer->getCustomerId()) $data['visitorId'] = (string)$customer->getCustomerId();

        // visitorLoginState
        $data['visitorLoginState'] = ($customer->isLoggedIn()) ? 'Logged in' : 'Logged out';

        // visitorType
        $data['visitorType'] = (string)Mage::getModel('customer/group')->load($customer->getCustomerGroupId())->getCode();

        // visitorExistingCustomer / visitorLifetimeValue
        $orders = Mage::getResourceModel('sales/order_collection')->addFieldToSelect('*')->addFieldToFilter('customer_id',$customer->getId());
        $ordersTotal = 0;
        foreach ($orders as $order) {
            $ordersTotal += $order->getGrandTotal();
        }
        if ($customer->isLoggedIn()) {
            $data['visitorLifetimeValue'] = round($ordersTotal,2);
        } else {
            $orderData = $this->_getTransactionData();
            if (!empty($orderData)) {
                $data['visitorLifetimeValue'] = $orderData['transactionTotal'];
            } else {
                $data['visitorLifetimeValue'] = 0;
            }
        }
        $data['visitorExistingCustomer'] = ($ordersTotal > 0) ? 'Yes' : 'No';

        return $data;
    }

    /**
     * Render Google Tag Manager code
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!Mage::helper('googletagmanager')->isGoogleTagManagerAvailable()) return '';
        return parent::_toHtml();
    }
}
