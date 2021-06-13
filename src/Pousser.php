<?php

namespace Pousser;

use Psr\Log\LogLevel;

class Pousser
{

  /**
   * @var string Version
   */
  public static $VERSION = '0.2';

  private $log;
  /**
   * @var array Settings
   */
  private $settings = array(
    'environment' => 'production',
    'scheme' => 'https',
    'port' => 443,
    'timeout' => 30,
    'debug' => false,
    'curl_options' => array(),
    'host' => 'api.pousser.io',
    'base_path' => 'api',
  );
  /**
   * @var null|resource
   */
  private $curl = null; // Curl handler

  /**
   * Initializes a new Pousser instance with key, secret, app_id.
   *
   * @param string $key
   * @param string $secret
   * @param int $app_id
   * @param array $options [optional]
   *                         Options to configure the Pousser instance.
   *                         environment - e.g. production or qa (production is default)
   *                         scheme - e.g. http or https
   *                         host - the host e.g. api.pousser.com. No trailing forward slash.
   *                         port - the http port
   *                         timeout - the http timeout
   *                         useTLS - quick option to use scheme of https and port 443.
   *                         encryption_master_key - a 32 char long key. This key, along with the channel name, are used to derive per-channel encryption keys. Per-channel keys are used encrypt event data on encrypted channels.
   *                         debug - (default `false`) if `true`, every `trigger()` and `triggerBatch()` call will return a `$response` object, useful for logging/inspection purposes.
   *                         curl_options - wrapper for curl_setopt, more here: http://php.net/manual/en/function.curl-setopt.php
   *                         notification_host - host to connect to for native notifications.
   *                         notification_scheme - scheme for the notification_host.
   *
   * @throws PousserException Throws exception if any required dependencies are missing
   */
  public function __construct($key, $secret, $app_id, $options = array())
  {
    $this->check_compatibility();

    /*$this->log->log('Legacy $host parameter provided: {scheme} host: {host}', array(
        'scheme' => $this->settings['scheme'],
        'host' => $this->settings['host'],
    ));*/

    $useTLS = false;
    if (isset($options['useTLS'])) {
      $useTLS = $options['useTLS'] === true;
    }
    if (
      $useTLS &&
      !isset($options['scheme']) &&
      !isset($options['port'])
    ) {
      $options['scheme'] = 'https';
      $options['port'] = 443;
    }
    $this->settings['key'] = $key;
    $this->settings['secret'] = $secret;
    $this->settings['app_id'] = $app_id;

    foreach ($options as $k => $v) {
      if (isset($this->settings[$k])) {
        $this->settings[$k] = $v;
      }
    }

    if (!array_key_exists('host', $this->settings)) {
      //throw
    }

    $this->settings['host'] = preg_replace('/http[s]?\:\/\//', '', $this->settings['host'], 1);

    $this->log = new Log();

  }

  /**
   * Fetch the settings.
   *
   * @return array
   */
  public function getSettings()
  {
    return $this->settings;
  }

  /**
   * Set a logger to be informed of internal log messages.
   *
   * @param object $logger A object
   *
   * @return void
   */
  public function setLogger($logger)
  {
    $this->log->setLogger($logger);
  }

  /**
   * Check if the current PHP setup is sufficient to run this class.
   *
   * @return void
   * @throws PousserException If any required dependencies are missing
   *
   */
  private function check_compatibility()
  {
    if (!extension_loaded('curl')) {
      throw new PousserException('The Pousser library requires the PHP cURL module. Please ensure it is installed');
    }
    if (!extension_loaded('json')) {
      throw new PousserException('The Pousser library requires the PHP JSON module. Please ensure it is installed');
    }
  }

  /**
   * Validate number of channels and channel name format.
   *
   * @param string[] $channels An array of channel names to validate
   *
   * @return void
   * @throws PousserException If $channels is too big or any channel is invalid
   *
   */
  private function validate_channels($channels)
  {
    if (count($channels) > 100) {
      throw new PousserException('An event can be triggered on a maximum of 100 channels in a single call.');
    }
    foreach ($channels as $channel) {
      $this->validate_channel($channel);
    }
  }

  /**
   * Ensure a channel name is valid based on our spec.
   *
   * @param string $channel The channel name to validate
   *
   * @return void
   * @throws PousserException If $channel is invalid
   *
   */
  private function validate_channel($channel)
  {
    if (!preg_match('/\A[-a-zA-Z0-9_=@,.;]+\z/', $channel)) {
      throw new PousserException('Invalid channel name ' . $channel);
    }
  }

