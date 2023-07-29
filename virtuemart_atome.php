<?php

defined('_JEXEC') or die('Restricted access');


if (!class_exists('vmPSPlugin')) {
    require JPATH_VM_PLUGINS.DS.'vmpsplugin.php';
}

class plgVmPaymentVirtuemart_atome extends vmPSPlugin
{
	
	
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }


    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Atome Table');
    }


    public function getTableSQLFields()
    {
        $SQLfields = [
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(255)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'cost_per_transaction'        => 'decimal(10,2)',
            'cost_percent_total'          => 'decimal(10,2)',
            'tax_id'                      => 'smallint(1)',
            'reference'                   => 'varchar(255)',
        ];

        return $SQLfields;
    }


    public function plgVmConfirmedOrder($cart, $order)
    {
		$db = &JFactory::getDBO();
		$app = JFactory::getApplication();
		
        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return; 
        }
		
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require VMPATH_ADMIN.DS.'models'.DS.'orders.php';
        }
		
        if (!class_exists('VirtueMartModelCurrency')) {
            require VMPATH_ADMIN.DS.'models'.DS.'currency.php';
        }
		

		$method = new stdClass;
        $params = $this->_currentMethod;
        $usrBT = $order['details']['BT'];
        $address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
        $this->getPaymentCurrency($method);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'.$method->payment_currency.'" ';
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();	
		
		$username = $params->username;
		$password = $params->password;
		$typemode = $params->type;
		$currency_in_param = $params->currency;
		
		$currency_code = $currency_in_param ? $currency_in_param : $currency_code_3;
		$amount = $order['details']['BT']->order_total;
		$amount = number_format((float)$amount, 2, '', '');
		
		// CUSTOMER
		$fullName = $order['details']['BT']->first_name .' '.$order['details']['BT']->last_name;
		$mobileNumber = $order['details']['BT']->phone_2;
		$email = $order['details']['BT']->email;		
		
		// SHIPPING
        $q = 'SELECT * FROM `#__virtuemart_countries` WHERE `virtuemart_country_id`='. (int)$order['details']['ST']->virtuemart_country_id;
        $db->setQuery($q);
        $co = $db->loadObject();			
        $country_2_code = $co->country_2_code;			
        $country_name = $co->country_name;			
		$shipping_zipcode = $order['details']['ST']->zip;
		
		// ITEMS
		$items = array();
		foreach($order['items'] as $item){
			$items[] = array(
				'itemId' => $item->virtuemart_product_id,
				'name' => $item->order_item_name,
				'quantity' => $item->product_quantity,
				'price' => number_format((float)$item->product_final_price, 2, '', ''),
				'originalPrice' => number_format((float)$item->product_item_price, 2, '', ''),
			);
		}
		
		if($typemode == 'test'){
			$url = 'https://api.apaylater.net/v2/';
		} else {
			$url = 'https://api.apaylater.com/v2/';
		}
		
		$data = array(
			"referenceId" => $order['details']['BT']->order_number,
			"merchantReferenceId" => $order['details']['BT']->order_number,
			"amount" => $amount,
			"currency" => $currency_code,
			"callbackUrl" => JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&type=callback&on='.$order['details']['BT']->order_number.'&pm='.$order['details']['BT']->virtuemart_paymentmethod_id,
			"paymentResultUrl" => JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&type=result&on='.$order['details']['BT']->order_number.'&pm='.$order['details']['BT']->virtuemart_paymentmethod_id,
			"paymentCancelUrl" => JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&type=cancel&on='.$order['details']['BT']->order_number.'&pm='.$order['details']['BT']->virtuemart_paymentmethod_id.'&Itemid=' . vRequest::getInt('Itemid'),
			"customerInfo" => array(
					"mobileNumber" => $mobileNumber ? $mobileNumber : null,
					"fullName" => $fullName,
					"email" => $email,
				),
			"shippingAddress" => array(
					"countryCode" => $country_2_code,
					"lines" => array($order['details']['ST']->address_1, $country_name.",".$shipping_zipcode),
					"postCode" => $shipping_zipcode
				),
			"items" => $items	
		);

		//echo '<pre>';print_r($data);echo '</pre>';die;		
		
		$ch = curl_init($url.'payments');
		$headers = array(
			'Content-Type:application/json',
			'Authorization: Basic '. base64_encode($username.":".$password)
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		//curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$return = curl_exec($ch);
		curl_close($ch);		
		$return = json_decode($return, true);
		
		if($return['code'] AND $return['message']){
			$app->enqueueMessage($return['message'], 'error');
			$app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&Itemid=' . vRequest::getInt('Itemid'), false));
			return;
		}
		
        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);		
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $method->payment_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);		
		
		header('Location: ' . $return['redirectUrl']);
		return;		
    }
	
	
	

    public function plgVmOnPaymentResponseReceived(&$html)
    {

		$app = JFactory::getApplication();
        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
        $type = JRequest::getVar('type');
        $order_number = JRequest::getVar('on', 0);    
		$modelOrder = VmModel::getModel ('orders');
		$norder = array();

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; 
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartCart')) {
            require JPATH_VM_SITE.DS.'helpers'.DS.'cart.php';
        }
        if (!class_exists('shopFunctionsF')) {
            require JPATH_VM_SITE.DS.'helpers'.DS.'shopfunctionsf.php';
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php';
        }
		
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		
		if($type == 'cancel' OR !$type){
			
			$norder['order_status'] = $method->status_canceled;
			$norder['customer_notified'] = 0;					
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $norder, true);	
			$cart = VirtueMartCart::getCart();
			$cart->emptyCart();				
			$app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&Itemid=' . vRequest::getInt('Itemid'), false));
			return;
		}

        if ($virtuemart_order_id) {
            $cart = VirtueMartCart::getCart();
			$orderModel = VmModel::getModel('orders');
			$order = $orderModel->getOrder($virtuemart_order_id);	
			$username = $method->username;
			$password = $method->password;
			$typemode = $method->type;

			if($typemode == 'test'){
				$url = 'https://api.apaylater.net/v2/';
			} else {
				$url = 'https://api.apaylater.com/v2/';
			}			

			if($type == 'result'){

				$ch = curl_init($url.'payments/'. $order_number);
				$headers = array(
					'Content-Type:application/json',
					'Authorization: Basic '. base64_encode($username.":".$password)
				);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);
				curl_setopt($ch, CURLOPT_POST, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				$return = curl_exec($ch);
				curl_close($ch);		
				$return = json_decode($return, true);
				//print_r($return['paymentTransaction']['transactionId']);
				if($return['status'] == 'PAID'){	
					$cart = VirtueMartCart::getCart();
					$cart->emptyCart();					
					$app->enqueueMessage(vmText::_('VMPAYMENT_ATOME_SUCCESS_PAYMENT') ,'success');
				} else {
					$app->enqueueMessage(vmText::_('VMPAYMENT_ATOME_FAILED_PAYMENT') ,'error');
				}				

			} elseif($type == 'callback') {
				
				$ch = curl_init($url.'payments/'. $order_number);
				$headers = array(
					'Content-Type:application/json',
					'Authorization: Basic '. base64_encode($username.":".$password)
				);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);
				curl_setopt($ch, CURLOPT_POST, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				$return = curl_exec($ch);
				curl_close($ch);		
				$return = json_decode($return, true);
				
				if($return['status'] == 'PAID'){
					$dbValues = array();
					$dbValues['order_number'] = $order_number;
					$dbValues['reference'] = $return['paymentTransaction']['transactionId'];
					$this->storePSPluginInternalData($dbValues);
					$norder['order_status'] = $method->status_success;
					$norder['customer_notified'] = 1;
					$cart = VirtueMartCart::getCart();
					$cart->emptyCart();							
				
				} else {
					
					$norder['order_status'] = $method->status_pending;
					$norder['customer_notified'] = 0;						
				}
								
				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $norder, true);
			}			
		}	
		return;
    }

		
	
	
    /**
     * Display stored payment data for an order.
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return;
        }
        VmConfig::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">'."\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE(vmText::_('VMPAYMENT_ATOME_PAYMENT_METHOD') , 'Atome');
        $html .= $this->getHtmlRowBE(vmText::_('VMPAYMENT_ATOME_TOTAL') , $paymentTable->payment_order_total);
        if ($paymentTable->email_currency) {
            $html .= $this->getHtmlRowBE(vmText::_('VMPAYMENT_ATOME_CURRENCY') , $paymentTable->email_currency);
        }
        $html .= '</table>'."\n";

        return $html;
    }



    /**
     * Check if the payment conditions are fulfilled for this payment method.
     *
     *
     * @param $cart_prices: cart prices
     * @param $payment
     *
     * @return true: if the conditions are fulfilled, false otherwise
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->convert_condition_amount($method);
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount_cond = ($amount >= $method->min_amount and $amount <= $method->max_amount
            or
            ($method->min_amount <= $amount and ($method->max_amount == 0)));
        if (!$amount_cond) {
            return false;
        }
        $countries = [];
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = [];
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            return true;
        }

        return false;
    }



    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the en method to create the tables.
     *
     * @author Valérie Isaksen
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     *
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel.
     *
     * @param object $cart     Cart object
     * @param int    $selected ID of the method selected
     *
     * @return bool True on succes, false on failures, null when this plugin was not selected.
     *              On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
	* plgVmonSelectedCalculatePricePayment
	* Calculate the price (value, tax_id) of the selected method
	* It is called by the calculator
	* This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	* @author Valerie Isaksen
	* @cart: VirtueMartCart the current cart
	* @cart_prices: array the new cart prices
	* @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
	*
	*
	*/

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

	
    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type.
     *
     * @author Valerie Isaksen
     *
     * @param VirtueMartCart cart: the cart object
     *
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param int $order_id The order ID
     *
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     *
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * @param $orderDetails
     * @param $data
     *
     * @return null
     */
    public function plgVmOnUserInvoice($orderDetails, &$data)
    {
        if (!($method = $this->getVmPluginMethod($orderDetails['virtuemart_paymentmethod_id']))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return;
        }
        //vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

        if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null == 1 or $orderDetails['order_total'] > 0.00) {
            return;
        }

        if ($orderDetails['order_salesPrice'] == 0.00) {
            $data['invoice_number'] = 'reservedByPayment_'.$orderDetails['order_number']; // Nerver send the invoice via email
        }
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     *
     * @return bool|null
     */
    public function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        if (empty($payments[0]->email_currency)) {
            $vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
            $db = JFactory::getDBO();
            $q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`='.$vendorId;
            $db->setQuery($q);
            $emailCurrencyId = $db->loadResult();
        } else {
            $emailCurrencyId = $payments[0]->email_currency;
        }
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return bool True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     *
     * @author Max Milbers

     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param int $_virtuemart_order_id The order ID
     * @param int $method_id            method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     *
     * @author Valerie Isaksen
     */
    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
	


	
}
