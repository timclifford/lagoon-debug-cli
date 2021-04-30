<?php

namespace App\Domain\Plugins;

class HttpAuth extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'hasHttpAuth';
  }

  /**
   * @inheritDoc
   */
  public function analyseData() {
    return $this->response->getStatusCode() === 401;
  }

}
