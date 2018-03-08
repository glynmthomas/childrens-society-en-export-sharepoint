<?php

namespace EngagingNetworksDataExport;

require_once './../vendor/autoload.php';

use \DateInterval;
use \DateTime;
use \DOMDocument;
use \DOMXPath;
use Dotenv\Dotenv;

class DataExport
{
  /**
   * Full URL to request Sharepoint security token.
   *
   * @const string
   */
  const SECURITY_REQUEST_URL = 'https://login.microsoftonline.com/extSTS.srf';
  
  /**
   * Last part of URL to request Sharepoint access token.
   *
   * @const string
   */
  const ACCESS_TOKEN_REQUEST_URL = '/_forms/default.aspx?wa=wsignin1.0';
  
  /**
   * Last part of URL to request Sharepoint digest.
   *
   * @const string
   */
  const REQUEST_DIGEST_URL = '/_api/contextinfo';
  
  /**
   * Last part of URL to upload file to Sharepoint.
   *
   * @const string
   */
  const UPLOAD_URL = "/_api/web/lists/GetByTitle('[listname]')/rootfolder/files/add(url='[filename]',overwrite=true)";
  
  /**
   * Date format for download requests.
   *
   * @const string
   */
  const DATE_FORMAT_DOWNLOADS = 'mdY';
  
  /**
   * Date format for uploading/saving files.
   *
   * @const string
   */
  const DATE_FORMAT_UPLOADS = 'Ymd';
  
  /**
   * Number of seconds to wait for download to complete.
   *
   * @const int
   */
  const DOWNLOAD_TIMEOUT = 180;
  
  /**
   * Dotenv helper to read environment variables from .env file.
   *
   * @var Dotenv
   */
  private $dotenv;

  /**
   * The start date for data we want to download.
   *
   * @var Date
   */
  private $dataFrom;

  /**
   * The end date for data we want to download.
   *
   * @var Date
   */
  private $dataTo;

  /**
   * Constructor.
   *
   * @param Date $dataFrom
   * @param Date $dataTo
   */
  public function __construct($dataFrom = null, $dataTo = null)
  {    
    $this->dataFrom = $dataFrom ? $dataFrom : $this->yesterday();
    $this->dataTo = $dataTo ? $dataTo : $this->yesterday();
    
    $dotenv = new Dotenv(__DIR__ . '/../../');
    $dotenv->load();
  }
  
  /**
   * Carry out all necessary steps to download data export file from Engaging Networks and upload to Sharepoint server.
   *
   * @return void
   */
  public function handle() {    
    $downloadedFile = $this->download();
    
    if ($downloadedFile) {
      $uploadSuccess = $this->uploadToSharepoint($downloadedFile);
    }
    
    if ($downloadedFile && isset($uploadSuccess) && $uploadSuccess) {
      mail(getenv('NOTIFICATION_EMAIL'), getenv('SUCCESS_SUBJECT'), getenv('SUCCESS_MSG') . ' ' . $this->dataFrom->format(self::DATE_FORMAT_UPLOADS) . '-' . $this->dataTo->format(self::DATE_FORMAT_UPLOADS));
      echo 'Success!';
    } else {
      echo 'Fail!';
    }
    
    echo PHP_EOL;
  }

  /**
   * Download data file from Engaging Networks.
   *
   * @return string
   */
  private function download() {
    echo 'Downloading from ' . getenv('DATA_SERVICE_URL') . ' for date(s) ' . $this->dataFrom->format(self::DATE_FORMAT_DOWNLOADS) . '-' . $this->dataTo->format(self::DATE_FORMAT_DOWNLOADS) . PHP_EOL;
    
    try {      
      $parameters = array(
        'token' => getenv('ENGAGING_NETWORKS_TOKEN'),
        'startDate' => $this->dataFrom->format(self::DATE_FORMAT_DOWNLOADS),
        'endDate' => $this->dataTo->format(self::DATE_FORMAT_DOWNLOADS),
        'type' => getenv('DOWNLOAD_FORMAT')
      );

      $data = $this->getRemoteData(getenv('DATA_SERVICE_URL') . '?' . http_build_query($parameters), false, 'gzip,deflate');
    } catch (\Exception $exception) {
      $this->log('Download error: ' . $exception->getMessage());
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_DOWNLOAD_SUBJECT'), getenv('ERROR_DOWNLOAD_MSG'));
      
      return '';
    }
    
    if (!$data) {
      return '';
    }
    
    $fileContents = (string) $data;
    
    if (strpos($fileContents, 'ERROR:') !== false || strpos($fileContents, 'Data can only be exported') !== false) {
      $this->log('Download error: The data contains an error message from Engaging Networks');
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_DOWNLOAD_SUBJECT'), getenv('ERROR_DOWNLOAD_MSG'));
    }
    
    return $fileContents;
  }
  
