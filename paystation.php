<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC')) {
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
}

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentPaystation extends vmPSPlugin
{
    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Paystation Table');
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'INT(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(1) DEFAULT NULL'
        );
        return $SQLfields;
    }

    // this is the function responsible for redirecting to Paystation page
    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $session = JFactory::getSession();
        $return_context = $session->getId();
        $this->_debug = $method->debug;
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
        }

        $new_status = '';

        $usrBT = $order['details']['BT'];
        $address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

        if (!class_exists('TableVendors')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
        }
        $vendorModel = VmModel::getModel('Vendor');
        $vendorModel->setId(1);
        $vendor = $vendorModel->getVendor();
        $vendorModel->addImages($vendor, 1);
        $this->getPaymentCurrency($method);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();

        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency,
            $order['details']['BT']->order_total, false), 2);
        $cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

        $this->_virtuemart_paymentmethod_id = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $currency_code_3;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);

        $paymentmethod_id = (int)$this->_virtuemart_paymentmethod_id;
        $return_url = JURI::base() . "index.php?option=com_virtuemart";
        $return_url .= "&view=pluginresponse&task=pluginnotification&pm=";
        $return_url .= $this->_virtuemart_paymentmethod_id;

        $paystation_url = "https://www.paystation.co.nz/direct/paystation.dll";
        $pattern = array('/\'/', '/\"/');
        $pstn_pi = trim($method->paystation_id);

        $authenticationKey = trim($method->paystation_hmac);
        $hmacWebserviceName = 'paystation';
        $pstn_HMACTimestamp = time();

        $pstn_gi = trim($method->paystation_gateway); //Gateway ID
        $pstn_mr = urlencode($order['details']['BT']->order_number . '-' . filter_var(preg_replace($pattern, '',
                $order['details']['BT']->email), FILTER_SANITIZE_EMAIL));
        $merchantSession = urlencode($order['details']['BT']->ip_address . '-' . time() . '-' . $order['details']['BT']->order_number); //max length of ms is 64 char
        $amount = $totalInPaymentCurrency * 100;
        $paystationParams = "paystation&pstn_pi=" . trim($pstn_pi) . "&pstn_gi=" . trim($pstn_gi) . "&pstn_ms=" . $merchantSession . "&pstn_am=" . $amount . "&pstn_mr=" . $pstn_mr . "&pstn_nr=t";

        $paystationParams .= "&pstn_du=" . urlencode($return_url);

        if ($method->postback_enabled == '1') {
            $paystationParams .= "&pstn_dp=" . urlencode($return_url);

        }
        if ($method->is_test == '1') {
            $paystationParams = $paystationParams . "&pstn_tm=t";
        }

        $hmacBody = pack('a*', $pstn_HMACTimestamp) . pack('a*', $hmacWebserviceName) . pack('a*', $paystationParams);
        $hmacHash = hash_hmac('sha512', $hmacBody, $authenticationKey);
        $hmacGetParams = '?pstn_HMACTimestamp=' . $pstn_HMACTimestamp . '&pstn_HMAC=' . $hmacHash;
        $paystation_url .= $hmacGetParams;

        $intiationResult = $this->directTransaction($paystation_url, $paystationParams);
        $xml = simplexml_load_string($intiationResult);

        $digitalOrder = $xml->DigitalOrder;

        $html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
        $html .= '<form action="' . $digitalOrder . '" method="post" name="vm_paystation_form" >';
        $html .= '<input type="submit"  value="' . JText::_('VMPAYMENT_PAYSTATION_REDIRECT_MESSAGE') . '" />';
        $html .= '</form></div>';

        $html .= ' <script type="text/javascript">';
        $html .= ' document.vm_paystation_form.submit();';
        $html .= ' </script></body></html>';

        return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $dbValues['payment_name'],
            $new_status);
    }

    /**
     * Handles all call backs from paystation
     * This event is fired after receiving a asynchronous payment Notification.
     * It can be used to store the payment specific data.
     * @return string
     */
    function plgVmOnPaymentNotification()
    {
        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $postback = file_get_contents("php://input");
        $post_response = false;
        $order_id = 0;
        $success = false;
        $merchant_session = '';

        // if there is a post body then this is the return from the post response else it is the redirect
        if ($postback != '') {
            if ($method->postback_enabled == '0') {
                exit("Postback not enabled in Joomla plugin settings");
            }

            $post_response = true;

            $xml = simplexml_load_string($postback);

            if (isset($xml->MerchantSession)) {
                $merchant_session = $xml->MerchantSession;
                $pieces = explode("-", $xml->MerchantSession);
                $order_id = $pieces[2];
            }

            // if the payment is successful change status, clear cart send emails
            // otherwise do nothing and order remains as pending
            if ($xml->ec == 0) {
                $success = true;
            }
        } else {
            $jinput = JFactory::getApplication()->input;
            $postdata = $jinput->get->getArray();

            $merchant_session = $postdata['ms'];
            $pieces = explode("-", $postdata['ms']);
            $order_id = $pieces[2];

            if ($postdata['ec'] == '0') {
                $success = true;
            }
        }

        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_id);
        $order = VirtueMartModelOrders::getOrder($virtuemart_order_id);

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        $modelOrder = VmModel::getModel('orders');
        $orderitems = $modelOrder->getOrder($virtuemart_order_id);
        $nb_history = count($orderitems['history']);

        $order['order_status'] = 'X';
        if ($success) {
            // Quick look verification of success
            $result = $this->transactionVerification($method->paystation_id, $merchant_session, $method->paystation_hmac);
            $success = $result == '0';
            if ($success) {
                $order['order_status'] = 'C';
            }
        }

        // Only update order if the status is different to current status
        if ($orderitems['history'][$nb_history - 1]->order_status_code != $order['order_status']) {
            if ($success) {
                $order['customer_notified'] = 1; //send emails
                $order['comments'] = JText::sprintf('VMPAYMENT_PAYSTATION_EMAIL_SENT');
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();

                $session = JFactory::getSession();
                $return_context = $session->getId();
                $this->emptyCart($return_context, $virtuemart_order_id);
                $this->emptyCartFromStorageSession($return_context, $virtuemart_order_id);
            }
            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
        }

        if ($post_response) {
            if ($success) {
                exit('Order update successful');
            } else {
                exit('Order update failed');
            }
        }

        if (!($paymentTable = $this->_getPaystationInternalData($virtuemart_order_id, $order_id))) {
            return '';
        }

        $payment_name = $this->renderPluginName($method);

        $html = $this->_getPaymentResponseHtml($paymentTable, $payment_name);

        if ($postdata['ec'] == '0') {
            echo '<h1>' . $postdata['em'] . '</h1>';
            echo JText::sprintf('VMPAYMENT_PAYSTATION_PAYMENT_STATUS_CONFIRMED', $order_id);
        } else {
            echo '<h1>Transaction Error - ' . $postdata['em'] . '</h1>';
            $error_text = JText::sprintf('VMPAYMENT_PAYSTATION_PAYMENT_STATUS_ERROR', $order_id, $postdata['ec'],
                $postdata['em']);
            echo $error_text;
            JFactory::getApplication()->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment', FALSE), $error_text);
        }
        echo $html;
    }

    function _getPaystationInternalData($virtuemart_order_id, $order_number = '')
    {
        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
        if ($order_number) {
            $q .= " `order_number` = '" . $order_number . "'";
        } else {
            $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
        }

        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            return '';
        }
        return $paymentTable;
    }

    function _getPaymentResponseHtml($paystationTable, $payment_name)
    {
        $html = '<table width="100%">' . "\n";
        if (!empty($paystationTable)) {
            $html .= $this->getHtmlRow('PAYSTATION_ORDER_NUMBER', $paystationTable->order_number);
            $html .= $this->getHtmlRow('PAYSTATION_AMOUNT',
                $paystationTable->payment_order_total . " " . $paystationTable->payment_currency);
        }
        $html .= '</table>' . "\n";

        return $html;
    }

    // the following functions have all been copied from existing payment methods
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ($method->min_amount <= $amount AND ($method->max_amount == 0)));
        if (!$amount_cond) {
            return false;
        }
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'],
                $countries) || count($countries) == 0) {
            return true;
        }

        return false;
    }

    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return null;
        }
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('PAYSTATION_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('PAYSTATION_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= $this->getHtmlRowBE('PAYSTATION_COST_PER_TRANSACTION', $paymentTable->cost_per_transaction);
        $html .= $this->getHtmlRowBE('PAYSTATION_COST_PERCENT_TOTAL', $paymentTable->cost_percent_total);
        $html .= '</table>' . "\n";
        return $html;
    }

    public function transactionVerification($paystationID, $merchantSession, $hmacKey)
    {
        $lookupXML = $this->quickLookup($paystationID, 'ms', $merchantSession, $hmacKey);
        $xml = simplexml_load_string($lookupXML);
        return $xml->LookupResponse->PaystationErrorCode;
    }

    public function quickLookup($pi, $type, $value, $hmacKey)
    {
        $url = "https://payments.paystation.co.nz/lookup/";
        $params = "&pi=$pi&$type=$value";

        $authenticationKey = trim($hmacKey);
        $hmacWebserviceName = 'paystation';
        $pstn_HMACTimestamp = time();

        $hmacBody = pack('a*', $pstn_HMACTimestamp) . pack('a*', $hmacWebserviceName) . pack('a*', $params);
        $hmacHash = hash_hmac('sha512', $hmacBody, $authenticationKey);
        $hmacGetParams = '?pstn_HMACTimestamp=' . $pstn_HMACTimestamp . '&pstn_HMAC=' . $hmacHash;

        $url .= $hmacGetParams;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    function directTransaction($url, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }
}