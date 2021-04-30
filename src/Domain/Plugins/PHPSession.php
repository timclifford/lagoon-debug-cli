<?php

namespace App\Domain\Plugins;

class PHPSession extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'phpsession';
  }

  /**
   * @inheritDoc
   *
   * This can cause false positives with Akamai Bot Manager.
   */
  public function analyseData() {
    $cookies = $this->response->getHeader('set-cookie');
    foreach ($cookies as $cookie) {
      if (preg_match('#^SSESS.+HttpOnly$#', $cookie)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
