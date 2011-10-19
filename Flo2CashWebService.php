<?php 

/*
  +--------------------------------------------------------------------+
  | Flo2Cash WSDL v0.1                                                 |
  +--------------------------------------------------------------------+
  | Copyright Giant Robot Ltd (c) 2007-2011                            |
  +--------------------------------------------------------------------+
  | This file is a payment processor for CiviCRM.                      |
  |                                                                    |
  | CiviCRM is free software; you can copy, modify, and distribute it  |
  | under the terms of the GNU Affero General Public License           |
  | Version 3, 19 November 2007.                                       |
  |                                                                    |
  | CiviCRM is distributed in the hope that it will be useful, but     |
  | WITHOUT ANY WARRANTY; without even the implied warranty of         |
  | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
  | See the GNU Affero General Public License for more details.        |
  |                                                                    |
  | You should have received a copy of the GNU Affero General Public   |
  | License along with this program; if not, contact CiviCRM LLC       |
  | at info[AT]civicrm[DOT]org. If you have questions about the        |
  | GNU Affero General Public License or the licensing of CiviCRM,     |
  | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
  +--------------------------------------------------------------------+
*/
 
/** 
 * 
 * @package CRM 
 * @copyright Giant Robot Ltd (c) 2007-2011 
 */ 

require_once 'CRM/Core/Payment.php';

class nz_co_giantrobot_Flo2CashWebService extends CRM_Core_Payment { 

    /**
     * mode of operation: live or test
     *
     * @var object
     * @static
     */
    static protected $_mode = null;

    static private $_singleton = null;