  /**
   * Generate filename.
   *
   * @return string
   */
  private function generateFilename() {   
    if ($this->dataFrom == $this->dataTo) {
      $filename = $this->dataFrom->format(self::DATE_FORMAT_UPLOADS);
    } else {
      $filename = $this->dataFrom->format(self::DATE_FORMAT_UPLOADS) . '-' . $this->dataTo->format(self::DATE_FORMAT_UPLOADS);
    }
    $filename = $filename . getenv('UPLOAD_FILE_EXTENSION');
    
    return $filename;
  }
  
  /**
   * Upload data file to SFTP server.
   *
   * @param string $fileContents
   * @return boolean
   */
  private function uploadToSFTP($fileContents) {   
    $filename = $this->generateFilename();
    
    echo 'Uploading ' . $filename . PHP_EOL;

    $uploadSuccess = $this->uploadFile($filename, $fileContents);
    
    if (!$uploadSuccess) {
      $this->log('Upload error: could not upload file to SFTP site.');
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
    }
    
    return $uploadSuccess;
  }
  
  /**
   * Log an error to file and display it in the terminal.
   *
   * @param string $message
   * @return void
   */
  private function log($message) {
    echo $message . PHP_EOL;
    
    $logFile = fopen(getenv('LOG_FILE_LOCATION'), 'a');
    fwrite($logFile,  date('l jS F Y h:i:s A') . ' ' . $message . PHP_EOL);
    fclose($logFile);
  }
  
  /**
   * Yesterday's date.
   *
   * @return Date
   */
  private function yesterday() {
    $date = new DateTime('UTC');
    $date->add(DateInterval::createFromDateString('yesterday'));
    
    return $date;
  }
  
  /**
   * Get a file over http using cURL.
   * See updates and explanation at: https://github.com/tazotodua/useful-php-scripts/
   *
   * @param string $url
   * @param string $postParameters
   * @param string $encoding
   * @return mixed
   */
  function getRemoteData($url, $postParameters=false, $encoding)
  {
      $c = curl_init();
    
      curl_setopt($c, CURLOPT_URL, $url);
      curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
      if($postParameters) {
          curl_setopt($c, CURLOPT_POST,TRUE);
          curl_setopt($c, CURLOPT_POSTFIELDS, $postParameters);
      }
      curl_setopt($c, CURLOPT_SSL_VERIFYHOST,false);
      curl_setopt($c, CURLOPT_SSL_VERIFYPEER,false);
      curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0");
      curl_setopt($c, CURLOPT_COOKIE, 'CookieName1=Value;');
      curl_setopt($c, CURLOPT_MAXREDIRS, 10);
      $follow_allowed= ( ini_get('open_basedir') || ini_get('safe_mode')) ? false:true;
      if ($follow_allowed)
      {
          curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
      }
      curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 9);
      curl_setopt($c, CURLOPT_REFERER, $url);
      curl_setopt($c, CURLOPT_TIMEOUT, 60);
      curl_setopt($c, CURLOPT_AUTOREFERER, true);
      curl_setopt($c, CURLOPT_ENCODING, $encoding);
    
      $data=curl_exec($c);
      $status=curl_getinfo($c);
    
      curl_close($c);
    
