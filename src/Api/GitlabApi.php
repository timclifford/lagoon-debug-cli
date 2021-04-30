<?php

namespace App\Api;

use App\Api\Gitlab\Job;
use App\Api\RequestMatcher\GitlabProjectJobsRequestMatcher;
use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Header;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\KeyValueHttpHeader;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\Delegate\DelegatingCacheStrategy;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use Kevinrob\GuzzleCache\Strategy\NullCacheStrategy;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ElasticsearchApi. Uses singleton pattern.
 *
 * @package App\Api
 */
class GitlabApi {

  protected static ?GitlabApi $instance = NULL;
  protected ?Client $client = NULL;

  /**
   * @return \App\Api\GitlabApi
   */
  public static function getInstance() {
    if (self::$instance === NULL) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * ElasticsearchApi constructor.
   */
  protected function __construct() {
    // cURL handler.
    $handlerStack = HandlerStack::create(new CurlHandler());

    // Add in the caching middleware for project requests. This is mainly due to
    // having to query this API a lot.
    $cacheDir = sprintf('%s/gitlab', getenv('TEMP_FOLDER'));
    $strategy = new DelegatingCacheStrategy($defaultStrategy = new NullCacheStrategy());
    $strategy->registerRequestMatcher(new GitlabProjectJobsRequestMatcher(), new GreedyCacheStrategy(
      new DoctrineCacheStorage(
        new FilesystemCache($cacheDir)
      ),
      // 1 day.
      86400,
      // The headers that can change the cache key.
      new KeyValueHttpHeader([
        'Private-Token',
      ])
    ));
    $handlerStack->push(new CacheMiddleware($strategy));
    $this->client = new Client([
      'handler' => $handlerStack,
      'timeout' => 10,
      'connect_timeout' => 2,
      'allow_redirects' => FALSE,
      'headers' => [
        'User-Agent' => 'govcms-debug/1.0',
        'Accept' => 'application/json',
        'Private-Token' => getenv('GITLAB_TOKEN'),
      ],
      'base_uri' => 'https://projects.govcms.gov.au/api/v4/',
    ]);
  }

  /**
   * Perform an elasticsearch query.
   *
   * @param string $uri
   *   The URI to request. Prefixed with the base URI.
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function query(string $uri): array {
    if (!getenv('GITLAB_TOKEN')) {
      throw new \Exception('Missing credentials GITLAB_TOKEN');
    }
    $response = $this->client->get($uri);
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Attempt to find a Gitlab project by name.
   *
   * @param $projectName
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getProject(string $projectName) {
    if (!$projectName) {
      return [];
    }

    $projects = $this->query("projects?order_by=name&sort=asc&statistics=true&search={$projectName}");
    // Best case scenario, ony 1 project found.
    if (count($projects) === 1) {
      return $projects[0];
    }
    foreach ($projects as $projectLoop) {
      if ($projectLoop['path'] === $projectName) {
        return $projectLoop;
      }
    }

    return $projects;
  }

  /**
   * Find all non-archived projects.
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Exception
   */
  public function getAllProjects():array {
    if (!getenv('GITLAB_TOKEN')) {
      throw new \Exception('Missing credentials GITLAB_TOKEN');
    }
    $projects = [];
    $response = $this->client->get("projects?order_by=id&sort=asc&archived=false&pagination=keyset&per_page=100&simple=true");
    $body = json_decode((string) $response->getBody(), TRUE);
    $projects = array_merge($projects, $body);
    while ($this->getNextLink($response)) {
      $response = $this->client->get($this->getNextLink($response));
      $body = json_decode((string) $response->getBody(), TRUE);
      $projects = array_merge($projects, $body);
    }
    return $projects;
  }

  /**
   * Find all jobs for a given Gitlab project.
   *
   * @param string $projectName
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getAllJobs(string $projectName):array {
    if (!getenv('GITLAB_TOKEN')) {
      throw new \Exception('Missing credentials GITLAB_TOKEN');
    }

    $gitlabProject = $this->getProject($projectName);
    if (!isset($gitlabProject['id'])) {
      return [];
    }

    $jobs = [];

    // An HTTP 403 can be returned, if the project has CI disabled.
    try {
      $response = $this->client->get("projects/{$gitlabProject['id']}/jobs?order_by=id&sort=asc&pagination=keyset&per_page=100");
      $items = json_decode((string) $response->getBody(), TRUE);
      foreach ($items as $item) {
        $jobs[] = new Job($item);
      }
    }
    catch (\Exception $e) {
      return [];
    }
    while ($this->getNextLink($response)) {
      echo(">> {$this->getNextLink($response)}\n");
      $response = $this->client->get($this->getNextLink($response));
      $items = json_decode((string) $response->getBody(), TRUE);
      foreach ($items as $item) {
        $jobs[] = new Job($item);
      }
    }

    return $jobs;
  }

  /**
   * Attempt to parse any pagination headers from the response to find the next
   * URL to request.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *
   * @return string
   */
  private function getNextLink(ResponseInterface $response):string {
    if ($response->getHeader('Link')) {
      $links = Header::parse($response->getHeader('Link'));
      foreach ($links as $link) {
        if ($link['rel'] === 'next') {
          return trim($link[0], '<>');
        }
      }
    }
    return '';
  }

  /**
   * @param string $projectName
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getRecentEvents(string $projectName):array {
    $gitlabProject = $this->getProject($projectName);
    if (!isset($gitlabProject['id'])) {
      return [];
    }

    $events = $this->query("projects/{$gitlabProject['id']}/events");
    $parsedEvents = [];
    foreach ($events as $event) {
      // Convert the created date to AEST.
      $created = new \DateTime($event['created_at'], new \DateTimeZone('Australia/Sydney'));

      // Try to create human readable strings.
      switch ($event['action_name']) {
        case 'pushed to':
          $plural = $event['push_data']['commit_count'] > 1 ? 's' : '';
          $parsedEvents[] = "<strong>{$event['author']['name']}</strong> {$event['push_data']['action']} {$event['push_data']['commit_count']} commit{$plural} to <code>{$event['push_data']['ref']}</code> on {$created->format('d/m/Y')}";
          break;
        case 'removed due to membership expiration from':
          $parsedEvents[] = "<strong>{$event['author']['name']}</strong> left on {$created->format('d/m/Y')}";
          break;
        default :
          $parsedEvents[] = "<strong>{$event['author']['name']}</strong> {$event['action_name']} on {$created->format('d/m/Y')}";
          break;
      }
    }

    return $parsedEvents;
  }

  /**
   * @param string $projectName
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getRecentCommits(string $projectName):array {
    $gitlabProject = $this->getProject($projectName);
    if (!isset($gitlabProject['id'])) {
      return [];
    }

    $commits = $this->query("projects/{$gitlabProject['id']}/repository/commits");
    $authors = [];
    foreach ($commits as $commit) {
      if (!isset($authors[$commit['author_email']])) {
        $authors[$commit['author_email']] = 1;
      }
      else {
        $authors[$commit['author_email']]++;
      }
    }
    arsort($authors);
    return $authors;
  }

}
