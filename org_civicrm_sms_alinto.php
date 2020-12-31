<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 5                                                  |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2020                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/
/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2020
 * $Id$
 *
 */

 /**
 * SMS Provider for Alinto.
 *
 * Partly copied and mixed and matched from MySmsMantra, RingCentral and Clickatell.
 */

class org_civicrm_sms_alinto extends CRM_SMS_Provider {

  /**
   * api type to use to send a message
   * @var	string
   */
  protected $_apiType = 'http';

  /**
   * provider details
   * @var	string
   */
  protected $_providerInfo = array();

  /**
   * Alinto API Server Session ID
   *
   * @var string
   */
  protected $_sessionID = NULL;

  /**
   * Curl handle resource id
   *
   */
  protected $_ch;


  public $_apiURL = "https://scim.alinto.net/api/v2/sms";

  

   /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = array();

  /**
   * Constructor
   *
   * Create and auth a Alinto session.
   *
   * @return void
   */
  function __construct($provider = array( ), $skipAuth = FALSE) {
    // Adjust for old civi versions which pass in numeric value.

    $this->_apiType = CRM_Utils_Array::value('api_type', $provider, 'http');
    $this->_providerInfo = $provider;

    if ($skipAuth) {
      return TRUE;
    }
    // first create the curl handle

    /**
     * Reuse the curl handle
     */
    $this->_ch = curl_init();
    if (!$this->_ch || !is_resource($this->_ch)) {
      return PEAR::raiseError('Cannot initialise a new curl handle.');
    }

    curl_setopt($this->_ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($this->_ch, CURLOPT_VERBOSE, 1);
    curl_setopt($this->_ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($this->_ch, CURLOPT_COOKIEJAR, "/dev/null");
    curl_setopt($this->_ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($this->_ch, CURLOPT_USERAGENT, 'CiviCRM - http://civicrm.org/');

  }
  
   /**
   * singleton function used to manage this object
   *
   * @return object
   * @static
   *
   */
  static function &singleton($providerParams = array(
    ), $force = FALSE) {
    $providerID = CRM_Utils_Array::value('provider_id', $providerParams);
    $skipAuth   = $providerID ? FALSE : TRUE;
    $cacheKey   = (int) $providerID;

    if (!isset(self::$_singleton[$cacheKey]) || $force) {
      $provider = array();
      if ($providerID) {
        $provider = CRM_SMS_BAO_Provider::getProviderInfo($providerID);
      }
      self::$_singleton[$cacheKey] = new org_civicrm_sms_alinto($provider, $skipAuth);
    }
    return self::$_singleton[$cacheKey];
  }


  /**
   * Send an SMS Message via the Alinto API Server
   *
   * @param array the message with a recipients/message
   *
   * @return mixed true on sucess or PEAR_Error object
   * @access public
   */
  function send($recipients, $header, $message, $jobID = NULL) {
    $url = $this->_providerInfo['api_url'];
    $user = $this->_providerInfo['username'];
    $password = $this->_providerInfo['password'];

    if ($this->_apiType = 'http') {
     $postDataArray = array( 
      'message' => $message,
      'recipients' => array($recipients)   
     );
     /**
       * Put recipients in desired format.
       *
       * This function seems to be called separately for each recipient regardless of whether it's a standalone sms with multiple recipients or a bulk sms sent via the process_sms job. So:
       * $recipients seems to be a string with a single phone each time through despite being named plurally. The other provider implementations don't seem to use it.
       * $header['To'] (with a capital T) seems to be the same as $recipients and seems to be what other providers use.
       * $header['to'] (with a small t) is blank for bulk SMS. When using the standalone SMS form it seems to be a comma-separated list of all the recipients, repeated each time the function is called for each recipient. Each comma-separated value is itself a '::'-separated value with contact_id::phone, e.g. 6816::222-333-4444,6817::222-455-6778
       *
       * Use this site to convert your curl command to php: https://incarnate.github.io/curl-to-php/
       * 
       * */
      //connexion to the api with the curl command
     curl_setopt($this->_ch, CURLOPT_URL, $url);
     curl_setopt($this->_ch, CURLOPT_SSL_VERIFYHOST, Civi::settings()->get('verifySSL') ? 2 : 0);
     curl_setopt($this->_ch, CURLOPT_SSL_VERIFYPEER, Civi::settings()->get('verifySSL'));
     curl_setopt($this->_ch, CURLOPT_POST, 1);
     curl_setopt($this->_ch, CURLOPT_USERPWD, $user.':'.$password);
     $auth = base64_encode($user.':'.$password);
     curl_setopt($this->_ch, CURLOPT_HTTPHEADER, array(
      "Content-Type: application/json",
      "Accept: application/json",
      "Authorization: Basic $auth"
     ));
     //content of the message you want to post and the recipient encoded in json and convert in curl
     curl_setopt($this->_ch, CURLOPT_POSTFIELDS, json_encode($postDataArray));
    
     //added to curl command to close the inteface once the message submitted
     curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, 1);
     curl_setopt($this->_ch, CURLOPT_TIMEOUT, 36000);

     //execute the curl commande
     $response = curl_exec($this->_ch);
     

    if (empty($response)) {
      $errorMessage = 'Error: "' . curl_error($this->_ch) . '" - Code: ' . curl_errno($this->_ch);
      return PEAR::raiseError($errorMessage);
    }

    if (PEAR::isError($response)) {
      return $response;
    }
    $result = json_decode($response, TRUE);

    if (!empty($result['errorCode'])) {
      return PEAR::raiseError($result['message'], $result['errorCode']);
    }

  }
}
    
}