  /**
   * Utility function used to create the curl object with common settings.
   *
   * @param string $domain
   * @param string $s_url
   * @param string [optional] $request_method
   * @param array [optional]  $query_params
   *
   * @return resource
   * @throws PousserException Throws exception if curl wasn't initialized correctly
   *
   */
  private function create_curl($domain, $s_url, $request_method = 'GET', $query_params = array())
  {
    $query = self::build_query_string($query_params);
    $full_url = $domain . $s_url . '?' . $query;
    $this->log->log('create_curl( {{full_url}} )', array('full_url' => $full_url));

    if (!is_resource($this->curl)) {
      $this->curl = curl_init();
    }
    if ($this->curl === false) {
      throw new PousserException('Could not initialise cURL!');
    }
    $curl = $this->curl;

    if (function_exists('curl_reset')) {
      curl_reset($curl);
    }

    curl_setopt($curl, CURLOPT_URL, $full_url);

    $header_sign = self::build_auth_header($request_method, $s_url, $query);

    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
      'key: ' . $this->settings['key'],
      'secret: ' . $this->settings['secret'],
      'hash: ' . $header_sign,
      'Content-Type: application/json',
      'X-Pousser-Library: pousser-php ' . self::$VERSION,
    ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, $this->settings['timeout']);
    if ($request_method === 'POST') {
      curl_setopt($curl, CURLOPT_POST, 1);
    } elseif ($request_method === 'GET') {
      curl_setopt($curl, CURLOPT_POST, 0);
    }