      preg_match('/(http(|s)):\/\/(.*?)\/(.*\/|)/si',  $status['url'],$link);
      $data=preg_replace('/(src|href|action)=(\'|\")((?!(http|https|javascript:|\/\/|\/)).*?)(\'|\")/si','$1=$2'.$link[0].'$3$4$5', $data);   $data=preg_replace('/(src|href|action)=(\'|\")((?!(http|https|javascript:|\/\/)).*?)(\'|\")/si','$1=$2'.$link[1].'://'.$link[3].'$3$4$5', $data);
    
      if($status['http_code'] == 200) {
          return $data;
      } elseif($status['http_code'] == 301 || $status['http_code'] == 302) {
          if (!$follow_allowed) {
              if (!empty($status['redirect_url'])) {
                  $redirURL=$status['redirect_url'];
              }
              else {
                  preg_match('/href\=\"(.*?)\"/si',$data,$m);
                  if (!empty($m[1])) {
                      $redirURL=$m[1];
                  }
              }
              if(!empty($redirURL)) {
                  return call_user_func( __FUNCTION__, $redirURL, $postParameters);
              }
          }
      }
    
      $this->log('Download error: ' . json_encode($status));
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_DOWNLOAD_SUBJECT'), getenv('ERROR_DOWNLOAD_MSG'));
    
