<?php

namespace App\Domain\Plugins;

use App\Commands\ScrapeOpenshiftCommand;
use Carbon\CarbonInterval;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

Abstract class BasePlugin {

  protected string $domain = '';
  protected ?Client $httpClient = NULL;
  protected ?ResponseInterface $response = NULL;
  protected array $metatags = [];
  protected ?array $ipv4Records = NULL;
  protected ?array $ipv6Records = NULL;
  protected string $registrableDomain = '';
  protected array $cache = [];

  /**
   * BasePlugin constructor.
   *
   * @param string $domain
   * @param \GuzzleHttp\Client $httpClient
   */
  public function __construct(string $domain, Client $httpClient) {
    $this->domain = $domain;
    $this->httpClient = $httpClient;
  }

  /**
   * @param \Psr\Http\Message\ResponseInterface $response
   */
  public function setResponse(ResponseInterface $response): void {
    $this->response = $response;
  }

  /**
   * @param array $metatags
   */
  public function setMetatags(array $metatags): void {
    $this->metatags = $metatags;
  }

  /**
   * @param string $registrableDomain
   */
  public function setRegistrableDomain(string $registrableDomain): void {
    $this->registrableDomain = $registrableDomain;
  }

  /**
   * @param array $ipv4Records
   */
  public function setIpv4Records(array $ipv4Records): void {
    $this->ipv4Records = $ipv4Records;
  }

  /**
   * @param array $ipv6Records
   */
  public function setIpv6Records(array $ipv6Records): void {
    $this->ipv6Records = $ipv6Records;
  }

  /**
   * @param string $gitlabProjectName
   */
  public function setGitlabProjectName(string $gitlabProjectName): void {
    $this->gitlabProjectName = $gitlabProjectName;
  }

  /**
   * Get the machine name for the filter.
   *
   * @return string
   */
  abstract public function getMachineName();

  /**
   * Analyse the data.
   *
   * @return mixed
   */
  abstract public function analyseData();

  /**
   * Is the backend Lagoon?
   *
   * @return bool
   */
  protected function isLagoon(): bool {
    return !empty($this->response->getHeaderLine('X-Lagoon'));
  }

  /**
   * Attempt to find the Lagoon project name from the HTTP headers. Only works
   * on Openshift at present.
   *
   * e.g. `lb6827.govcms1.amazee.io>casa-master:www.casa.gov.au`.
   * e.g. `lb1400.bi.amazee.io>varnish-20-4cq2t-master-nbi-portal>nginx-32-dtwnq`.
   *
   * @return string
   */
  protected function getNamespaceFromHeader(): string {
    $lagoon = $this->response->getHeaderLine('X-Lagoon');
    if ($lagoon) {
      // Attempt to match the complex option first.
      if (preg_match('#>(?:nginx|varnish)-\d+-(?:[a-z0-9]+)-([a-z]+)-([a-zA-Z0-9-_]+)>#', $lagoon, $matches)) {
        return "{$matches[2]}-{$matches[1]}";
      }
      // Basic option.
      if (preg_match('#>([a-zA-Z0-9-_]+):#', $lagoon, $matches)) {
        return $matches[1];
      }
    }

    return '';
  }

  /**
   * Find the name of the project, without the environment, given the
   * openshift namespace.
   *
   * e.g. `casa` from `casa-master`.
   */
  protected static function workOutProjectNameFromNamespace($namespace) {
    preg_match('#(.+)-[a-zA-Z0-9_]+$#', $namespace, $matches);
    if (isset($matches[1])) {
      return $matches[1];
    }
    return '';
  }

  /**
   * Find the name of the Gitlab repo, if this project is hosted on GovCMS.
   *
   * e.g. git@projects.govcms.gov.au:SAV/lms.git
   *
   * @return ?string
   */
  protected function getGovcmsGitlabProject(): ?string {
    $projects = ScrapeOpenshiftCommand::getProjects();
    if ($this->getNamespaceFromHeader()) {
      $openshiftProject = $projects[$this->getNamespaceFromHeader()] ?? [];
      if (isset($openshiftProject['gitUrl'])) {
        if (preg_match('#projects\.govcms\.gov\.au:.+/(.+)\.git$#', $openshiftProject['gitUrl'], $matches)) {
          return $matches[1];
        }
      }
    }
    return NULL;
  }

  /**
   * Human readable seconds.
   *
   * @param int $seconds
   *
   * @return string
   * @throws \Exception
   */
  public static function secondsToHuman(int $seconds):string {
    return CarbonInterval::seconds($seconds)->cascade()->forHumans([
      'parts' => 2,
      'join' => TRUE,
    ]);
  }

  /**
   * Check if a given ip is in a network.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1
   * @param string $range
   *   IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32
   *   assumed.
   *
   * @see https://gist.github.com/josuecau/4fab416f5cfc5f2b7d5d
   *
   * @return boolean
   *   TRUE if the ip is in this range / FALSE if not.
   */
  public function isIpInRange(string $ip, string $range) {
    if (strpos($range, '/') == false) {
      $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24.
    [$range, $netmask] = explode('/', $range, 2);
    $range_decimal = ip2long($range);
    $ip_decimal = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal = ~ $wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
  }

  /**
   * Check if a given IPV4 belongs to Cloudflare.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1.
   *
   * @return boolean
   *   TRUE if the ip belongs to Cloudflare / FALSE if not.
   */
  public function isCloudflare($ip) {
    if (!isset($this->cache["cloudflare_{$ip}"])) {
      // @see https://www.cloudflare.com/ips-v4/
      $cloudflareIps = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/12',
        '172.64.0.0/13',
        '131.0.72.0/22',
      ];
      $isCloudflareIp = FALSE;
      foreach ($cloudflareIps as $cloudflareIp) {
        if ($this->isIpInRange($ip, $cloudflareIp)) {
          $isCloudflareIp = TRUE;
          break;
        }
      }
      $this->cache["cloudflare_{$ip}"] = $isCloudflareIp;
    }

    return $this->cache["cloudflare_{$ip}"];
  }

  /**
   * Check if a given IPV4 belongs to Fastly.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1.
   *
   * @return boolean
   *   TRUE if the ip belongs to Fastly / FALSE if not.
   */
  public function isFastly($ip) {
    if (!isset($this->cache["fastly_{$ip}"])) {
      // @see https://api.fastly.com/public-ip-list
      $fastlyIps = [
        "23.235.32.0/20",
        "43.249.72.0/22",
        "103.244.50.0/24",
        "103.245.222.0/23",
        "103.245.224.0/24",
        "104.156.80.0/20",
        "146.75.0.0/16",
        "151.101.0.0/16",
        "157.52.64.0/18",
        "167.82.0.0/17",
        "167.82.128.0/20",
        "167.82.160.0/20",
        "167.82.224.0/20",
        "172.111.64.0/18",
        "185.31.16.0/22",
        "199.27.72.0/21",
        "199.232.0.0/16"
      ];
      $isFastlyIp = FALSE;
      foreach ($fastlyIps as $fastlyIp) {
        if ($this->isIpInRange($ip, $fastlyIp)) {
          $isFastlyIp = TRUE;
          break;
        }
      }
      $this->cache["fastly_{$ip}"] = $isFastlyIp;
    }

    return $this->cache["fastly_{$ip}"];
  }

  /**
   * Check if a given IPV4 belongs to Incapsula.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1.
   *
   * @return boolean
   *   TRUE if the ip belongs to Incapsula / FALSE if not.
   */
  public function isIncapsula($ip) {
    if (!isset($this->cache["incapsula_{$ip}"])) {
      // @see https://support.incapsula.com/hc/en-us/articles/200627570-Whitelist-Incapsula-IP-addresses-Setting-IP-restriction-rules
      $incapuslaIps = [
        "199.83.128.0/21",
        "198.143.32.0/19",
        "149.126.72.0/21",
        "103.28.248.0/22",
        "45.64.64.0/22",
        "185.11.124.0/22",
        "192.230.64.0/18",
        "107.154.0.0/16",
        "45.60.0.0/16",
        "45.223.0.0/16",
      ];
      $isIncapsulaIp = FALSE;
      foreach ($incapuslaIps as $incapuslaIp) {
        if ($this->isIpInRange($ip, $incapuslaIp)) {
          $isIncapsulaIp = TRUE;
          break;
        }
      }
      $this->cache["incapsula_{$ip}"] = $isIncapsulaIp;
    }

    return $this->cache["incapsula_{$ip}"];
  }

  /**
   * Check if a given IPV4 belongs to Akamai. These IP ranges are not published
   * so this is just a hand-curated list.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1.
   *
   * @return boolean
   *   TRUE if the ip belongs to Akamai / FALSE if not.
   */
  public function isAkamai($ip) {
    if (!isset($this->cache["akamai_{$ip}"])) {
      $akamaiIps = [
        "104.74.32.0/20",
      ];
      $isAkamaiIp = FALSE;
      foreach ($akamaiIps as $akamaiIp) {
        if ($this->isIpInRange($ip, $akamaiIp)) {
          $isAkamaiIp = TRUE;
          break;
        }
      }
      $this->cache["akamai_{$ip}"] = $isAkamaiIp;
    }

    return $this->cache["akamai_{$ip}"];
  }

  /**
   * Check if a given IPV4 belongs to Amazon.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1.
   *
   * @param string $type
   *   Valid values: AMAZON | AMAZON_APPFLOW | AMAZON_CONNECT | API_GATEWAY |
   *   CHIME_MEETINGS | CHIME_VOICECONNECTOR | CLOUD9 | CLOUDFRONT | CODEBUILD |
   *   DYNAMODB | EC2 | EC2_INSTANCE_CONNECT | GLOBALACCELERATOR | ROUTE53 |
   *   ROUTE53_HEALTHCHECKS | S3 | WORKSPACES_GATEWAYS
   *
   * @see https://docs.aws.amazon.com/general/latest/gr/aws-ip-ranges.html
   *
   * @return boolean
   *   TRUE if the ip belongs to the AWS service / FALSE if not.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function isAmazon($ip, $type = 'CLOUDFRONT') {
    $cacheFile = getenv('TEMP_FOLDER') . '/aws-ip-ranges.json';
    if (!file_exists($cacheFile)) {
      $response = $this->httpClient->request('GET', 'https://ip-ranges.amazonaws.com/ip-ranges.json');
      file_put_contents($cacheFile, (string) $response->getBody());
    }
    $body = json_decode(file_get_contents($cacheFile), TRUE);
    if (!isset($this->cache["{$type}_{$ip}"])) {
      $isAmazonIp = FALSE;
      foreach ($body['prefixes'] as $prefix) {
        if ($prefix['service'] !== $type) {
          continue;
        }
        if ($this->isIpInRange($ip, $prefix['ip_prefix'])) {
          $isAmazonIp = TRUE;
          break;
        }
      }
      $this->cache["{$type}_{$ip}"] = $isAmazonIp;
    }

    return $this->cache["{$type}_{$ip}"];
  }

  /**
   * Check if a given IPV4 belongs to Azure.
   *
   * @param string $ip
   *   IP to check in IPV4 format eg. 127.0.0.1.
   *
   * @param string $type
   *   Valid values: AzureFrontDoor.Frontend | AzureFrontDoor.Backend
   *
   * @see https://www.microsoft.com/en-us/download/confirmation.aspx?id=56519
   *
   * @return boolean
   *   TRUE if the ip belongs to the Azure service / FALSE if not.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function isAzure($ip, $type = 'AzureFrontDoor.Frontend') {
    $cacheFile = getenv('TEMP_FOLDER') . '/azure-ip-ranges.json';
    if (!file_exists($cacheFile)) {
      $response = $this->httpClient->request('GET', 'https://download.microsoft.com/download/7/1/D/71D86715-5596-4529-9B13-DA13A5DE5B63/ServiceTags_Public_20210426.json');
      file_put_contents($cacheFile, (string) $response->getBody());
    }
    $body = json_decode(file_get_contents($cacheFile), TRUE);
    if (!isset($this->cache["{$type}_{$ip}"])) {
      $isAzureIp = FALSE;
      if ($body) {
        foreach ($body['values'] as $service) {
          if ($service['name'] !== $type) {
            continue;
          }
          foreach ($service['properties']['addressPrefixes'] as $addressPrefix) {
            if ($this->isIpInRange($ip, $addressPrefix)) {
              $isAzureIp = TRUE;
              break;
            }
          }
        }
      }
      $this->cache["{$type}_{$ip}"] = $isAzureIp;
    }

    return $this->cache["{$type}_{$ip}"];
  }

}
