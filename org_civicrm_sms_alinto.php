<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.5                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2014                                |
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
 * @copyright CiviCRM LLC (c) 2004-2014
 * $Id$
 *
 */
class org_civicrm_sms_alinto extends CRM_SMS_Provider {
  
  CONST MAX_SMS_CHAR = 459;
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

  /**
   * Temporary file resource id
   * @var	resource
   */
  protected $_fp;

  protected $_messageType = array(
    'SMS_TEXT',
    'SMS_FLASH',
    'SMS_NOKIA_OLOGO',
    'SMS_NOKIA_GLOGO',
    'SMS_NOKIA_PICTURE',
    'SMS_NOKIA_RINGTONE',
    'SMS_NOKIA_RTTL',
    'SMS_NOKIA_CLEAN',
    'SMS_NOKIA_VCARD',
    'SMS_NOKIA_VCAL',
  );

  protected $_messageStatus = array(
    '001' => 'Message unknown',
    '002' => 'Message queued',
    '003' => 'Delivered',
    '004' => 'Received by recipient',
    '005' => 'Error with message',
    '006' => 'User cancelled message delivery',
    '007' => 'Error delivering message',
    '008' => 'OK',
    '009' => 'Routing error',
    '010' => 'Message expired',
    '011' => 'Message queued for later delivery',
    '012' => 'Out of credit',
    '013' => 'Alinto cancelled message delivery',
    '014' => 'Maximum MT limit exceeded',
  );

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
   * @param array $provider
   * @param bool $skipAuth
   *
   * @return \org_civicrm_sms_alinto
   */
  function __construct($provider = array( ), $skipAuth = FALSE) {
    // initialize vars
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
    if (ini_get('open_basedir') == '' && ini_get('safe_mode') == 'Off') {
      curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, 1);
    }
    curl_setopt($this->_ch, CURLOPT_COOKIEJAR, "/dev/null");
    curl_setopt($this->_ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($this->_ch, CURLOPT_USERAGENT, 'CiviCRM - http://civicrm.org/');

    // GK 13102017 - New API doesn't have authenticate endpoint
    // $this->authenticate();
  }

  /**
   * singleton function used to manage this object
   *
   * @param array $providerParams
   * @param bool $force
   * @return object
   * @static
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
   * Authenticate to the Alinto API Server.
   *
   * @return mixed true on sucess or PEAR_Error object
   * @access public
   * @since 1.1
   */
  function authenticate() {
    $url = $this->_providerInfo['api_url'] . "/http/auth";

    $postDataArray = array(
      'user'     => $this->_providerInfo['username'],
      'password' => $this->_providerInfo['password'],
      'api_id'    => $this->_providerInfo['api_params']['api_id']
    );

    if (array_key_exists('is_test', $this->_providerInfo['api_params']) &&
        $this->_providerInfo['api_params']['is_test'] == 1 ) {
        $response = array('data' => 'OK:' . rand());
    } else {
      $postData = $this->urlEncode($postDataArray);
      $response = $this->curl($url, $postData);
    }
    if (PEAR::isError($response)) {
      return $response;
    }
    $sess = explode(":", $response['data']);

    $this->_sessionID = trim($sess[1]);

    if ($sess[0] == "OK") {
      return TRUE;
    }
    else {
      return PEAR::raiseError($response['data']);
    }
  }

  /**
   * @param $url
   * @param $postDataArray
   * @param null $id
   *
   * @return object|string
   */
  function formURLPostData($url, &$postDataArray, $id = NULL) {
    $url = $this->_providerInfo['api_url'] . $url;
    // GK 13102017 - New API doesn't need this param
    // $postDataArray['session_id'] = $this->_sessionID;
    if ($id) {
      if (strlen($id) < 32 || strlen($id) > 32) {
        return PEAR::raiseError('Invalid API Message Id');
      }
      $postDataArray['apimsgid'] = $id;
    }
    return $url;
  }

