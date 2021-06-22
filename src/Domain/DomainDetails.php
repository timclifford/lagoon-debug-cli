<?php

namespace App\Domain;

use Clockwork\Clockwork;
use GuzzleHttp\Client;
use HaydenPierce\ClassFinder\ClassFinder;
use Pdp\Cache;
use Pdp\CurlHttpClient;
use Pdp\Domain;
use Pdp\Manager;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;

class DomainDetails {

  const CLOUDFLARE_DNS = '1.1.1.1';
  const GOOGLE_DNS = '8.8.8.8';

  protected string $domain = '';
  protected bool $isOps = FALSE;
  protected bool $hasEdge = FALSE;
  protected bool $hasSsl = TRUE;
  protected bool $isLagoon = FALSE;

  protected array $cache = [];
  protected array $variables = [];
  protected Client $httpClient;
  protected ResponseInterface $response;

  protected array $plugins;

  /**
   * DomainDetails constructor.
   *
   * @param \Clockwork\Clockwork $clockwork
   * @param string $domain
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function __construct($clockwork, $domain) {
    $this->domain = $domain;
    $this->httpClient = new Client([
      'timeout' => 10,
      'connect_timeout' => 2,
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'headers' => [
        'User-Agent' => 'debug/1.0',
        'Accept' => 'text/html',
        'Fastly-Debug' => '1',
      ],
    ]);

    // Available in twig for templating.
    $this->variables['domain'] = $this->domain;

    $clockwork->event('HTTP request')->begin();
    $this->requestHomepageOverHttp($this->domain);
    $clockwork->event('HTTP request')->end();

    // Domain details.
    $clockwork->event('Domain details')->begin();
    $domainDetails = $this->getDomainDetails($this->domain);
    $this->variables['domainDetails'] = [
      'domain' => $domainDetails->getContent(),
      'registrableDomain' => $domainDetails->getRegistrableDomain(),
      'subDomain' => $domainDetails->getSubDomain(),
      'publicSuffix' => $domainDetails->getPublicSuffix(),
      'isKnown' => $domainDetails->isKnown(),
      'isICANN' => $domainDetails->isICANN(),
      'isPrivate' => $domainDetails->isPrivate(),
    ];
    $clockwork->event('Domain details')->end();

    // DNS records.
    $clockwork->event('DNS details')->begin();
    $ipv4Records = $this->getDnsRecords($this->domain, 'CNAME');
    $this->variables['ipv4Records'] = $ipv4Records;
    $ipv6Records = $this->getDnsRecords($this->domain, 'AAAA');
    $this->variables['ipv6Records'] = $ipv6Records;
    $clockwork->event('DNS details')->end();

    // Registrable domain.
    $registrableDomain = $this->variables['domainDetails']['registrableDomain'] ?? NULL;

    // Metatags.
    $metaTags = $this->getMetaTags((string) $this->response->getBody());
    $this->variables['metatags'] = $metaTags;

    // Plugins.
    $this->plugins = $this->findPlugins();
    /** @var \App\Domain\Plugins\BasePlugin $plugin */
    foreach ($this->plugins as $plugin) {
      $plugin->setIpv4Records($ipv4Records);
      $plugin->setIpv6Records($ipv6Records);
      $plugin->setResponse($this->response);
      $plugin->setMetatags($metaTags);
      $plugin->setRegistrableDomain($registrableDomain);
      $clockwork->event("Plugin {$plugin->getMachineName()}")->begin();
      $this->variables[$plugin->getMachineName()] = $plugin->analyseData();
      if ($plugin->getMachineName() === "backend") {
          $this->isLagoon = $this->variables[$plugin->getMachineName()]['isLagoon'];
      }
      $clockwork->event("Plugin {$plugin->getMachineName()}")->end();
    }

  }

  /**
   * @return array
   */
  public function getVariables(): array {
    return $this->variables;
  }

  /**
   * Get a list of plugins.
   *
   * @return array
   */
  public function findPlugins() {
    $plugins = [];
    $classes = ClassFinder::getClassesInNamespace('App\Domain\Plugins');
    foreach ($classes as $class) {
      $reflection = new ReflectionClass($class);
      if (!$reflection->isAbstract()) {
        $plugins[] = new $class($this->domain, $this->httpClient);
      }
    }
    return $plugins;
  }

  public function isLagoon(): bool {
    return $this->isLagoon;
  }

  /**
   * Basic tests to ensure domain is valid.
   *
   * @see https://stackoverflow.com/questions/1755144/how-to-validate-domain-name-in-php
   *
   * @param string $domain
   *   The domain name to check.
   *
   * @return bool
   *   Whether the domain looks valid or not
   */
  public static function isValidDomainName($domain): bool {
    // Valid chars check.
    return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain)
      // Overall length check.
      && preg_match("/^.{1,253}$/", $domain)
      // Length of each label.
      && preg_match("/^[^.]{1,63}(\.[^.]{1,63})*$/", $domain));
  }

  /**
   * Request the homepage via HTTP(S).
   *
   * @param string $domain
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function requestHomepageOverHttp(string $domain) {
    try {
      $this->hasSsl = TRUE;
      $this->response = $this->httpClient->request('GET', "https://{$domain}/");
    }
    catch (\Exception $e) {
      // cURL error 28: Connection timed out after 2001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)
      // cURL error 60: SSL certificate problem: unable to get local issuer certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)
      $this->variables['connectError'] = explode(' (see', $e->getMessage())[0];
      $this->hasSsl = FALSE;
      $this->response = $this->httpClient->request('GET', "http://{$domain}/");
    }
    $this->variables['hasSsl'] = $this->hasSsl;
  }

  /**
   * Get a DNS record using dig, using Cloudflare's nameserver. This seems to be
   * 100% more accurate than using PHPs built in dns_get_record().
   *
   * @param $name
   *   The DNS record name to search for.
   * @param $type
   *   Uppercase DNS record type.
   *
   * @return array
   *   Record values, in an array. If no records are found, then the array is
   *   empty.
   * @throws \Exception
   */
  public static function getDnsRecords($name, $type = 'CNAME') {
    $dnsToUse = self::CLOUDFLARE_DNS;
    if ($type === 'CNAME') {
      exec("dig @{$dnsToUse} {$name} +short", $output, $returnVar);
    }
    else {
      exec("dig @{$dnsToUse} {$name} -t {$type} +short", $output, $returnVar);
    }
    return $output;
  }

  /**
   * Get information about a domain.
   *
   * @param $domain
   *
   * @return \Pdp\Domain
   *
   * @throws \Exception
   */
  protected function getDomainDetails($domain): Domain {
    $dir = getenv('TEMP_FOLDER');
    if (!file_exists($dir) && !mkdir($dir, 0744, TRUE)) {
      throw new \Exception("Could not create cache directory {$dir}");
    }
    $manager = new Manager(new Cache($dir), new CurlHttpClient());
    $rules = $manager->getRules();
    return $rules->resolve($domain);
  }

  /**
   * @param $str
   *
   * @see https://www.php.net/manual/en/function.get-meta-tags.php#117766
   * @return array
   */
  private function getMetaTags($str) {
    $pattern = '
  ~<\s*meta\s

  # using lookahead to capture type to $1
    (?=[^>]*?
    \b(?:name|property|http-equiv)\s*=\s*
    (?|"\s*([^"]*?)\s*"|\'\s*([^\']*?)\s*\'|
    ([^"\'>]*?)(?=\s*/?\s*>|\s\w+\s*=))
  )

  # capture content to $2
  [^>]*?\bcontent\s*=\s*
    (?|"\s*([^"]*?)\s*"|\'\s*([^\']*?)\s*\'|
    ([^"\'>]*?)(?=\s*/?\s*>|\s\w+\s*=))
  [^>]*>

  ~ix';

    if (preg_match_all($pattern, $str, $out)) {
      $metaTags = array_combine($out[1], $out[2]);
      // Prevent double encoding.
      $metaTags = array_map(function ($item) {
        return htmlspecialchars_decode($item, ENT_QUOTES);
      }, $metaTags);
      return $metaTags;
    }

    return [];
  }

}
