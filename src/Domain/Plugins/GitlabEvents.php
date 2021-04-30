<?php


namespace App\Domain\Plugins;


use App\Api\GitlabApi;

class GitlabEvents extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'gitlabEvents';
  }

  /**
   * @inheritDoc
   * @throws \Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function analyseData():array {
    if ($this->getGovcmsGitlabProject()) {
      return GitlabApi::getInstance()->getRecentEvents($this->getGovcmsGitlabProject());
    }
    return [];
  }

}
