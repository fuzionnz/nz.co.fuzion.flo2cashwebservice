<?php

/**
 * @file
 *
 * Flo2Cash WebService payment processor for CiviCRM.
 *
 * @package CRM
 * @copyright Giant Robot Ltd 2007-2012
 */

/*
  +--------------------------------------------------------------------+
  | Flo2Cash WebService v1.0                                          |
  +--------------------------------------------------------------------+
  | Copyright Giant Robot Ltd (c) 2007-2012                            |
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
 * Base CiviCRM Payment class.
 */
require_once 'CRM/Core/Payment.php';

/**
 * Implement a Payment Method class for CiviCRM.
 */
class nz_co_fuzion_Flo2CashWebService extends CRM_Core_Payment {

  /**
   * Mode of operation: live or test.
   *
   * @var object
   * @static
   */
  static protected $_mode = NULL;

  /**
   * Singleton pattern.
   */
  static private $_singleton = NULL;

  /**
   * @TODO Document this function.
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new nz_co_fuzion_Flo2CashWebService($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Constructor for nz_co_fuzion_Flo2CashWebService.
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('Flo2Cash WebService');

    // Defaults here
    $this->_setParam('emailCustomer', 'TRUE');
    // We'll load settings from DB if present.
    $settings = array(
      'emailCustomer',
    );
    foreach ($settings as $setting) {
      $setting_name = 'nz.co.fuzion.flo2cashwebservice.'.$setting;
      $params = array(
        'return' => $setting_name,
        'sequential' => 1,
      );
      try {
        $result = civicrm_api3('setting', 'get', $params);
        if (isset($result['values'][0][$setting_name])) {
          $this->_setParam($setting, $settings['values'][0][$setting_name]);
        }
      }
      catch (CiviCRM_API3_Exception $e) {
        $error_message = $e->getMessage();
        $error_code = $e->getErrorCode();
        $error_data = $e->getExtraParams();
      }
    }
  }

  /**
   * Validate configuration values.
   *
   * @return NULL | string error message
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

    if (! empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Do direct payment against Flo2Cash WSDL.
   *
   * @param array $params
   *   name value pair of contribution datat
   *
   * @return array
   *   $params or CiviCRM error array
   *
   * @access public
   */
  function doDirectPayment(&$params) {
    if (!defined('CURLOPT_SSLCERT')) {
      return self::error(9001, 'Flo2Cash WebService requires curl with SSL support');
    }

    foreach ($params as $field => $value) {
      $this->_setParam($field, $value);
    }

    // Ensure amount is in F2C expected format.
    $soap_vars['Amount'] = sprintf("%01.2f", $soap_vars['Amount']);

    $reference = $this->_getParam('last_name') .
      ', ' . $this->_getParam('first_name') .
      ' - ' . $this->_getParam('email');
    $reference = substr($reference, 0, 50);

    // Alter credit card type to F2C expected format.
    switch ($this->_getParam('credit_card_type')) {
      case 'Visa':
        $card_type = 'VISA';
        break;

      case 'MasterCard':
        $card_type = 'MC';
        break;

      case 'Amex':
        $card_type = 'AMEX';
        break;

      case 'Diners Club':
      case 'Diners':
        // Flo2Cash WebService offers Diners which CiviCRM doesn't
        // offer by default.  To enable this, visit
        //
        // civicrm/admin/options/accept_creditcard?group=accept_creditcard&reset=1
        //
        // and add a card type of 'Diners Club'
        $card_type = 'DINERS';
        break;

      default:
        // CiviCRM offers "Discover" by default, which Flo2Cash WebService
        // doesn't support.  To disable this, visit the URL below and
        // remove the card type 'Discover'
        //
        // civicrm/admin/options/accept_creditcard?group=accept_creditcard&reset=1
        //
        // You *could* try this, which would then throw an error on
        // processing if F2C reject it.
        //
        // $card_type = strtoupper($this->_getParam['credit_card_type']);
        return self::error(9004, 'Unsupported credit card type: ' . $this->_getParam['credit_card_type']);
    }

    // Frequency ID - only used for recurring.
    switch ($this->_getParam('frequency_unit')) {
      case 'week':
        $frequency_id = 2;
        break;

      case 'month':
        $frequency_id = 7;
        break;

      case 'year':
        $frequency_id = 13;
        break;

      default:
        $frequency_id = 0;
        break;
    }

    if ($params['is_recur']) {
      $soap_method = 'CreateRecurringCreditCardPlan';
      $soap_vars = array(
        'Username'       => $this->_paymentProcessor['user_name'],
        'Password'       => $this->_paymentProcessor['password'],
        'PlanDetails'    => array(
           // F2C don't seem to validate this anyway?
          // 'CardName'              => '',
          'CardName'             => $this->_getParam('first_name') . ' ' . $this->_getParam('last_name'),
          // 'CardName'             => $this->_getParam('credit_card_owner'),
          'CardNumber'            => $this->_getParam('credit_card_number'),
          'CardType'              => $card_type,
          'CardExpiry'            => str_pad($this->_getParam('month'), 2, '0', STR_PAD_LEFT) . $exp_year = substr($this->_getParam('year'), -2),
          'Amount'                => sprintf('%01.2f', $this->_getParam('amount')),
          // New Zealand – See Appendix B.
          'CountryID'             => 112,
          'ClientId'              => $this->_paymentProcessor['user_name'],
          'ClientAccountId'       => $this->_paymentProcessor['signature'],
           // See Appendix C.
          'FrequencyId'           => $frequency_id,
          // 'NumberOfPayment'      => 10,
          // 'terminationdate'      => '2008/12/25',
          'StartDate'             => strtotime('tomorrow'),
          'Reference'             => $reference,
          'Particular'            => $params['invoiceID'],
          // Merchant Particular.
          // 'Reference'            => $this->_getParam( 'credit_card_owner' ),
        ),
      );
      // dpm(var_export($soap_vars,1), 'vars');
    }
    else {
      $soap_method = 'ProcessPurchase';
      $soap_vars = array(
        'Username'      => $this->_paymentProcessor['user_name'],
        'Password'      => $this->_paymentProcessor['password'],
        'AccountId'     => $this->_paymentProcessor['signature'],
        'Amount'        => sprintf('%01.2f', $this->_getParam('amount')),
        'Reference'     => $reference,
        'Particular'    => $params['invoiceID'],
        'Email'         => $this->_getParam('email'),
        'CardNumber'    => $this->_getParam('credit_card_number'),
        'CardType'      => $card_type,
        'CardExpiry'    => str_pad($this->_getParam('month'), 2, '0', STR_PAD_LEFT) . $exp_year = substr($this->_getParam('year'), -2),
        // Not used for WSDL?
        // 'CardHolderName' => $this->_getParam('credit_card_number'),
        'CardCSC'       => $this->_getParam('cvv2'),
        'StoreCard'     => 0,
      );
    }

    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $soap_vars);

