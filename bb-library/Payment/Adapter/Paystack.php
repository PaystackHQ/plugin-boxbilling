<?php

/**
 * Paystack for BoxBilling
 *
 * @copyright Paystack, Inc (http://www.paystack.co)
 * @license   Apache-2.0
 *
 * Copyright Paystack, Inc
 * This source file is subject to the Apache-2.0 License
 */
class Payment_Adapter_Paystack implements \Box\InjectionAwareInterface {

    private $config = array();
    protected $di;

    public function setDi($di) {
        $this->di = $di;
    }

    public function getDi() {
        return $this->di;
    }

    public function __construct($config) {
        $this->config = $config;

        if (!isset($this->config['secret_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update configuration parameter "secret_key" at "Configuration -> Payments".');
        }

        if (!isset($this->config['public_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update configuration parameter "public_key" at "Configuration -> Payments".');
        }
    }

    public static function getConfig() {
        return array(
            'supports_one_time_payments' => true,
            'description' => 'You authenticate to the Paystack API by providing one of your API keys in the request. You can manage your API keys from your account.',
            'form' => array(
                'test_secret_key' => array('text', array(
                        'label' => 'Test Secret key:',
                        'required' => false,
                    ),
                ),
                'test_public_key' => array('text', array(
                        'label' => 'Test Public key:',
                        'required' => false,
                    ),
                ),
                'secret_key' => array('text', array(
                        'label' => 'Live Secret key:',
                    ),
                ),
                'public_key' => array('text', array(
                        'label' => 'Live publishable key:',
                    ),
                ),
            ),
        );
    }

    public function getHtml($api_admin, $invoice_id, $subscription) {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    public function getAmountInCents(\Model_Invoice $invoice) {
        $invoiceService = $this->di['mod_service']('Invoice');
        return $invoiceService->getTotalWithTax($invoice) * 100;
    }

    public function getInvoiceTitle(\Model_Invoice $invoice) {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', array(':invoice_id' => $invoice->id));

        $params = array(
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title']);
        $title = __('Payment for invoice :serie:id [:title]', $params);
        if (count($invoiceItems) > 1) {
            $title = __('Payment for invoice :serie:id', $params);
        }
        return $title;
    }

    public function logError($jsonBody, $error_message, Model_Transaction $tx) {
        $tx->txn_status = 'failed';
        $tx->error = $error_message;
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if ($this->di['config']['debug']) {
            error_log($jsonBody);
        }
        throw new Exception($error_message);
    }

    public function verifyAndCaptureEvent() {
        if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' ) || !array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER)) {
            // only a post with paystack signature header gets our attention
            return null;
        }

        $input = file_get_contents('php://input');
        $secret_key = $this->config['secret_key'];
        if ($this->config['test_mode']) {
            $secret_key = $this->get_test_secret_key();
        }
        if (!$_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] || ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, $secret_key))) {
            // silently forget this ever happened
            return null;
        }

        $event = json_decode($input);
        if ($this->isEventDuplicate($event)) {
            throw new Payment_Exception('Event is duplicate');
        }
        // we only use charge.success
        if ('charge.success' == $event->event) {
            return $event;
        }
    }

    public function isEventDuplicate($event) {
        return $this->isTransactionDuplicate($event->data->reference);
    }

    public function isTransactionDuplicate($reference) {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                LIMIT 1';

        $bindings = array(
            ':transaction_id' => $reference,
        );

        $rows = $this->di['db']->getAll($sql, $bindings);
        if (count($rows) > 0) {
            return true;
        }

        return false;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id) {
        $event = $this->verifyAndCaptureEvent();

        $invoice_id = $event ? $event->data->metadata->invoice_id : $data['get']['bb_invoice_id'];

        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id);
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        $invoiceAmountInCents = $this->getAmountInCents($invoice);

        $secret_key = $this->config['secret_key'];
        if ($this->config['test_mode']) {
            $secret_key = $this->get_test_secret_key();
        }
        $title = $this->getInvoiceTitle($invoice);

        $tx->invoice_id = $invoice->id;

        $paystack = new Paystack($secret_key);

        // Get the credit card details submitted by the form
        $reference = $event ? $event->data->reference : $data['post']['reference'];
        $charge = $paystack->transaction->verify(['reference' => $reference]);
        if ($charge->error || (!('success' == $charge->data->status) )) {
            $this->logError(json_encode($charge), ($charge->error ? $charge->error : "Transaction failed"), $tx);
            return;
        }

