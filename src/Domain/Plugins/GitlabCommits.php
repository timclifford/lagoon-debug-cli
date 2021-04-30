<?php

namespace App\Domain\Plugins;

use App\Api\GitlabApi;

class GitlabCommits extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'gitlabCommits';
  }

  /**
   * @inheritDoc
   * @throws \Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function analyseData():array {
    if ($this->getGovcmsGitlabProject()) {
      return GitlabApi::getInstance()->getRecentCommits($this->getGovcmsGitlabProject());
    }
    return [];
  }

}
