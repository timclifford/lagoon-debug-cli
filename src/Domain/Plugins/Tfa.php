<?php

namespace App\Domain\Plugins;

use App\Api\LagoonApi;

class Tfa extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'hasTFA';
  }

  /**
   * @inheritDoc
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function analyseData() {
    if ($this->isLagoon()) {
      $lagoonProject = $this->getNamespaceFromHeader();
      $lagoonProjectData = LagoonApi::getInstance()->getLagoonProject($lagoonProject);

      // TFA.
      if (isset($lagoonProjectData['environments'])) {
        foreach ($lagoonProjectData['environments'] as $environment) {
          foreach ($environment['envVariables'] as $envVariable) {
            if ($envVariable['name'] === 'KEY_ENCRYPT_TFA') {
              return TRUE;
            }
          }
        }
      }
    }

    return FALSE;
  }

}
