<?php

namespace App\Domain\Plugins;

use App\Api\LagoonApi;
use App\Commands\ScrapeOpenshiftCommand;

class LagoonProject extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName(): string {
    return 'lagoonProject';
  }

  /**
   * @inheritDoc
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function analyseData(): array {
    // Retrieve Lagoon data via GraphQL.
    if ($this->isLagoon()) {
      // Attempt to use the X-Lagoon header.
      $namespace = $this->getNamespaceFromHeader();
      if (empty($namespace)) {
        // Attempt to check all the URLs in the most recent scrape.
        $projects = ScrapeOpenshiftCommand::getProjects();
        foreach ($projects as $namespaceInner => $project) {
          foreach ($project['routes'] as $route) {
            if ($route['host'] === $this->domain) {
              $namespace = $namespaceInner;
              break 2;
            }
          }
        }
      }
      if (empty($namespace)) {
        return [];
      }

      $projectName = self::workOutProjectNameFromNamespace($namespace);
      $lagoonProjectData = LagoonApi::getInstance()->getLagoonProject($projectName);

      // In case this project is not hosted on this Lagoon.
      if (empty($lagoonProjectData)) {
        return [];
      }

      // Support k/v project metadata.
      if (!empty($lagoonProjectData['metadata'])) {
        $lagoonProjectData['metadata'] = json_decode($lagoonProjectData['metadata'], TRUE);
      }

      return $lagoonProjectData;
    }

    return [];
  }

}
