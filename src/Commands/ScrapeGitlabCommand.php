<?php

namespace App\Commands;

use App\Api\ElasticsearchApi;
use App\Api\GitlabApi;
use Cassandra\Date;
use Google_Client;
use Google_Service_Analytics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ScrapeGitlabCommand extends Command {

  private array $projects = [];

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('scrape:gitlab')
      ->setDescription('Scrape job data from Gitlab.')
      ->setHelp(
        <<<EOT
        The <info>scrape:gitlab</info> command will gather job data from Gitlab.
        EOT
      )
      ->setAliases(['sg']);
  }

  /**
   * {@inheritdoc}
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Google_Exception
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $io->title('Scrape Gitlab');
    if (!getenv('GITLAB_TOKEN')) {
      $io->error('Missing credentials GITLAB_TOKEN');
      return 1;
    }

    $jobsByMonth = [];
    $totalJobs = 0;
    $totalDuration = 0;
    $this->projects = GitlabApi::getInstance()->getAllProjects();
    foreach ($this->projects as $index => $project) {
      $io->writeln("> $index - {$project['name']}");
      $jobs = GitlabApi::getInstance()->getAllJobs($project['name']);
      $countJobs = count($jobs);
      $io->writeln(">> Found {$countJobs} jobs.");
      /** @var \App\Api\Gitlab\Job $job */
      foreach ($jobs as $job) {
        if (!isset($jobsByMonth[$project['name']][$job->getCreatedMonth()])) {
          $jobsByMonth[$project['name']][$job->getCreatedMonth()] = $job->getDuration();
        }
        else {
          $jobsByMonth[$project['name']][$job->getCreatedMonth()] += $job->getDuration();
        }
        $totalJobs++;
        $totalDuration += $job->getDuration();
      }

      // If you found jobs, sort them.
      if (!empty($jobsByMonth[$project['name']])) {
        ksort($jobsByMonth[$project['name']]);

        // Fill in the gaps with zeros.
        $firstMonth = '2018-08';
        $today = new \DateTime('now', new \DateTimeZone('Australia/Sydney'));
        $month = new \DateTime("{$firstMonth}-01", new \DateTimeZone('Australia/Sydney'));
        do {
          if (!isset($jobsByMonth[$project['name']][$month->format('Y-m')])) {
            $jobsByMonth[$project['name']][$month->format('Y-m')] = 0;
          }
          $month->modify('+1 month');
        }
        while($month < $today);

        // Sort again.
        ksort($jobsByMonth[$project['name']]);
      }
    }

    $cachedFilename = self::getCacheFilename();
    file_put_contents($cachedFilename, json_encode($jobsByMonth, JSON_PRETTY_PRINT));
    $io->success("Successfully read {$totalJobs} jobs (that used up {$totalDuration} seconds), cached to file {$cachedFilename}");
    return 0;
  }

  /**
   * The file based cache of data. This is not accessible from nginx.
   *
   * @return string
   */
  public static function getCacheFilename():string {
    return getenv('TEMP_FOLDER') . "/gitlab-jobs.json";
  }

  /**
   * Get the latest scrape data.
   *
   * @return array
   */
  public static function getJobs():array {
    return json_decode(file_get_contents(self::getCacheFilename()),TRUE);
  }

  /**
   * Get the last time the scraps was run.
   *
   * @return int
   */
  public static function getLastUpdated():int {
    return filemtime(self::getCacheFilename());
  }

}
