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
  const UPLOAD_URL = "/_api/web/getfolderbyserverrelativeurl('/Shared%20Documents/FundM/SUPIN/Engaging%20Networks%20Data')/Files/Add(url='[filename]',overwrite=true)";
//   const UPLOAD_URL = "/_api/Web/GetFolderByServerRelativeUrl('/FundM/SUPIN/Engaging%20Networks%20Data')/Files";
  
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
    $username = getenv('UPLOAD_SHAREPOINT_USERNAME');
    $password = getenv('UPLOAD_SHAREPOINT_PASSWORD');
    $host = 'https://thechildrenssociety.sharepoint.com';

    $token = $this->getSecurityToken($username, $password, $host);
    $authCookies = $this->getAuthCookies($token, $host);
    $requestDigest = $this->getRequestDigest($authCookies);
    $this->uploadToSharepoint($authCookies, $requestDigest, 'testfile.csv', 'blah some content');
//     var_dump($token, $authCookies, $requestDigest);
    exit;
    
    $downloadedFile = $this->download();
    
    if ($downloadedFile) {
      $uploadSuccess = $this->upload($downloadedFile);
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
   * Upload data file to SFTP server.
   *
   * @param string $fileContents
   * @return boolean
   */
  private function upload($fileContents) {   
    if ($this->dataFrom == $this->dataTo) {
      $filename = $this->dataFrom->format(self::DATE_FORMAT_UPLOADS);
    } else {
      $filename = $this->dataFrom->format(self::DATE_FORMAT_UPLOADS) . '-' . $this->dataTo->format(self::DATE_FORMAT_UPLOADS);
    }
    $filename = $filename . getenv('UPLOAD_FILE_EXTENSION');
    
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
    $username = getenv('UPLOAD_SHAREPOINT_USERNAME');
    $password = getenv('UPLOAD_SHAREPOINT_PASSWORD');
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-type: multipart/form-data"));
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Linux i686; rv:6.0) Gecko/20100101 Firefox/6.0Mozilla/4.0 (compatible;)");
    curl_setopt($ch, CURLOPT_URL, getenv('UPLOAD_SHAREPOINT_FOLDER_URL'));

    curl_setopt($ch, CURLOPT_POST, true);

    $post = array(
      'file_contents' => $fileContents,
      'file_name' => $filename
    );

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    $response = curl_exec($ch);

    if(curl_errno($ch)) {
      echo json_encode('Error: ' . curl_error($ch));
      return false;
    }
    else {
      echo json_encode($response);
      return true;
    }
  }
  
  /**
   * Get the FedAuth and rtFa cookies
   * 
   * @param string $token
   * @param string $host
   * @return array
   * @throws Exception
   */
  function getAuthCookies($token, $host) {
    $url = $host . self::ACCESS_TOKEN_REQUEST_URL;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $token);   
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); 

    $result = curl_exec($ch);

    // catch error
    if($result === false) {
      throw new Exception('Curl error: ' . curl_error($ch));
    }

    //close connection
    curl_close($ch);      

    return $this->getCookieValue($result);
  }

  /**
   * Get the security token needed
   * 
   * @param string $username
   * @param string $password
   * @param string $endpoint
   * @return string
   * @throws Exception
   */
  function getSecurityToken($username, $password, $endpoint) {
    $tokenXml = $this->getSecurityTokenXml($username, $password, $endpoint);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, self::SECURITY_REQUEST_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenXml);   
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);

    // catch error
    if($result === false) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }

    //close connection
    curl_close($ch);

    // Parse security token from response
    $xml = new DOMDocument();
    $xml->loadXML($result);
    $xpath = new DOMXPath($xml);
    $nodelist = $xpath->query("//wsse:BinarySecurityToken");
    foreach ($nodelist as $n){
      return $n->nodeValue;
      break;
    }
  }

  /**
   * Get the XML to request the security token
   * 
   * @param string $username
   * @param string $password
   * @param string $endpoint
   * @return type string
   */
  function getSecurityTokenXml($username, $password, $endpoint) {
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
   * @return array 
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
   * @return array 
   */
  function getRequestDigest($authCookies)
  {
    $url = getenv('UPLOAD_SHAREPOINT_BASE_URL') . self::REQUEST_DIGEST_URL;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0");
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, null);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-type: multipart/form-data',
      'Cookie: ' . $authCookies[0] . ';' . $authCookies[1]
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);

    // Catch error
    if($result === false) {
      throw new Exception('Curl error: ' . curl_error($ch));
    }

    // Close connection
    curl_close($ch);

    // Parse security token from response
    $xml = new DOMDocument();
    $xml->loadXML($result);
    $xpath = new DOMXPath($xml);
    $nodelist = $xpath->query("//d:FormDigestValue");
    foreach ($nodelist as $n){
      return $n->nodeValue;
      break;
    }
  }
  
  /**
   * Upload file to Sharepoint
   *
   * @param array $authCookies
   * @param string $requestDigest
   * @param string $filename
   * @param string $fileContent
   * @return boolean 
   */
  function uploadToSharepoint($authCookies, $requestDigest, $filename, $fileContent)
  {
    $url = getenv('UPLOAD_SHAREPOINT_BASE_URL') . self::UPLOAD_URL;
    $url = str_replace('[filename]', $filename, $url);
    var_dump($url);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0");
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: multipart/form-data',
      'Cookie: ' . $authCookies[0] . ';' . $authCookies[1],
      'accept: application/json;odata=verbose',
      'X-RequestDigest: ' . $requestDigest
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    
    curl_close($ch);
    
    var_dump($result);

    // Catch error
    if($result === false) {
      return false;
    }

    return true;
  }
}
