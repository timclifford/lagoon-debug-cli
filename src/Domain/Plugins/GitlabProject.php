<?php

namespace App\Domain\Plugins;

use App\Api\GitlabApi;

class GitlabProject extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'gitlabProject';
  }

  /**
   * @inheritDoc
   * @throws \Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function analyseData():array {
    if ($this->getGovcmsGitlabProject()) {
      return GitlabApi::getInstance()->getProject($this->getGovcmsGitlabProject());
    }
    return [];
  }

}