  /**
   * Send an SMS Message via the Alinto API Server
   *
   * @param $recipients
   * @param $header
   * @param $message
   * @param null $jobID
   * @param null $userID
   * @internal param \the $array message with a to/from/text
   *
   * @return mixed true on sucess or PEAR_Error object
   * @access public
   */
  function send($recipients, $header, $message, $jobID = NULL, $userID = NULL) {
    if ($this->_apiType == 'http') {
      $postDataArray = array( );
      $url = $this->formURLPostData("/messages/http/send", $postDataArray);

      if (array_key_exists('from', $this->_providerInfo['api_params'])) {
        $postDataArray['from'] = $this->_providerInfo['api_params']['from'];
      }
      if (array_key_exists('concat', $this->_providerInfo['api_params'])) {
        $postDataArray['concat'] = $this->_providerInfo['api_params']['concat'];
      }
      //TODO:
      $postDataArray['to']   = $header['To'];
	   // JS 25042018 - Content for Second Url
	  $postDataArray['text'] = utf8_decode(substr($message, 0, 460)); // max of 460 characters, is probably not multi-lingual
      $postDataArray['content'] = utf8_decode(substr($message, 0, 460)); // max of 460 characters, is probably not multi-lingual
      if (array_key_exists('mo', $this->_providerInfo['api_params'])) {
        $postDataArray['mo'] = $this->_providerInfo['api_params']['mo'];
      }
      // sendmsg with callback request:
      $postDataArray['callback'] = 3;

      $isTest = 0;
      if (array_key_exists('is_test', $this->_providerInfo['api_params']) &&
        $this->_providerInfo['api_params']['is_test'] == 1
      ) {
        $isTest = 1;
      }

      /**
       * Check if we are using a queue when sending as each account
       * with Alinto is assigned three queues namely 1, 2 and 3.
       */
      if (isset($header['queue']) && is_numeric($header['queue'])) {
        if (in_array($header['queue'], range(1, 3))) {
          $postDataArray['queue'] = $header['queue'];
        }
      }

      /**
       * Must we escalate message delivery if message is stuck in
       * the queue at Alinto?
       */
      if (isset($header['escalate']) && !empty($header['escalate'])) {
        if (is_numeric($header['escalate'])) {
          if (in_array($header['escalate'], range(1, 2))) {
            $postDataArray['escalate'] = $header['escalate'];
          }
        }
      }

      if ($isTest == 1) {
        $response = array('data' => 'ID:' . rand());
      }
      else {
        $postData = $this->urlEncode($postDataArray);
        $response = $this->curl($url, $postData);
      }

      if ($response['error']) {
        $errorMessage = $response['error'];
        CRM_Core_Session::setStatus(ts($errorMessage), ts('Sending SMS Error'), 'error');
        // TODO: Should add a failed activity instead.
        CRM_Core_Error::debug_log_message($response . " - for phone: {$postDataArray['to']}");
        return;
      } else {
        $data = $response['messages'][0];

        $this->createActivity($data->apiMessageId, $message, $header, $jobID, $userID);
        return $data->apiMessageId;
      }

    }
  }

