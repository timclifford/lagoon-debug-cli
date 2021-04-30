<?php

namespace App\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Class OpenshiftApi
 *
 * @package App\Api
 */
class OpenshiftApi {

  protected static ?OpenshiftApi $instance = NULL;
  protected string $name;
  protected string $consoleUrl;
  protected string $token;
  protected ?Client $client = NULL;

  public static function getInstance(string $name = '', string $consoleUrl = '', string $token = '') {
    if (self::$instance === NULL) {
      self::$instance = new self($name, $consoleUrl, $token);
    }
    else if (self::$instance->getName() !== $name) {
      self::$instance = new self($name, $consoleUrl, $token);
    }
    return self::$instance;
  }

  protected function __construct(string $name, string $consoleUrl, string $token) {
    $this->name = $name;
    $this->consoleUrl = $consoleUrl;
    $this->token = $token;
    $this->client = new Client([
      'timeout' => 10,
      'connect_timeout' => 5,
      'verify' => FALSE,
      'base_uri' => $consoleUrl,
      'headers' => [
        'User-Agent' => 'govcms-debug/1.0',
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Authorization' => "Bearer {$token}",
      ],
    ]);
  }

  /**
   * @return string
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * Perform an HTTP GET request.
   *
   * @param string $endpoint
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function apiRequest(string $endpoint):array {
    try {
      $response = $this->client->get($endpoint);
    }
    catch (RequestException $e) {
      $uri = $e->getRequest()->getUri();
      $json = json_encode(json_decode($e->getResponse()->getBody()->getContents(), TRUE), JSON_PRETTY_PRINT);
      throw new \Exception("URI: {$uri}\nMESSAGE: {$json}");
    }

    $json = json_decode((string) $response->getBody(), TRUE);
    return $json['items'] ?? $json;
  }

  /**
   * Who am I in Openshift.
   *
   * @return string
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function whoAmI():string {
    $json = $this->apiRequest("/oapi/v1/users/~");
    return $json['metadata']['name'];
  }

  /**
   * Get all namespaces that match a label selector.
   *
   * @param string $labelSelector
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getAllNamespaces(string $labelSelector = '') {
    if ($labelSelector) {
      $labelSelector = urlencode($labelSelector);
      return $this->apiRequest("/api/v1/namespaces?labelSelector={$labelSelector}");
    }

    return $this->apiRequest("/api/v1/namespaces");
  }

  /**
   * Get all routes for a namespace.
   *
   * @param string $namespace
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getAllRoutesForNamespace(string $namespace) {
    return $this->apiRequest("/apis/route.openshift.io/v1/namespaces/{$namespace}/routes");
  }

  /**
   * Get all HPAs for a namespace.
   *
   * @param string $namespace
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getAllHpasForNamespace(string $namespace) {
    return $this->apiRequest("/apis/autoscaling/v1/namespaces/{$namespace}/horizontalpodautoscalers");
  }

  /**
   * Get all recent builds for a namespace.
   *
   * @param string $namespace
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getAllRecentBuildsForNamespace(string $namespace) {
    return $this->apiRequest("/apis/build.openshift.io/v1/namespaces/{$namespace}/builds");
  }

}