    try {
      $payment_service = new F2CSoapClient($this->_paymentProcessor['url_api'], array('trace' => 1));
      $result = $payment_service->$soap_method($soap_vars);
    }
    catch (SoapFault $fault) {
      if (isset($fault->faultcode)) {
        $actor = $fault->faultcode;
      }
      if (isset($fault->detail->error->errortype)) {
        $error_type = $fault->detail->error->errortype;
      }
      if (isset($fault->detail->error->errornumber)) {
        $error_number = $fault->detail->error->errornumber;
      }
      if (isset($fault->detail->error->errormessage)) {
        $error_message = $fault->detail->error->errormessage;
      }
      elseif (isset($fault->faultstring)) {
        $error_message = $fault->faultstring;
      }
      else {
        $error_message = 'Unknown SOAP error.';
      }
      return self::error(9003, $error_message);
    }
    catch (Exception $ex) {
      return self::error(9002, $ex->get_errmsg());
    }
  }

  /**
   * Set a field to the specified (scalar) value.
   *
   * @param string $field
   *   Field to set value of.
   *
   * @param mixed $value
   *   Value to set in $field.
   *
   * @return bool
   *   FALSE if value is not a scalar, TRUE if successful.
   */
  function _setParam($field, $value) {
    if (!is_scalar($value)) {
      return FALSE;
    }
    else {
      $this->_params[$field] = $value;
    }
  }

  /**
   * Get the value of a field if set.
   *
   * @param string $field
   *   The field to obtain a value from.
   *
   * @return mixed
   *   Value of the field, or empty string if the field is
   *   not set
   */
  function _getParam($field) {
    return CRM_Utils_Array::value($field, $this->_params, '');
  }

  /**
   * This payment processor is a direct payment processor, so we do
   * not transfer.
   */
  function doTransferCheckout(&$params, $component) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * @TODO Document this function.
   */
  function &error($error_code = NULL, $error_message = NULL) {
    $e =& CRM_Core_Error::singleton();
    if ($error_code) {
      $e->push($error_code, 0, NULL, $error_message);
    }
    else {
      $e->push(9001, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

  /**
   * Debug (using builtin Drupal functionality here).
   */
  function debug($debug, $label = '') {
    if (function_exists('watchdog')) {
      $wargs = array('!label' => $label, '!debug' => htmlspecialchars(print_r($debug, 1)));
      watchdog('civicrm', '!label: <pre>!debug</pre>', $wargs, WATCHDOG_DEBUG);
    }
    if (function_exists('dpm')) {
      dpm($debug, $label);
    }
  }
}

/**
 * Wrapper for SOAP class.
 */
class F2CSoapClient extends SoapClient {

  /**
   * Implements SOAP::__doRequest().
   */
  public function __doRequest($request, $location, $action, $version, $one_way = FALSE) {
    $response = parent::__doRequest($request, $location, $action, $version, $one_way);
    // nz_co_fuzion_Flo2CashWebService::debug($response, 'response');
    if (!$one_way) {
      return $response;
    }
  }
}
