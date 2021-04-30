<?php

namespace App\Domain\Plugins;

use App\Commands\ScrapeGitlabCommand;

class GitlabJobs extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'gitlabJobs';
  }

  /**
   * @inheritDoc
   * @return array
   */
  public function analyseData():array {
    if ($this->getGovcmsGitlabProject()) {
      $jobs = ScrapeGitlabCommand::getJobs();
      return $jobs[$this->getGovcmsGitlabProject()] ?? [];
    }
    return [];
  }

}