    if (!empty($this->settings['curl_options'])) {
      foreach ($this->settings['curl_options'] as $option => $value) {
        curl_setopt($curl, $option, $value);
      }
    }
    return $curl;
  }

  /**
   * Utility function to execute curl and create capture response information.
   *
   * @param $curl resource
   *
   * @return array
   */
  private function exec_curl($curl)
  {
    $response = array();
    $response['body'] = curl_exec($curl);
    $response['status'] = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response['body'] === false) {
      $this->log->log('exec_curl error: {error}', array('error' => curl_error($curl)), LogLevel::ERROR);
    } elseif ($response['status'] < 200 || 400 <= $response['status']) {
      $this->log->log('exec_curl {{status}} error from server: {{body}}', $response, LogLevel::ERROR);
    } else {
      $this->log->log('exec_curl {{status}} response: {{body}}', $response);
    }
    $this->log->log('exec_curl response: {{response}}', array('response' => print_r($response, true)));
    return $response;
  }

  /**
   * Build the Channels domain.
   *
   * @return string
   */
  private function channels_domain()
  {
    return $this->settings['scheme'] . '://' . $this->settings['host'] . ':' . $this->settings['port'] . '/';
  }

  /**
   * Build the params.
   *
   * @param array $query_params [optional]
   *
   * @return string
   */
  public static function build_query_string($query_params = array())
  {
    $params = array();
    $params['auth_timestamp'] = time();

    $params = array_merge($params, $query_params);
    ksort($params);

    $auth_query_string = self::array_implode('=', '&', $params);
    return $auth_query_string;
  }

  /**
   * Build the required HMAC'd auth string.
   *
   * @param string $secret
   * @param string $request_method
   * @param string $request_path
   * @param array $query_params [optional]
   *
   * @return string
   */
  public static function build_auth_header($secret, $request_method, $request_path,
                                           $query_params = array())
  {
    $params = array();
    $params = array_merge($params, $query_params);
    ksort($params);

    $string_to_sign = $request_method . $request_path . self::array_implode('=', '&', $params);
    $auth_signature = hash_hmac('sha256', $string_to_sign, $secret, false);

    return $auth_signature;
  }

  /**
   * Implode an array with the key and value pair giving
   * a glue, a separator between pairs and the array
   * to implode.
   *
   * @param string $glue The glue between key and value
   * @param string $separator Separator between pairs
   * @param array|string $array The array to implode
   *
   * @return string The imploded array
   */
  public static function array_implode($glue, $separator, $array)
  {
    if (!is_array($array)) {
      return $array;
    }
    $string = array();
    foreach ($array as $key => $val) {
      if (is_array($val)) {
        $val = implode(',', $val);
      }
      $string[] = "{$key}{$glue}{$val}";
    }
    return implode($separator, $string);
  }

  /**
   * Trigger an event by providing event name and payload.
   * Optionally provide a socket ID to exclude a client (most likely the sender).
   *
   * @param array|string $channels A channel name or an array of channel names to publish the event on.
   * @param string $event
   * @param mixed $data Event data
   * @param bool $debug [optional]
   * @param bool $already_encoded [optional]
   *
   * @return bool|array
   * @throws PousserException Throws exception if $channels is an array of size 101 or above or $socket_id is invalid
   *
   */
  public function trigger($channels, $event, $data, $debug = false, $already_encoded = false)
  {
    if (is_string($channels) === true) {
      $channels = array($channels);
    }
    $this->validate_channels($channels);

    $query_params = array();

    $data_encoded = $already_encoded ? $data : json_encode($data);

    if ($this->settings['environment'] == 'production') {
      $s_url = $this->settings['base_path'] . sprintf('/app/%s/publish', $this->settings['app_id']);
    } else {
      $s_url = $this->settings['base_path'] . sprintf('/app/%s/environment/%s/publish', $this->settings['app_id'], $this->settings['environment']);
    }

    if (!$data_encoded) {
      $this->log->log('Failed to perform json_encode on the the provided data: {{error}}', array(
        'error' => print_r($data, true),
      ), LogLevel::ERROR);
    }
    $post_params = array();
    $post_params['eventName'] = $event;
    $post_params['data'] = $data_encoded;
    $post_params['channels'] = array_values($channels);

    $post_value = json_encode($post_params);
    $query_params['body_md5'] = md5($post_value);
    $curl = $this->create_curl($this->channels_domain(), $s_url, 'POST', $query_params);
    $this->log->log('trigger POST: {{post_value}}', compact('post_value'));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_value);
    $response = $this->exec_curl($curl);
    if ($debug === true || $this->settings['debug'] === true) {
      return $response;
    }
    if ($response['status'] === 200) {
      return true;
    }
    return false;
  }

  /**
   * Trigger multiple events at the same time.
   *
   * @param array $batch [optional] An array of events to send
   * @param bool $debug [optional]
   * @param bool $already_encoded [optional]
   *
   * @return array|bool|string
   * @throws PousserException Throws exception if curl wasn't initialized correctly
   *
   */
  public function triggerBatch($batch = array(), $debug = false, $already_encoded = false)
  {
    foreach ($batch as $key => $event) {
      $this->validate_channel($event['channel']);
      if (isset($event['socket_id'])) {
        $this->validate_socket_id($event['socket_id']);
      }
      $data = $event['data'];
      if (!is_string($data)) {
        $data = $already_encoded ? $data : json_encode($data);
      }
      $batch[$key]['data'] = $data;

    }
    $post_params = array();
    $post_params['batch'] = $batch;
    $post_value = json_encode($post_params);
    $query_params = array();
    $query_params['body_md5'] = md5($post_value);
    $s_url = $this->settings['base_path'] . '/batch_events';
    $curl = $this->create_curl($this->channels_domain(), $s_url, 'POST', $query_params);
    $this->log->log('trigger POST: {{post_value}}', compact('post_value'));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_value);
    $response = $this->exec_curl($curl);
    if ($debug === true || $this->settings['debug'] === true) {
      return $response;
    }
    if ($response['status'] === 200) {
      return true;
    }
    return false;
  }

  /**
   * Fetch channel information for a specific channel.
   *
   * @param string $channel The name of the channel
   * @param array $params Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
   *
   * @return bool|object
   * @throws PousserException If $channel is invalid or if curl wasn't initialized correctly
   *
   */
  public function get_channel_info($channel, $params = array())
  {
    $this->validate_channel($channel);
    $response = $this->get('/channels/' . $channel, $params);
    if ($response['status'] === 200) {
      return json_decode($response['body']);
    }
    return false;
  }

  /**
   * Fetch a list containing all channels.
   *
   * @param array $params Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
   *
   * @return array|bool
   * @throws PousserException Throws exception if curl wasn't initialized correctly
   *
   */
  public function get_channels($params = array())
  {
    $response = $this->get('/channels', $params);
    if ($response['status'] === 200) {
      $response = json_decode($response['body']);
      $response->channels = get_object_vars($response->channels);
      return $response;
    }
    return false;
  }

  /**
   * Fetch user ids currently subscribed to a presence channel.
   *
   * @param string $channel The name of the channel
   *
   * @return array|bool
   * @throws PousserException Throws exception if curl wasn't initialized correctly
   *
   */
  public function get_users_info($channel)
  {
    $response = $this->get('/channels/' . $channel . '/users');
    if ($response['status'] === 200) {
      return json_decode($response['body']);
    }
    return false;
  }

  /**
   * GET arbitrary REST API resource using a synchronous http client.
   * All request signing is handled automatically.
   *
   * @param string $path Path excluding /apps/APP_ID
   * @param array $params API params
   *
   * @return array|bool
   * @throws PousserException Throws exception if curl wasn't initialized correctly
   *
   */
  public function get($path, $params = array())
  {
    $s_url = $this->settings['base_path'] . $path;
    $curl = $this->create_curl($this->channels_domain(), $s_url, 'GET', $params);
    $response = $this->exec_curl($curl);
    if ($response['status'] === 200) {
      $response['result'] = json_decode($response['body'], true);
      return $response;
    }
    return false;
  }

}
