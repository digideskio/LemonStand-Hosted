<?

class Shop_Cardstream_Payment extends Shop_PaymentType
{
    public function get_info()
    {
        return array(
            'name' => 'CardStream Integration',
            'custom_payment_form' => 'backend_payment_form.htm',
            'description' => ''
        );
    }

    public function build_config_ui($host_obj, $context = null)
    {
        $host_obj->add_field('merchantid', 'Merchant Number')->tab('Configuration')->renderAs(frm_text)->comment('Merchant Number', 'above')->validation()->fn('trim')->required('Please provide Merchant Number.');

        $host_obj->add_field('countrycode', 'Country Code')->tab('Configuration')->renderAs(frm_text)->comment('Country Code', 'above')->validation()->fn('trim')->required('Please provide you\'re country code');

        $host_obj->add_field('currencycode', 'Currency Code')->tab('Configuration')->renderAs(frm_text)->comment('Country Code', 'above')->validation()->fn('trim')->required('Please provide you\'re currency code');

        $host_obj->add_field('passphrase', 'Passphrase')->tab('Configuration')->renderAs(frm_text)->comment('Secret Key / Passphrase', 'above')->validation()->fn('trim')->required('Please provide you\'re passphrase');

    }

    /**
     * Initializes configuration data when the payment method is first created
     * Use host object to access and set fields previously added with build_config_ui method.
     * @param $host_obj ActiveRecord object containing configuration fields values
     */
    public function init_config_data($host_obj)
    {
        $host_obj->order_status = Shop_OrderStatus::get_status_paid()->id;
        $host_obj->receipt_link_text = 'Return to merchant';
    }

    /**
     * Validates configuration data before it is saved to database
     * Use host object field_error method to report about errors in data:
     * $host_obj->field_error('max_weight', 'Max weight should not be less than Min weight');
     * @param $host_obj ActiveRecord object containing configuration fields values
     */
    public function validate_config_on_save($host_obj)
    {
    }

    public function get_order_status_options($current_key_value = -1)
    {
        if ($current_key_value == -1)
            return Shop_OrderStatus::create()->order('name')->find_all()->as_array('name', 'id');

        return Shop_OrderStatus::create()->find($current_key_value)->name;
    }

    /**
     * Processes payment using passed data
     * @param array $data Posted payment form data
     * @param $host_obj ActiveRecord object containing configuration fields values
     * @param $order Order object
     */
    public function process_payment_form($data, $host_obj, $order, $back_end = false)
    {
        /*
         * We do not need any code here since payments are processed on the payment gateway server.
         */
    }


    /**
     * Registers a hidden page with specific URL. Use this method for cases when you
     * need to have a hidden landing page for a specific payment gateway. For example,
     * PayPal needs a landing page for the auto-return feature.
     * Important! Payment module access point names should have the ls_ prefix.
     * @return array Returns an array containing page URLs and methods to call for each URL:
     * return array('ls_paypal_autoreturn'=>'process_paypal_autoreturn'). The processing methods must be declared
     * in the payment type class. Processing methods must accept one parameter - an array of URL segments
     * following the access point. For example, if URL is /ls_paypal_autoreturn/1234 an array with single
     * value '1234' will be passed to process_paypal_autoreturn method
     */
    public function register_access_points()
    {
        return array(
            'ls_cardstream_notification' => 'process_payment_notification'
        );
    }

    public function get_hidden_fields($host_obj, $order, $backend = false)
    {
        $result = array();
        $amount = $order->total;

        $fields = array();
        $fields['merchantID'] = $host_obj->merchantid;
        $fields['currencyCode'] = $host_obj->currencycode;
        $fields['countryCode'] = $host_obj->countrycode;
        $fields['transactionUnique'] = $order->id;
        $fields['amount'] = str_replace(".", "", $amount);

        $fields['merchantData'] = 'LemonStand-hosted-1';
        
        $fields['redirectURL'] = root_url('/ls_cardstream_notification/' . $order->id . '?utm_nooverride=1&nocache' . uniqid(), true);

        ksort($fields);
        $sig_fields = http_build_query($fields) . $host_obj->passphrase;
        $fields['signature'] = hash('SHA512', $sig_fields);

        return $fields;
    }

    public function get_form_action($host_obj)
    {
        return "https://gateway.cardstream.com/hosted/";
    }

    public function process_payment_notification($params)
    {
        $fields = $_POST;
        $order = null;

        try {

            $qs = $_SERVER['QUERY_STRING'];
            $qs = str_replace('&amp;', '&', $qs);
            $qs = str_replace('?', '&', $qs);
            $qsbits = explode('&', $qs);
            unset($_GET);
            foreach ($qsbits as $pair) {
                $pairbits = explode('=', $pair);
                $pairbits[0] = urldecode($pairbits[0]);
                $pairbits[1] = urldecode($pairbits[1]);
                $_REQUEST[$pairbits[0]] = $pairbits[1];
                $_GET[$pairbits[0]] = $pairbits[1];
                unset($_REQUEST['amp;' . $pairbits[0]]);
            }

            /*
             * Find order and load payment method settings
             */




            $order_id = $_POST['transactionUnique'];
            if (!$order_id)
                throw new Phpr_ApplicationException('Order not found');

            $order = Shop_Order::create()->find($order_id);
            if (!$order)
                throw new Phpr_ApplicationException('Order not found.');

            if (!$order->payment_method)
                throw new Phpr_ApplicationException('Payment method not found.');

            $order->payment_method->define_form_fields();
            $payment_method_obj = $order->payment_method->get_paymenttype_object();

            if (!($payment_method_obj instanceof Shop_Cardstream_Payment))
                throw new Phpr_ApplicationException('Invalid payment method.');

            /*
             * Validate the transaction
             */

         /*   if (isset($_POST['signature'])) {
                $sign = $_POST;
                unset($sign['signature']);
                ksort($sign);
                $sig_fields = http_build_query($sign) . $payment_method_obj["params"]['passphrase'];
                $signature = hash('SHA512', $sig_fields);
            }*/

            if ($_POST['responseCode'] != "0") {
                $this->log_payment_attempt($order, 'Payment not autharised.', 0, array(), $_POST, null);

            } elseif ($_POST['amountReceived'] != str_replace(".", "", $order->total)) {
                $this->log_payment_attempt($order, 'Amount paid doesnt match the amount expected.', 0, array(), $_POST, null);

            } else {
                Shop_OrderStatusLog::create_record(2, $order);
                if ($order->set_payment_processed()) {
                    $this->log_payment_attempt($order, 'Successful payment', 1, array(), $_POST, null);
                }
            }
            $return_page = $order->payment_method->receipt_page;

            if ($return_page){
                $approved_page = $return_page->url . '/' . $order->order_hash . '?utm_nooverride=1';
            }
            $url = root_url($approved_page, true);
            echo "<meta http-equiv='refresh' content='0;url=" . $url . "'>";

        } catch (Exception $ex) {
            if ($order)
                $this->log_payment_attempt($order, $ex->getMessage(), 0, array(), $fields, null);

            throw new Phpr_ApplicationException($ex->getMessage());
        }
    }
}

?>
