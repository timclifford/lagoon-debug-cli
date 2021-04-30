<?php

namespace App\Domain\Plugins;

use App\Commands\ScrapeOpenshiftCommand;

class OpenshiftProject extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName(): string {
    return 'openshiftProject';
  }

  /**
   * @inheritDoc
   */
  public function analyseData(): array {
    $projects = ScrapeOpenshiftCommand::getProjects();

    // Attempt to use the X-Lagoon header.
    if ($this->getNamespaceFromHeader()) {
      return $projects[$this->getNamespaceFromHeader()] ?? [];
    }

    // Attempt to check all the URLs in the most recent scrape.
    foreach ($projects as $namespace => $project) {
      foreach ($project['routes'] as $route) {
        if ($route['host'] === $this->domain) {
          return $projects[$namespace];
        }
      }
    }

    return [];
  }

}
