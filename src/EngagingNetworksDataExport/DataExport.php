<?php

namespace EngagingNetworksDataExport;

require_once 'vendor/autoload.php';

use \DateInterval;
use \DateTime;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use phpseclib\Net\SFTP;

class DataExport
{
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
   * Guzzle HTTP client.
   *
   * @var Client
   */
  private $client;
  
  /**
   * PHPSecLib SFTP client.
   *
   * @var SFTP
   */
  private $sftp;

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
    
    $client = new Client();
    $this->client = $client;
    
    $sftp = new SFTP(getenv('UPLOAD_SFTP_SERVER'));
    $this->sftp = $sftp;
  }
  
  /**
   * Carry out all necessary steps to download data export file from Engaging Networks and upload to SFTP server.
   *
   * @return void
   */
  public function handle() {
    $downloadedFile = $this->download();
    
    if ($downloadedFile) {
      $uploadSuccess = $this->upload($downloadedFile);
    }
    
    echo $downloadedFile && isset($uploadSuccess) && $uploadSuccess ? 'Success!' : 'Fail!';
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
      $response = $this->client->request('GET', getenv('DATA_SERVICE_URL'), [
        'http_errors' => false,
        'timeout' => self::DOWNLOAD_TIMEOUT,
        'query' => [
          'token' => getenv('ENGAGING_NETWORKS_TOKEN'),
          'startDate' => $this->dataFrom->format(self::DATE_FORMAT_DOWNLOADS),
          'endDate' => $this->dataTo->format(self::DATE_FORMAT_DOWNLOADS),
          'type' => getenv('DOWNLOAD_FORMAT'),
        ]
      ]);
    } catch (\Exception $exception) {
      $this->log('Download error: ' . $exception->getMessage());
      mail(getenv('ERROR_EMAIL'), getenv('ERROR_DOWNLOAD_SUBJECT'), getenv('ERROR_DOWNLOAD_MSG'));
      
      return '';
    }
    
    if ($response->getStatusCode() !== 200) {
      $this->log('Download error: ' . $response->getReasonPhrase());
      mail(getenv('ERROR_EMAIL'), getenv('ERROR_DOWNLOAD_SUBJECT'), getenv('ERROR_DOWNLOAD_MSG'));
      
      return '';
    }
    
    $fileContents = (string) $response->getBody();
    
    if (strpos($fileContents, 'ERROR:') !== false || strpos($fileContents, 'Data can only be exported') !== false) {
      $this->log('Download error: The data contains an error message from Engaging Networks');
      mail(getenv('ERROR_EMAIL'), getenv('ERROR_DOWNLOAD_SUBJECT'), getenv('ERROR_DOWNLOAD_MSG'));
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
    if (!$this->sftp->login(getenv('UPLOAD_SFTP_USERNAME'), getenv('UPLOAD_SFTP_PASSWORD'))) {
      $this->log('Upload error: could not log in to SFTP site.');
      mail(getenv('ERROR_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
      
      return false;
    }
    
    if ($this->dataFrom == $this->dataTo) {
      $filename = $this->dataFrom->format(self::DATE_FORMAT_UPLOADS);
    } else {
      $filename = $this->dataFrom->format(self::DATE_FORMAT_UPLOADS) . '-' . $this->dataTo->format(self::DATE_FORMAT_UPLOADS);
    }
    $filename = $filename . getenv('UPLOAD_FILE_EXTENSION');
    
    echo 'Uploading ' . $filename . PHP_EOL;

    $uploadSuccess = $this->sftp->put($filename, $fileContents);
    
    if (!$uploadSuccess) {
      $this->log('Upload error: could not upload file to SFTP site.');
      mail(getenv('ERROR_EMAIL'), getenv('ERROR_UPLOAD_SUBJECT'), getenv('ERROR_UPLOAD_MSG'));
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
    $date = new DateTime();
    $date->add(DateInterval::createFromDateString('yesterday'));
    
    return $date;
  }
}