        if (($invoice->buyer_email != $charge->data->customer->email) || ($charge->data->metadata->invoice_id != $invoice->id)) {
            $this->logError(json_encode($charge), "Transaction doesn't match Paystack Records", $tx);
            return;
        }

        $tx->txn_status = $charge->data->status;
        $tx->txn_id = $charge->data->reference;
        $tx->amount = $charge->data->amount / 100;
        $tx->currency = $charge->data->currency;
        $bd = array(
            'amount' => $tx->amount,
            'description' => 'Paystack transaction ' . $charge->data->reference,
            'type' => 'transaction',
            'rel_id' => $tx->id,
        );
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $clientService = $this->di['mod_service']('client');
        if (!$this->isTransactionDuplicate($reference)) {
            $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);
            $invoiceService = $this->di['mod_service']('Invoice');
            if ($tx->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            }
            $invoiceService->doBatchPayWithCredits(array('client_id' => $client->id));
        }

        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /**
     * @param string $url
     */
    protected function _generateForm(Model_Invoice $invoice) {
        $pubKey = $this->config['public_key'];
        if ($this->config['test_mode']) {
            $pubKey = $this->get_test_public_key();
        }

        $dataAmount = $this->getAmountInCents($invoice);

        $settingService = $this->di['mod_service']('System');
        $company = $settingService->getCompany();

        $title = $this->getInvoiceTitle($invoice);

        $form = '
        <iframe style="display:none" id="take_the_reload" name="take_the_reload"></iframe>
        <form method="post" target="take_the_reload" action=":callbackUrl" id="paystack_inline_form">
        <div class="loading" style="display:none;"><span>{% trans \'Loading ...\' %}</span></div>
          <script src="https://js.paystack.co/v1/inline.js"></script>
          <script>
              function handlePaystackCallback(response){
                if(response){
                    form = $("#paystack_inline_form");
                    form.append($(\'<input type="hidden" name="reference" />\').val(response.reference));
                    form.submit();
                }
                setTimeout(function(){
                  window.location.href=":redirectUrl";
                }, 3000);
              }
              function payWithPaystack(){
                var handler = PaystackPop.setup({
                  key: ":key",
                  email: ":email",
                  amount: ":amount",
                  currency: ":currency",
                  metadata: {
                     custom_fields: [
                        {
                            display_name: "Paid Via",
                            variable_name: "paid_via",
                            value: "Box Billing"
                        },
                        {
                            display_name: "Invoice Title",
                            variable_name: "invoice_title",
                            value: ":title"
                        },
                        {
                            display_name: "Invoice ID",
                            variable_name: "invoice_id",
                            value: ":invoice_id"
                        }
                     ],
                     invoice_id: ":invoice_id"
                  },
                  callback: function(response){
                    handlePaystackCallback(response);
                  },
                  onClose: function(){
                    handlePaystackCallback();
                  }
                });
                handler.openIframe();
              }
              $(document).ready(function(){
                payWithPaystack();
              });

              document.addEventListener("bb_ajax_post_message_error", function(e) {
                payWithPaystack();
              });
            </script>
        </form>';

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Paystack"');
        $bindings = array(
            ':key' => $pubKey,
            ':title' => $title,
            ':amount' => $dataAmount,
            ':currency' => $invoice->currency,
            ':invoice_id' => $invoice->id,
            ':description' => $title,
            ':email' => $invoice->buyer_email,
            ':callbackUrl' => $payGatewayService->getCallbackUrl($payGateway, $invoice),
            ':redirectUrl' => $this->di['tools']->url('invoice/' . $invoice->hash)
        );
        return strtr($form, $bindings);
    }

    public function get_test_public_key() {
        if (!isset($this->config['test_public_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update configuration parameter "test_public_key" at "Configuration -> Payments".');
        }
        return $this->config['test_public_key'];
    }

    public function get_test_secret_key() {
        if (!isset($this->config['test_secret_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update configuration parameter "test_secret_key" at "Configuration -> Payments".');
        }
        return $this->config['test_secret_key'];
    }

}

if (!class_exists('Paystack')) {

    class Paystack {

        public $secret_key;
        private $routes = ['customer', 'plan', 'transaction', 'page', 'subscription'];

        public function __construct($params_or_key) {
            if (is_array($params_or_key)) {
                $params = $params_or_key;
                $test_mode = array_key_exists('paystack_test_mode', $params) ? $params['paystack_test_mode'] : true;
                if ($test_mode) {
                    $secret_key = array_key_exists('paystack_key_test_secret', $params) ? trim($params['paystack_key_test_secret']) : '';
                } else {
                    $secret_key = array_key_exists('paystack_key_live_secret', $params) ? trim($params['paystack_key_live_secret']) : '';
                }
                if (!is_string($secret_key) || !(substr($secret_key, 0, 8) === 'sk_' . ($test_mode ? 'test_' : 'live_'))) {
                    // Should never get here
                    throw new \InvalidArgumentException('A Valid Paystack Secret Key must start with \'sk_\'.');
                }
            } else {
                $secret_key = trim(strval($params_or_key));
                if (!is_string($secret_key) || !(substr($secret_key, 0, 3) === 'sk_')) {
                    // Should never get here
                    throw new \InvalidArgumentException('A Valid Paystack Secret Key must start with \'sk_\'.');
                }
            }

            $this->secret_key = $secret_key;
        }

        public function __call($method, $args) {
            /*
              attempt to call fetch when the route is called directly
              translates to /{root}/{get}/{id}
             */

            if (in_array($method, $this->routes, true) && count($args) === 1) {
                $route = new PaystackHelpersRouter($method, $this);
                // no params, just one arg... the id
                $args = [[], [ PaystackHelpersRouter::ID_KEY => $args[0]]];
                return $route->__call('fetch', $args);
            }

            // Not found is it plural?
            $is_plural = strripos($method, 's') === (strlen($method) - 1);
            $singular_form = substr($method, 0, strlen($method) - 1);

            if ($is_plural && in_array($singular_form, $this->routes, true)) {
                $route = new PaystackHelpersRouter($singular_form, $this);
                if ((count($args) === 1 && is_array($args[0])) || (count($args) === 0)) {
                    return $route->__call('getList', $args);
                }
            }

            // Should never get here
            throw new InvalidArgumentException(
            'Route "' . $method . '" can only accept ' .
            ($is_plural ?
                    'an optional array of paging arguments (perPaystackRoutesPage, page)' : 'an id or code') . '.'
            );
        }

        public function __get($name) {
            if (in_array($name, $this->routes, true)) {
                return new PaystackHelpersRouter($name, $this);
            }
        }

    }

    interface PaystackContractsRouteInterface {

        const METHOD_KEY = 'method';
        const ENDPOINT_KEY = 'endpoint';
        const PARAMS_KEY = 'params';
        const ARGS_KEY = 'args';
        const REQUIRED_KEY = 'required';
        const POST_METHOD = 'post';
        const PUT_METHOD = 'put';
        const GET_METHOD = 'get';

        /**
         */
        public static function root();
    }

    class PaystackHelpersRouter {

        private $route;
        private $route_class;
        private $secret_key;
        private $methods;

        const ID_KEY = 'id';
        const PAYSTACK_API_ROOT = 'https://api.paystack.co';

        private function moveArgsToSentargs(
        $interface, &$payload, &$sentargs
        ) {

            // check if interface supports args
            if (array_key_exists(PaystackContractsRouteInterface:: ARGS_KEY, $interface)) {
                // to allow args to be specified in the payload, filter them out and put them in sentargs
                $sentargs = (!$sentargs) ? [] : $sentargs; // Make sure $sentargs is not null
                $args = $interface[PaystackContractsRouteInterface::ARGS_KEY];
                while (list($key, $value) = each($payload)) {
                    // check that a value was specified
                    // with a key that was expected as an arg
                    if (in_array($key, $args)) {
                        $sentargs[$key] = $value;
                        unset($payload[$key]);
                    }
                }
            }
        }

        private function putArgsIntoEndpoint(&$endpoint, $sentargs) {
            // substitute sentargs in endpoint
            while (list($key, $value) = each($sentargs)) {
                $endpoint = str_replace('{' . $key . '}', $value, $endpoint);
            }
        }

        private function callViaCurl($interface, $payload = [], $sentargs = []) {
            $resp = new stdClass();

            $endpoint = PaystackHelpersRouter::PAYSTACK_API_ROOT . $interface[PaystackContractsRouteInterface::ENDPOINT_KEY];
            $method = $interface[PaystackContractsRouteInterface::METHOD_KEY];

            $this->moveArgsToSentargs($interface, $payload, $sentargs);
            $this->putArgsIntoEndpoint($endpoint, $sentargs);

            $headers = ["Authorization" => "Bearer " . $this->secret_key];
            $body = '';
            if (($method === PaystackContractsRouteInterface::POST_METHOD) || ($method === PaystackContractsRouteInterface::PUT_METHOD)
            ) {
                $headers["Content-Type"] = "application/json";
                $body = json_encode($payload);
            } elseif ($method === PaystackContractsRouteInterface::GET_METHOD) {
                $endpoint = $endpoint . '?' . http_build_query($payload);
            }

            //open connection

            $ch = curl_init();
            // set url
            curl_setopt($ch, CURLOPT_URL, $endpoint);

            if ($method === PaystackContractsRouteInterface::POST_METHOD || $method === PaystackContractsRouteInterface::PUT_METHOD) {
                ($method === PaystackContractsRouteInterface:: POST_METHOD) && curl_setopt($ch, CURLOPT_POST, true);
                ($method === PaystackContractsRouteInterface ::PUT_METHOD) && curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");

                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            //flatten the headers
            $flattened_headers = [];
            while (list($key, $value) = each($headers)) {
                $flattened_headers[] = $key . ": " . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $flattened_headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            // Make sure CURL_SSLVERSION_TLSv1_2 is defined as 6
            // Curl must be able to use TLSv1.2 to connect
            // to Paystack servers

            if (!defined('CURL_SSLVERSION_TLSV1_2')) {
                define('CURL_SSLVERSION_TLSV1_2', 6);
            }
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSV1_2);

            $response = curl_exec($ch);
            $resp->error = false;

            if (curl_errno($ch)) {   // should be 0
                // curl ended with an error
                $cerr = curl_error($ch);
                curl_close($ch);
                $resp->error = "Curl failed with response: '" . $cerr . "'.";
                return $resp;
            }
            $resp = json_decode($response);

            // Then, after your curl_exec call:
            //close connection
            curl_close($ch);

            if (!$resp->status) {
                $resp->error = "Paystack Request failed with response: '" . $resp->message . "'.";
            }

            return $resp;
        }

        public function __call($methd, $sentargs) {
            $method = ($methd === 'list' ? 'getList' : $methd );
            if (array_key_exists($method, $this->methods) && is_callable($this->methods[$method])) {
                return call_user_func_array($this->methods[$method], $sentargs);
            } else {
                // User attempted to call a function that does not exist
                throw new Exception('Function "' . $method . '" does not exist for "' . $this->route . '".');
            }
        }

        public function __construct($route, $paystackObj) {
            $this->route = strtolower($route);
            $this->route_class = 'PaystackRoutes' . ucwords($route);
            $this->secret_key = $paystackObj->secret_key;

            $mets = get_class_methods($this->route_class);
            if (empty($mets)) {
                throw new InvalidArgumentException('Class "' . $this->route . '" does not exist.');
            }
            // add methods to this object per method, except root
            foreach ($mets as $mtd) {
                if ($mtd === 'root') {
                    // skip root method
                    continue;
                }
                $mtdFunc = function (
                        array $params = [],
                        array $sentargs = []
                        ) use ($mtd) {
                    $interface = call_user_func($this->route_class . '::' . $mtd);
                    // TODO: validate params and sentargs against definitions
                    return $this->callViaCurl($interface, $params, $sentargs);
                };
                $this->methods[$mtd] = Closure::bind($mtdFunc, $this, get_class());
            }
        }

    }

    class PaystackRoutesCustomer implements PaystackContractsRouteInterface {

        public static function root() {
            return '/customer';
        }

        public static function create() {
            return [
                PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesCustomer::root(),
                PaystackContractsRouteInterface::PARAMS_KEY => ['first_name',
                    'last_name',
                    'email',
                    'phone'],
                PaystackContractsRouteInterface::REQUIRED_KEY => [
                    PaystackContractsRouteInterface::PARAMS_KEY => ['first_name',
                        'last_name',
                        'email']
                ]
            ];
        }

        public static function fetch() {
            return [
                PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesCustomer::root() . '/{id}',
                PaystackContractsRouteInterface::ARGS_KEY => ['id'],
                PaystackContractsRouteInterface::REQUIRED_KEY => [PaystackContractsRouteInterface::ARGS_KEY => ['id']]
            ];
        }

        public static function getList() {
            return [
                PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesCustomer::root(),
                PaystackContractsRouteInterface::PARAMS_KEY => ['perPaystackRoutesPage',
                    'page']
            ];
        }

        public static function update() {
            return [
                PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::PUT_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesCustomer::root() . '/{id}',
                PaystackContractsRouteInterface::PARAMS_KEY => ['first_name',
                    'last_name',
                    'email',
                    'phone'],
                PaystackContractsRouteInterface::ARGS_KEY => ['id'],
                PaystackContractsRouteInterface::REQUIRED_KEY => [
                    PaystackContractsRouteInterface::ARGS_KEY => ['id'],
                    PaystackContractsRouteInterface::PARAMS_KEY => ['first_name',
                        'last_name']
                ]
            ];
        }

    }

    class PaystackRoutesPage implements PaystackContractsRouteInterface {

        public static function root() {
            return '/page';
        }

        public static function create() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPage::root(),
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'name',
                    'description',
                    'amount']
            ];
        }

        public static function fetch() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPage::root() . '/{id}',
                PaystackContractsRouteInterface::ARGS_KEY => ['id']];
        }

        public static function getList() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPage::root()];
        }

        public static function update() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::PUT_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPage::root() . '/{id}',
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'name',
                    'description'],
                PaystackContractsRouteInterface::ARGS_KEY => ['id']];
        }

    }

    class PaystackRoutesPlan implements PaystackContractsRouteInterface {

        public static function root() {
            return '/plan';
        }

        public static function create() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPlan::root(),
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'name',
                    'description',
                    'amount',
                    'interval',
                    'send_invoices',
                    'send_sms',
                    'hosted_page',
                    'hosted_page_url',
                    'hosted_page_summary',
                    'currency']
            ];
        }

        public static function fetch() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPlan::root() . '/{id}',
                PaystackContractsRouteInterface::ARGS_KEY => ['id']];
        }

        public static function getList() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPlan::root()];
        }

        public static function update() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::PUT_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesPlan::root() . '/{id}',
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'name',
                    'description',
                    'amount',
                    'interval',
                    'send_invoices',
                    'send_sms',
                    'hosted_page',
                    'hosted_page_url',
                    'hosted_page_summary',
                    'currency'],
                PaystackContractsRouteInterface::ARGS_KEY => ['id']];
        }

    }

    class PaystackRoutesSubscription implements PaystackContractsRouteInterface {

        public static function root() {
            return '/subscription';
        }

        public static function create() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesSubscription::root(),
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'customer',
                    'plan',
                    'authorization']
            ];
        }

        public static function fetch() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesSubscription::root() . '/{id}',
                PaystackContractsRouteInterface::ARGS_KEY => ['id']];
        }

        public static function getList() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesSubscription::root()];
        }

        public static function disable() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesSubscription::root() . '/disable',
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'code',
                    'token']];
        }

        public static function enable() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesSubscription::root() . '/enable',
                PaystackContractsRouteInterface::PARAMS_KEY => [
                    'code',
                    'token']];
        }

    }

    class PaystackRoutesTransaction implements PaystackContractsRouteInterface {

        public static function root() {
            return '/transaction';
        }

        public static function initialize() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/initialize',
                PaystackContractsRouteInterface::PARAMS_KEY => ['reference',
                    'amount',
                    'email',
                    'plan']
            ];
        }

        public static function charge() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/charge_authorization',
                PaystackContractsRouteInterface::PARAMS_KEY => ['reference',
                    'authorization_code',
                    'email',
                    'amount']];
        }

        public static function chargeToken() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::POST_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/charge_token',
                PaystackContractsRouteInterface::PARAMS_KEY => ['reference',
                    'token',
                    'email',
                    'amount']];
        }

        public static function fetch() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/{id}',
                PaystackContractsRouteInterface::ARGS_KEY => ['id']];
        }

        public static function getList() {
            return [ PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root()];
        }

        public static function export() {
            return [ PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/export'];
        }

        public static function totals() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/totals'];
        }

        public static function verify() {
            return [PaystackContractsRouteInterface::METHOD_KEY => PaystackContractsRouteInterface::GET_METHOD,
                PaystackContractsRouteInterface::ENDPOINT_KEY => PaystackRoutesTransaction::root() . '/verify/{reference}',
                PaystackContractsRouteInterface::ARGS_KEY => ['reference']];
        }

    }

}