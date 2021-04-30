<?php

namespace App\Domain\Plugins;

class PoweredBy extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'poweredBy';
  }

  /**
   * Sometimes the X-Powered-By header can be useful and/or entertaining.
   *
   * @inheritDoc
   */
  public function analyseData() {
    $xPoweredBy = $this->response->getHeaderLine('x-powered-by');
    return $xPoweredBy;
  }

}
