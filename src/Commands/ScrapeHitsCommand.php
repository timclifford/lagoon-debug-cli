<?php

namespace App\Commands;

use App\Api\ElasticsearchApi;
use Google_Client;
use Google_Service_Analytics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ScrapeHitsCommand extends Command {

  private ?Google_Service_Analytics $analytics = NULL;
  private array $projects = [];

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('scrape:hits')
      ->setDescription('Scrape hits data from Google Analytics and Elasticsearch. Joined by Openshift project.')
      ->setHelp(
        <<<EOT
        The <info>scrape:hits</info> command will gather data from Google Analytics and Elasticsearch. Joined by Openshift project.
        EOT
      )
      ->setAliases(['sh']);
  }

  /**
   * {@inheritdoc}
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Google_Exception
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $io->title('Scrape Hits');
    $combinedJson = [];
    $this->projects = ScrapeOpenshiftCommand::getProjects();

    // Google Analytics is optional.
    if (getenv('GOOGLE_ANALYTICS_VIEW_ID')) {
      $io->writeln("Scraping Google Analytics");
      $ga = $this->scrapeGoogleAnalytics();
      foreach ($ga as $hostname => $months) {
        // Only production projects will have a namespace found.
        if ($namespace = $this->findNamespaceForHostname($hostname)) {
          $combinedJson[$namespace][$hostname] = $months;
        }
      }
    }
    else {
      $io->writeln("Skipping GA due to no variable GOOGLE_ANALYTICS_VIEW_ID defined.");
    }

    // Elasticsearch.
    if (getenv('ELASTICSEARCH_TOKEN')) {
      $io->writeln("Scraping Elasticsearch");
      $esTotal = ElasticsearchApi::getInstance()->getTotalPhpTimeForMonth('last30days');
      $esProjects = ElasticsearchApi::getInstance()->getPhpTimeForMonth(1000, 'last30days');
      foreach ($esProjects['buckets'] as $index => $row) {
        $time = (int) $row[1]['value'] < 0 ? 0 : (int) $row[1]['value'];
        // Key on namespace.
        $combinedJson[$row['key']]['30daysOriginHits'] = $row['doc_count'];
        $combinedJson[$row['key']]['30daysOriginHitsPercent'] = (($row['doc_count'] / $esTotal['totalHits']) * 100);
        $combinedJson[$row['key']]['30daysOriginTime'] = $time;
        $combinedJson[$row['key']]['30daysOriginTimePercent'] = (($time / $esTotal['totalTime']) * 100);
      }

      $countNamespaces = count($combinedJson);
      $cachedFilename = self::getCacheFilename();
      file_put_contents($cachedFilename, json_encode($combinedJson, JSON_PRETTY_PRINT));
      $io->success("Successfully read {$countNamespaces} namespaces, cached to file {$cachedFilename}");
    }
    else {
      $io->writeln("Skipping Elasticsearch due to no variable ELASTICSEARCH_TOKEN defined.");
    }

    return 0;
  }

  /**
   * @throws \Google_Exception
   * @throws \Exception
   */
  private function scrapeGoogleAnalytics():array {
    $client = new Google_Client();
    $client->setApplicationName("GovCMS Debug");
    $client->useApplicationDefaultCredentials();
    $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
    $this->analytics = new Google_Service_Analytics($client);

    // Last 30 full days.
    $results = $this->getPageviewsByHostname('31daysAgo', 'yesterday');
    $json = [];
    foreach ($results['rows'] as $row) {
      $json[$row[0]] = [
        '30days' => $row[1],
        '30daysPercent' => (($row[1] / $results['total']) * 100),
      ];
    }

    // Last 12 calendar months.
    for ($calendarMonths = 1; $calendarMonths <= 12 ; $calendarMonths++) {
      $start = new \DateTime('now', new \DateTimeZone('Australia/Sydney'));
      $start->setTime(0, 0, 0);
      $start->modify('first day of this month');
      $start->modify("-{$calendarMonths} months");
      $end = clone($start);
      $end->modify('+1 month');
      $end->modify('-1 second');
      $results = $this->getPageviewsByHostname($start->format('Y-m-d'), $end->format('Y-m-d'));
      foreach ($results['rows'] as $row) {
        $json[$row[0]][$start->format('Y-m')] = $row[1];
      }
    }

    return $json;
  }

  /**
   * The file based cache of data. This is not accessible from nginx.
   *
   * @return string
   */
  public static function getCacheFilename():string {
    return getenv('TEMP_FOLDER') . "/hits.json";
  }

  /**
   * Get the latest scrape data.
   *
   * @return array
   */
  public static function getHits():array {
    return json_decode(file_get_contents(self::getCacheFilename()),TRUE);
  }

  /**
   * @param string $hostname
   *
   * @return ?string
   */
  private function findNamespaceForHostname(string $hostname):?string {
    foreach ($this->projects as $namesapce => $project) {
      foreach ($project['routes'] as $route) {
        if ($route['host'] === $hostname) {
          return $namesapce;
        }
      }
    }
    return NULL;
  }

  /**
   * Get pageviews.
   *
   * @param string $start
   *   YYYY-MM-DD format.
   * @param string $end
   *   YYYY-MM-DD format.
   *
   * @return array
   * @throws \Exception
   */
  private function getPageviewsByHostname(string $start, string $end):array {
    $results = $this->analytics->data_ga->get(
      getenv('GOOGLE_ANALYTICS_VIEW_ID'),
      $start,
      $end,
      'ga:pageviews',
      [
        'max-results' => 1000,
        'dimensions' => 'ga:hostname',
        'sort' => '-ga:pageviews',
      ]);

    if (count($results->getRows()) === 0) {
      throw new \Exception("No data found.");
    }

    return [
      'rows' => $results->getRows(),
      'total' => $results->getTotalsForAllResults()['ga:pageviews'],
    ];
  }

}
