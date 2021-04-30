<?php

namespace App\Api;

use GuzzleHttp\Client;

/**
 * Class LagoonApi. Uses singleton pattern.
 *
 * @package App\Api
 */
class LagoonApi {

  protected static ?LagoonApi $instance = NULL;
  protected ?Client $client = NULL;
  protected array $cache = [];

  /**
   * @return \App\Api\LagoonApi
   */
  public static function getInstance() {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * LagoonApi constructor.
   */
  protected function __construct() {
    $this->client = new Client([
      'timeout' => 10,
      'connect_timeout' => 4,
      'allow_redirects' => FALSE,
      'headers' => [
        'User-Agent' => 'govcms-debug/1.0',
        'Accept' => 'application/json',
        'Authorization' => 'bearer ' . getenv('LAGOON_TOKEN'),
      ],
    ]);
  }

  /**
   * Perform a Graphql query.
   *
   * @param string $query
   * @param array $variables
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Exception
   */
  public function graphqlQuery(string $query, array $variables = []): array {
    if (!getenv('LAGOON_ENDPOINT') || !getenv('LAGOON_TOKEN')) {
      throw new \Exception('Missing credentials LAGOON_ENDPOINT, LAGOON_TOKEN');
    }
    $response = $this->client->post(getenv('LAGOON_ENDPOINT'), [
      'json' => [
        'query' => $query,
        'variables' => $variables,
      ],
    ]);
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Update a key/value pair in the metadata.
   *
   * @param $project
   * @param $key
   * @param $value
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function updateMetadata($projectId, $key, $value) {
    $query = <<<GRAPHQL
      mutation {
        updateProjectMetadata(
          input: { id: {$projectId}, patch: { key: "{$key}", value: "{$value}" } }
        ) {
          id
          metadata
        }
      }
      GRAPHQL;

    $response = $this->graphqlQuery($query);
    return $response['data']['updateProjectMetadata'];
  }

  /**
   * Gets the details about Openshift.
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getOpenshifts():array {
    $query = <<<'GRAPHQL'
      query Shifts {
        allOpenshifts {
          name
          consoleUrl
          token
        }
      }
      GRAPHQL;

    $response = $this->graphqlQuery($query);
    return $response['data']['allOpenshifts'];
  }

  /**
   * Gets the hit data for all production projects for last month.
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getHits(string $month):array {
    $query = <<<GRAPHQL
      query Hits {
        allProjects {
          environments:environments(includeDeleted: true, type: PRODUCTION) {
            project {
              name
            }
            name
            environmentType
            created
            deleted
            hits:hitsMonth(month: "{$month}") {
              total
            }
          }
        }
      }
      GRAPHQL;

    $response = $this->graphqlQuery($query);

    $json = [];
    foreach ($response['data']['allProjects'] as $project) {
      foreach ($project as $environment) {
        foreach ($environment as $data) {
          $namespace = "{$data['project']['name']}-{$data['name']}";
          $json[$namespace] = [
            'environmentType' => $data['environmentType'],
            'created' => $data['created'],
            'deleted' => $data['deleted'] === '0000-00-00 00:00:00' ? FALSE : $data['deleted'],
            'hits' => $data['hits']['total'],
          ];
        }
      }
    }

    return $json;
  }

  /**
   * @param $project
   *
   * @see https://github.com/amazeeio/lagoon/blob/main/services/api/src/resolvers.js#L243
   *
   * @return array
   *   Record values, in an array. If no records are found, then the array is
   *   empty.
   * @throws \Exception|\GuzzleHttp\Exception\GuzzleException
   */
  public function getLagoonProject($project) {
    if (isset($this->cache[$project])) {
      return $this->cache[$project];
    }

    $query = <<<'GRAPHQL'
      query GetProject($project: String!) {
        projectByName(
          name: $project
        ) {
          id
          name
          branches
          gitUrl
          metadata
          pullrequests
          productionEnvironment
          developmentEnvironmentsLimit
          storageCalc
          envVariables {
            id
            name
            scope
          }
          environments {
            name
            id
            deployType
            environmentType
            storages {
              claim: persistentStorageClaim
              kb: bytesUsed
              updated
            }
            envVariables {
              id
              name
              scope
            }
          }
          openshift {
            id
          }
        }
      }
      GRAPHQL;

    $response = $this->graphqlQuery($query, [
      'project' => $project,
    ]);
    $data = $response['data']['projectByName'];
    if (isset($data['environments'])) {
      foreach ($data['environments'] as $key => $environment) {
        // Sort the storage by date.
        $storages = $environment['storages'];
        if ($storages) {
            usort($storages, function ($a, $b) {
                return $b['updated'] <=> $a['updated'];
            });

            // Remove all storages except the latest one, and the first of the month.
            $cleanedStorages = [];
            $today = new \DateTime('now', new \DateTimeZone('UTC'));
            $firstDate = $storages[0]['updated'] ?? $today->format('Y-m-d');
            foreach ($storages as $index => $storage) {
                if ($storage['updated'] === $firstDate || preg_match('#-01$#', $storage['updated'])) {
                    $cleanedStorages[] = $storage;
                }
            }
            $data['environments'][$key]['storages'] = $cleanedStorages;
        }
      }
    }

    $this->cache[$project] = $data;
    return $this->cache[$project];
  }

}