      return null;
  }
  
  /**
   * Upload a file over http using cURL.
   * From https://stackoverflow.com/questions/20758431/php-curl-upload-file-to-sharepoint
   *
   * @param string $filename
   * @param string $fileContents
   * @return boolean
   */
  function uploadFile($filename, $fileContents)
  {
    $url = getenv('UPLOAD_SHAREPOINT_FOLDER_URL');
    $httpHeader = array('Content-type: multipart/form-data');
    $postFields = array(
      'file_contents' => $fileContents,
      'file_name' => $filename
    );
    $username = getenv('UPLOAD_SHAREPOINT_USERNAME');
    $password = getenv('UPLOAD_SHAREPOINT_PASSWORD');
    
    $response = $this->curlRequest(false, false, true, $url, true, $postFields, $httpHeader, $username, $password);
    
    return $result === false ? false : true;
  }
  
  /**
   * Make a request using cURL.
   *
   * @param bool $header
   * @param bool $verbose
   * @param bool $returnTransfer
   * @param string $url
   * @param bool $post
   * @param mixed $postFields
   * @param array $httpHeader
   * @param string $username
   * @param string $password
   * @return boolean
   */
  function curlRequest($header, $verbose, $returnTransfer, $url, $post, $postFields, $httpHeader = null, $username = null, $password = null)
  {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Linux i686; rv:6.0) Gecko/20100101 Firefox/6.0Mozilla/4.0 (compatible;)");
    curl_setopt($ch, CURLOPT_HEADER, $header);
    curl_setopt($ch, CURLOPT_VERBOSE, $verbose);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, $returnTransfer);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, $post);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    
    if (isset($httpHeader)) {
      curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeader);
    }
    if ($username && $password) {
      curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    }

    $response = curl_exec($ch);
    $error = curl_errno($ch);
      
    curl_close($ch);

    if($error) {
      return false;
    } else {
      return $response;
    }
  }
  
  /**
   * Get node from XML
   * 
   * @param string $xmlString
   * @param string $query
   * @return string
   */
  function getXMLNode($xmlString, $query) {
    $xml = new DOMDocument();
    $xml->loadXML($xmlString);
    $xpath = new DOMXPath($xml);
    $nodelist = $xpath->query($query);
    foreach ($nodelist as $n){
      return $n->nodeValue;
      break;
    }
  }
  
  /**
   * Get the Sharepoint FedAuth and rtFa cookies
   * 
   * @param string $token
   * @return array
   * @throws Exception
   */
  function getSharepointAuthCookies($token) {
    $url = getenv('UPLOAD_SHAREPOINT_BASE_URL') . self::ACCESS_TOKEN_REQUEST_URL;
    
    $result = $this->curlRequest(true, false, true, $url, true, $token);

    return $result === false ? false : $this->getCookieValue($result);
  }

  /**
   * Get the security token needed
   * 
   * @return mixed
   */
  function getSharepointSecurityToken() {
    $tokenXml = $this->getSharepointSecurityTokenXml(
      getenv('UPLOAD_SHAREPOINT_USERNAME'),
      getenv('UPLOAD_SHAREPOINT_PASSWORD'),
      getenv('UPLOAD_SHAREPOINT_BASE_URL')
    );
    
    $result = $this->curlRequest(false, false, true, self::SECURITY_REQUEST_URL, true, $tokenXml);
    
    return $result === false ? false : $this->getXMLNode($result, '//wsse:BinarySecurityToken');
  }

  /**
   * Get the XML to request the security token
   * 
   * @return type string
   */
  function getSharepointSecurityTokenXml() {
    $file = fopen('securityTokenRequest.xml', 'r') or die('Unable to open Security Token Request file!');
    $xml = fread($file, filesize('securityTokenRequest.xml'));
    fclose($file);
    
    $xml = str_replace('[username]', getenv('UPLOAD_SHAREPOINT_USERNAME'), $xml);
    $xml = str_replace('[password]', getenv('UPLOAD_SHAREPOINT_PASSWORD'), $xml);
    $xml = str_replace('[url]', getenv('UPLOAD_SHAREPOINT_BASE_URL'), $xml);
    
    return $xml;
  }

  /**
   * Get the cookie value from the http header
   *
   * @param string $header
   * @return mixed 
   */
  function getCookieValue($header)
  {
    $authCookies = array();
    $header_array = explode("\r\n",$header);
    foreach($header_array as $header) {
      $loop = explode(":",$header);
      if($loop[0] == 'Set-Cookie') {
        array_push($authCookies, ltrim($loop[1]));
      }
    }
    unset($authCookies[2]);

    return array_values($authCookies);
  }
  
  /**
   * Get the Sharepoint request digest
   *
   * @param array $authCookies
   * @return mixed 
   */
  function getSharepointRequestDigest($authCookies)
  {
    $url = getenv('UPLOAD_SHAREPOINT_BASE_URL') . getenv('UPLOAD_SHAREPOINT_SITE_URL') . self::REQUEST_DIGEST_URL;
    $httpHeader = array(
      'Content-type: multipart/form-data',
      'Cookie: ' . $authCookies[0] . ';' . $authCookies[1]
    );
    
    $result = $this->curlRequest(false, false, true, $url, true, null, $httpHeader);

    return $result === false ? false : $this->getXMLNode($result, '//d:FormDigestValue');
  }
  
  /**
   * Upload file to Sharepoint
   *
   * @param string $fileContent
   * @return boolean 
   */
  function uploadToSharepoint($fileContent)
  {
    $token = $this->getSharepointSecurityToken();
    if ($token === false) {
      $this->log('Upload error: could not get Sharepoint security token.');
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
      
      return false;
    }
    
    $authCookies = $this->getSharepointAuthCookies($token);
    if ($authCookies === false) {
      $this->log('Upload error: could not get Sharepoint authentication cookies.');
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
      
      return false;
    }
    
    $requestDigest = $this->getSharepointRequestDigest($authCookies);
    if ($requestDigest === false) {
      $this->log('Upload error: could not get Sharepoint request digest.');
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
      
      return false;
    }
    
    $filename = $this->generateFilename();
    
    $url = getenv('UPLOAD_SHAREPOINT_BASE_URL') . getenv('UPLOAD_SHAREPOINT_SITE_URL') . self::UPLOAD_URL;
    $url = str_replace('[listname]', getenv('UPLOAD_SHAREPOINT_LIST'), $url);
    $url = str_replace('[filename]', $filename, $url);
    
    $httpHeader = array(
      'Content-Type: application/x-www-form-urlencoded',
      'Cookie: ' . $authCookies[0] . ';' . $authCookies[1],
      'accept: application/json;odata=verbose',
      'X-RequestDigest: ' . $requestDigest
    );
    
    echo 'Uploading ' . $filename . PHP_EOL;
    
    $result = $this->curlRequest(false, false, true, $url, true, $fileContent, $httpHeader);
    
    if ($result === false) {
      $this->log('Upload error: could not upload file to Sharepoint.');
      mail(getenv('NOTIFICATION_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
      
      return false;
    }
    
    return true;
  }
}