  /**
   * @return bool
   */
  function callback() {
    $apiMsgID = $this->retrieve('apiMsgId', 'String');

    $activity = new CRM_Activity_DAO_Activity();
    $activity->result = $apiMsgID;

    if ($activity->find(TRUE)) {
      $actStatusIDs = array_flip(CRM_Core_OptionGroup::values('activity_status'));

      $status = $this->retrieve('status', 'String');
      switch ($status) {
        case "001":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Message Unknown";
          break;

        case "002":
          $statusID = $actStatusIDs['Scheduled'];
          $alintoStat = $this->_messageStatus[$status] . " - Message Queued";
          break;

        case "003":
          $statusID = $actStatusIDs['Completed'];
          $alintoStat = $this->_messageStatus[$status] . " - Delivered to Gateway";
          break;

        case "004":
          $statusID = $actStatusIDs['Completed'];
          $alintoStat = $this->_messageStatus[$status] . " - Received by Recipient";
          break;

        case "005":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Error with Message";
          break;

        case "006":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - User cancelled message";
          break;

        case "007":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Error delivering message";
          break;

        case "008":
          $statusID = $actStatusIDs['Completed'];
          $alintoStat = $this->_messageStatus[$status] . " - Ok, Message Received by Gateway";
          break;

        case "009":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Routing Error";
          break;

        case "010":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Message Expired";
          break;

        case "011":
          $statusID = $actStatusIDs['Scheduled'];
          $alintoStat = $this->_messageStatus[$status] . " - Message Queued for Later";
          break;

        case "012":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Out of Credit";
          break;

        case "013":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Alinto cancelled message delivery";
          break;

        case "014":
          $statusID = $actStatusIDs['Cancelled'];
          $alintoStat = $this->_messageStatus[$status] . " - Maximum MT limit exceeded";
          break;
      }

      if ($statusID) {
        // update activity with status + msg in location
        $activity->status_id = $statusID;
        $activity->location = $alintoStat;
        $activity->activity_date_time = CRM_Utils_Date::isoToMysql($activity->activity_date_time);
        $activity->save();
        CRM_Core_Error::debug_log_message("SMS Response updated for apiMsgId={$apiMsgID}.");
        return TRUE;
      }
      else {
        $trace = "unhandled status value of '{$status}'";
      }
    }
    else {
      $trace = "could not find activity matching that Id";
    }

    // if no update is done
    CRM_Core_Error::debug_log_message("Could not update SMS Response for apiMsgId={$apiMsgID} - {$trace}");
    return FALSE;
  }

  /**
   * @return $this|null|object
   */
  function inbound() {
    $like      = "";
    $fromPhone = $this->retrieve('from', 'String');
    $fromPhone = $this->formatPhone($this->stripPhone($fromPhone), $like, "like");
    
    $body = $this->retrieve('text', 'String');
    $charset = $this->retrieve('charset', 'String');
    if ($charset != 'UTF-8') {
      if (in_array($charset, mb_list_encodings())) {
        $body = mb_convert_encoding($body, 'UTF-8', $charset);
      }
      else {
        CRM_Core_Error::debug_log_message("Alinto: mb_convert_encoding doesn't support incoming character set: '$charset'.");
      }
    }
    return parent::processInbound($fromPhone, $body, NULL, $this->retrieve('moMsgId', 'String'));
  }

  /**
   * Perform curl stuff
   *
   * @param   string  URL to call
   * @param   string  HTTP Post Data
   *
   * @return  mixed   HTTP response body or PEAR Error Object
   * @access	private
   */
  function curl($url, $postData) {
	  
    // JS 25042018 - If user uses the url "https://api.alinto.com/" in CiviCRM SMS provider Settings
    if ($this->_providerInfo['api_url'] == "https://api.alinto.com") {
      //Url for Old Credentials
      $user     = $this->_providerInfo['username'];
      $password = $this->_providerInfo['password'];
      $api_id1  = $this->_providerInfo['api_params']['api_id'];
      $params1  = 'user=' . $user . '&password=' . $password .'&api_id=' . $api_id1;
      $chUrl    = "https://api.testwpcivi.appli.in/sms/send?" . $params1 . '&' . $postData;
    } else {
      //Url for New Credentials
      // cliackatell apiKey requires '==' to be passed with the apikey!!!
      $apiKey = $this->_providerInfo['api_params']['api_id'].'==';
      // include apiKey in the params
      $params = $postData . '&apiKey=' . $apiKey;
      $chUrl = $url . '?' . $params;
    }  

    curl_setopt($this->_ch, CURLOPT_URL, $chUrl);
    curl_setopt($this->_ch, CURLOPT_SSL_VERIFYHOST, Civi::settings()->get('verifySSL') ? 2 : 0);
    curl_setopt($this->_ch, CURLOPT_SSL_VERIFYPEER, Civi::settings()->get('verifySSL'));
    // return the result on success, FALSE on failure
    curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($this->_ch, CURLOPT_TIMEOUT, 36000);

    // Send the data out over the wire
    $responseData = curl_exec($this->_ch);

    if (!$responseData) {
      $erroMessage = 'Error: "' . curl_error($this->_ch) . '" - Code: ' . curl_errno($this->_ch);
      CRM_Core_Session::setStatus(ts($erroMessage), ts('API Error'), 'error');
    }

    $result = json_decode($responseData);

    return (array)$result;
  }
}

