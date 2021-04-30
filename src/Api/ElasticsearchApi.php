<?php

namespace App\Api;

use GuzzleHttp\Client;

/**
 * Class ElasticsearchApi. Uses singleton pattern.
 *
 * @package App\Api
 */
class ElasticsearchApi {

  protected static ?ElasticsearchApi $instance = NULL;
  protected ?Client $client = NULL;

  /**
   * @return \App\Api\ElasticsearchApi
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
    $this->client = new Client([
      'base_uri' => 'http://' . getenv('ELASTICSEARCH_ENDPOINT') . ':9200/router-logs-*/_search?rest_total_hits_as_int=true&ignore_unavailable=true&ignore_throttled=true&timeout=30000ms',
      'auth' => [
        'admin',
        getenv('ELASTICSEARCH_TOKEN'),
      ],
      'timeout' => 60,
      'connect_timeout' => 5,
      'allow_redirects' => FALSE,
      'headers' => [
        'User-Agent' => 'govcms-debug/1.0',
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ],
    ]);
  }

  /**
   * Perform an elasticsearch query.
   *
   * @param string $query
   *   The query to perform
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function query(string $query): array {
    if (!getenv('ELASTICSEARCH_ENDPOINT') || !getenv('ELASTICSEARCH_TOKEN')) {
      throw new \Exception('Missing credentials ELASTICSEARCH_ENDPOINT, ELASTICSEARCH_TOKEN');
    }
    $response = $this->client->post('', [
      'body' => $query,
    ]);
    $json = json_decode((string) $response->getBody(), TRUE);
    if ($json['timed_out']) {
      throw new \Exception("Request took longer than 30 seconds.");
    }
    return $json;
  }

  /**
   * Get the total amount of router hits for a given project.
   *
   * @param string $namespace
   *   The namespace of the project.
   * @param string $month
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Exception
   */
  public function getHitsForMonth(string $namespace, string $month): int {
    // Work out the month start and end.
    $start = new \DateTime('now', new \DateTimeZone('Australia/Sydney'));
    $start->setTime(0, 0, 0);
    switch ($month) {
      case 'lastmonth':
        $start->modify('first day of last month');
        break;
      default:
        $start->modify('first day of this month');
    }
    $end = clone($start);
    $end->modify('+1 month');
    $body = <<<BODY
      {
        "aggs": {},
        "size": 0,
        "stored_fields": [
          "*"
        ],
        "script_fields": {},
        "docvalue_fields": [
          {
            "field": "@timestamp",
            "format": "date_time"
          }
        ],
        "_source": {
          "excludes": []
        },
        "query": {
          "bool": {
            "must": [],
            "filter": [
              {
                "match_all": {}
              },
              {
                "match_phrase": {
                  "openshift_project.keyword": "{$namespace}"
                }
              },
              {
                "range": {
                  "@timestamp": {
                    "gte": "{$start->format(DATE_RFC3339_EXTENDED)}",
                    "lte": "{$end->format(DATE_RFC3339_EXTENDED)}",
                    "format": "strict_date_optional_time"
                  }
                }
              }
            ],
            "should": [],
            "must_not": []
          }
        }
      }
      BODY;

    return $this->query($body)['hits']['total'];
  }

  /**
   * Get the total amount of router hits for a given project.
   *
   * @param int $size
   * @param string $month
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getPhpTimeForMonth(int $size, string $month):array {
    // Work out the month start and end.
    $start = new \DateTime('now', new \DateTimeZone('Australia/Sydney'));
    $start->setTime(0, 0, 0);
    switch ($month) {
      case 'last30days':
        $start->modify('-1 month');
        break;
      case 'lastmonth':
        $start->modify('first day of last month');
        break;
      default:
        $start->modify('first day of this month');
    }
    $end = clone($start);
    $end->modify('+1 month');
    $body = <<<BODY
      {
        "aggs": {
          "2": {
            "terms": {
              "field": "openshift_project.keyword",
              "order": {
                "1": "desc"
              },
              "size": {$size}
            },
            "aggs": {
              "1": {
                "sum": {
                  "script": {
                    "source": "if (doc.containsKey('time_backend_response') ) { return Integer.parseInt(doc['time_backend_response.keyword'].value)}",
                    "lang": "painless"
                  }
                }
              }
            }
          }
        },
        "size": 0,
        "stored_fields": [
          "*"
        ],
        "script_fields": {
          "time_backend_response_int": {
            "script": {
              "source": "if (doc.containsKey('time_backend_response') ) { return Integer.parseInt(doc['time_backend_response.keyword'].value)}",
              "lang": "painless"
            }
          }
        },
        "docvalue_fields": [
          {
            "field": "@timestamp",
            "format": "date_time"
          }
        ],
        "_source": {
          "excludes": []
        },
        "query": {
          "bool": {
            "must": [],
            "filter": [
              {
                "match_all": {}
              },
              {
                "match_all": {}
              },
              {
                "range": {
                  "@timestamp": {
                    "gte": "{$start->format(DATE_RFC3339_EXTENDED)}",
                    "lte": "{$end->format(DATE_RFC3339_EXTENDED)}",
                    "format": "strict_date_optional_time"
                  }
                }
              }
            ],
            "should": [],
            "must_not": [
              {
                "bool": {
                  "minimum_should_match": 1,
                  "should": [
                    {
                      "match_phrase": {
                        "openshift_project": "errors"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "default"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "public"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "lagoon"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "gitlab-production"
                      }
                    }
                  ]
                }
              },
              {
                "bool": {
                  "minimum_should_match": 1,
                  "should": [
                    {
                      "match_phrase": {
                        "openshift_project": "errors"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "default"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "public"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "lagoon"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "gitlab-production"
                      }
                    }
                  ]
                }
              }
            ]
          }
        }
      }
      BODY;

    $response = $this->query($body);
    return [
      'totalHits' => $response['hits']['total'],
      'buckets' => $response['aggregations'][2]['buckets'],
    ];

  }

  /**
   * Get the total amount of router hits for a given project.
   *
   * @param int $size
   * @param string $month
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Exception
   */
  public function getTotalPhpTimeForMonth(string $month):array {
    // Work out the month start and end.
    $start = new \DateTime('now', new \DateTimeZone('Australia/Sydney'));
    $start->setTime(0, 0, 0);
    switch ($month) {
      case 'last30days':
        $start->modify('-1 month');
        break;
      case 'lastmonth':
        $start->modify('first day of last month');
        break;
      default:
        $start->modify('first day of this month');
    }
    $end = clone($start);
    $end->modify('+1 month');
    $body = <<<BODY
      {
        "aggs": {
          "1": {
            "sum": {
              "script": {
                    "source": "if (doc.containsKey('time_backend_response') ) { return Integer.parseInt(doc['time_backend_response.keyword'].value)}",
                "lang": "painless"
              }
            }
          }
        },
        "size": 0,
        "stored_fields": [
          "*"
        ],
        "script_fields": {
          "time_backend_response_int": {
            "script": {
              "source": "if (doc.containsKey('time_backend_response') ) { return Integer.parseInt(doc['time_backend_response.keyword'].value)}",
              "lang": "painless"
            }
          }
        },
        "docvalue_fields": [
          {
            "field": "@timestamp",
            "format": "date_time"
          }
        ],
        "_source": {
          "excludes": []
        },
        "query": {
          "bool": {
            "must": [],
            "filter": [
              {
                "match_all": {}
              },
              {
                "match_all": {}
              },
              {
                "range": {
                  "@timestamp": {
                    "gte": "{$start->format(DATE_RFC3339_EXTENDED)}",
                    "lte": "{$end->format(DATE_RFC3339_EXTENDED)}",
                    "format": "strict_date_optional_time"
                  }
                }
              }
            ],
            "should": [],
            "must_not": [
              {
                "bool": {
                  "minimum_should_match": 1,
                  "should": [
                    {
                      "match_phrase": {
                        "openshift_project": "errors"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "default"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "public"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "lagoon"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "gitlab-production"
                      }
                    }
                  ]
                }
              },
              {
                "bool": {
                  "minimum_should_match": 1,
                  "should": [
                    {
                      "match_phrase": {
                        "openshift_project": "errors"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "default"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "public"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "lagoon"
                      }
                    },
                    {
                      "match_phrase": {
                        "openshift_project": "gitlab-production"
                      }
                    }
                  ]
                }
              }
            ]
          }
        }
      }
      BODY;

    $response = $this->query($body);
    return [
      'totalHits' => $response['hits']['total'],
      'totalTime' => $response['aggregations'][1]['value'],
    ];
  }


}
