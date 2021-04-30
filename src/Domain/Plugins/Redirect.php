<?php

namespace App\Domain\Plugins;

class Redirect extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'redirect';
  }

  /**
   * @inheritDoc
   */
  public function analyseData() {
    switch ($this->response->getStatusCode()) {
      case 301:
      case 302:
      case 303:
      case 307:
      case 308:
        $location = $this->response->getHeaderLine('Location');
        // Ensure the URL is absolute.
        if (preg_match('#^/#', $location)) {
          $location = "https://{$this->domain}{$location}";
        }
        $parse = parse_url($location);
        $locationDomain = $parse['host'];
        return [
          'redirect' => $this->response->getHeaderLine('Location'),
          'redirectDomain' => $locationDomain,
        ];
    }

    return [];
  }

}