    static function &singleton($mode, &$paymentProcessor) {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null) {
            self::$_singleton[$processorName] = new nz_co_giantrobot_Flo2CashWebService($mode, $paymentProcessor);
        }
        return self::$_singleton[$processorName];
    }

    /** 
     * Constructor 
     *
     * @param string $mode the mode of operation: live or test
     * 
     * @return void 
     */ 
    function __construct($mode, &$paymentProcessor) {
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Flo2Cash');

        $config = CRM_Core_Config::singleton();
        
        // @TODO would be nice to expose a config option for this, but
        // CiviCRM only offers certain textfields AFAIK
        $this->_setParam('emailCustomer', 'TRUE');
        $this->_setParam('timestamp', time());
        srand(time());
        $this->_setParam('sequence', rand(1, 1000));
    }

    /** 
     * This function checks to see if we have the right config values.
     *
     * @return string the error message if any 
     * @public 
     */ 
    function checkConfig() {
        $config =& CRM_Core_Config::singleton();

        $error = array();

        if (empty($this->_paymentProcessor['user_name'])) {
            $error[] = ts('Client ID is not set in Administer CiviCRM &raquo; Configure &raquo; Global Settings &raquo; Payment Processors &raquo; ' . $this->_paymentProcessor['name']);
        }

        if (empty($this->_paymentProcessor['signature'])) {
            $error[] = ts('Account ID is not set in Administer CiviCRM &raquo; Configure &raquo; Global Settings &raquo; Payment Processors &raquo; ' . $this->_paymentProcessor['name']);
        }

        if (empty($this->_paymentProcessor['password'])) {
            $error[] = ts('Password is not set in Administer CiviCRM &raquo; Configure &raquo; Global Settings &raquo; Payment Processors &raquo; ' . $this->_paymentProcessor['name']);
        }

        if (empty($this->_paymentProcessor['url_api'])) {
            $error[] = ts('API URL is not set in Administer CiviCRM &raquo; Configure &raquo; Global Settings &raquo; Payment Processors &raquo; ' . $this->_paymentProcessor['name']);
        }

        //self::debug($this->_paymentProcessor, '$this->_paymentProcessor');

        if (! empty($error)) {
            return implode('<p>', $error);
        } else {
            return null;
        }
    }

    /**  
     * Do direct payment against Flo2Cash WSDL
     *  
     * @param array $params  name value pair of contribution datat
     *  
     * @return void  
     * @access public 
     *  
     */  
    function doDirectPayment(&$params) {
        if (! defined('CURLOPT_SSLCERT')) {
            return self::error(9001, 'Flo2Cash requires curl with SSL support');
        }

        foreach ($params as $field => $value) {
            $this->_setParam($field, $value);
        }

        $soap_vars = array(
            'Username'      => $this->_paymentProcessor['user_name'],
            'Password'      => $this->_paymentProcessor['password'],
            'AccountId'     => $this->_paymentProcessor['signature'],
            'Amount'        => sprintf('%01.2f', $this->_getParam('amount')),
            /**
             * I think this is a much nicer reference, but it is not
             * unique. If you want to do this, do it in the CiviCRM
             * alter payment processor params hook instead. 
             */
            // 'Reference'    => substr(trim($this->_getParam('billing_first_name') .' '. $this->_getParam('billing_last_name') .' ('. $this->_getParam('email') .')'), 0, 50),
            'Reference'     => $this->_getParam('invoiceID'),
            'Particular'    => $this->_getParam('description'),
            'Email'         => $this->_getParam('email'),
            'CardNumber'    => $this->_getParam('credit_card_number'),
            'CardExpiry'    => str_pad($this->_getParam('month'), 2, '0', STR_PAD_LEFT) . $exp_year = substr($this->_getParam('year'), -2),
            'CardHolderName'=> $this->_getParam('credit_card_owner'), // not used for WSDL? form element not displayed
            'CardCSC'       => $this->_getParam('cvv2'),
            'StoreCard'     => 0
        );

        // ensure amount is in F2C expected format
        $soap_vars['Amount'] = sprintf("%01.2f", $soap_vars['Amount']);

        // alter credit card type to F2C expected format
        switch ($this->_getParam('credit_card_type')) {
            case 'Visa':
                $soap_vars['CardType'] = 'VISA';
                break ;
            case 'MasterCard':
                $soap_vars['CardType'] = 'MC';
                break;
            case 'Amex':
                $soap_vars['CardType'] = 'AMEX';
                break;
            case 'Diners Club':
            case 'Diners':
                // Flo2Cash offers Diners which CiviCRM doesn't offer by default.
                // To enable this, visit
                // civicrm/admin/options/accept_creditcard?group=accept_creditcard&reset=1
                // and add a card type of 'Diners Club'
                $soap_vars['CardType'] = 'DINERS';
                break;
            default:
                // CiviCRM offers "Discover" by default, which Flo2Cash doesn't support.
                // To disable this, visit the URL below and remove the card type 'Discover'
                //   civicrm/admin/options/accept_creditcard?group=accept_creditcard&reset=1
                // 
                // You *could* try this, which would then throw an error on processing if F2C reject it.
                // $soap_vars['CardType'] = strtoupper($this->_getParam['credit_card_type']);
                return self::error(9004, 'Unsupported credit card type: '. $this->_getParam['credit_card_type']);
        }

        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $soap_vars);

        try {
            $PaymentService = new F2CSoapClient($this->_paymentProcessor['url_api']);
            $result = $PaymentService->ProcessPurchase($soap_vars);
/*
              self::debug(array(
                            '$PaymentService' => $PaymentService,
                            '$soap_vars' => $soap_vars,
                            '$result' => $result,
                            '$fault' => $fault,
                            '$this' => $this,
                            'url' => $this->_paymentProcessor['url_api'],
                          ), 'success');
*/
        }
        catch (SoapFault $fault) {
/*
              self::debug(array(
                            '$PaymentService' => $PaymentService,
                            '$soap_vars' => $soap_vars,
                            '$result' => $result,
                            '$fault' => $fault,
                            '$this' => $this,
                            'url' => $this->_paymentProcessor['url_api'],
                          ), 'failure');
*/
            $Actor = $fault->faultcode;
            $ErrorType = $fault->detail->error->errortype;
            $ErrorNumber = $fault->detail->error->errornumber;
            $ErrorMessage = $fault->detail->error->errormessage;
            return self::error(9003, $ErrorMessage);
        }
        catch (Exception $exp) {
            return self::error(9002, $excp->get_errmsg());
        }
        
        if ($result->transactionresult->Status == 'SUCCESSFUL') {
            $params['gross_amount'] = $result->transactionresult->Amount;
            $params['trxn_id'] = $result->transactionresult->TransactionId;
            $params['contribution_status_completed'] = 1;
            return $params;
        }
        else {
            return self::error(9001, 'Error: ' . $result->transactionresult->Message);
        }
    }

    /**
     * Set a field to the specified value.  Value must be a scalar (int,
     * float, string, or boolean)
     *
     * @param string $field
     * @param mixed $value
     * @return bool false if value is not a scalar, true if successful
     */ 
    function _setParam($field, $value) {
        if (! is_scalar($value)) {
            return false;
        } else {
            $this->_params[$field] = $value;
        }
    }

    /**
     * Get the value of a field if set
     *
     * @param string $field the field
     * @return mixed value of the field, or empty string if the field is
     * not set
     */
    function _getParam($field) {
        return CRM_Utils_Array::value($field, $this->_params, '');
    }

    /**
     * This payment processor is a direct payment processor, so we do not transfer.
     */
    function doTransferCheckout(&$params, $component) {
        CRM_Core_Error::fatal(ts('This function is not implemented'));
    }

    /**
     * 
     */
    function &error($errorCode = null, $errorMessage = null) {
        $e =& CRM_Core_Error::singleton();
        if ($errorCode) {
            $e->push($errorCode, 0, null, $errorMessage);
        } else {
            $e->push(9001, 0, null, 'Unknown System Error.');
        }
        return $e;
    }

    /**
     * Debug (using builtin Drupal functionality here).
     */
    function debug($debug, $label = '') {
        if (function_exists('watchdog')) {
            watchdog('civicrm', '!label: <pre>!debug</pre>', array('!label' => $label, '!debug' => htmlspecialchars(print_r($debug,1))), WATCHDOG_DEBUG);
        }
        if (function_exists('dpm')) {
            dpm($debug, $label);
        }
    }

}

class F2CSoapClient extends SoapClient {
  
    public function __doRequest($request, $location, $action, $version, $one_way = FALSE) {
/*
        nz_co_giantrobot_Flo2CashWebService::debug(array(
                'request' => $request,
                'location' => $location,
                'action' => $action,
                'version' => $version,
                'one_way' => $one_way,
           ), 'SOAP request');
*/
        $response = parent::__doRequest($request, $location, $action, $version, $one_way);
        // nz_co_giantrobot_Flo2CashWebService::debug($response, 'response');
        if (!$one_way) {
            return $response;
        }
    }

}