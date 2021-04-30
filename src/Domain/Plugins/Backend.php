<?php

namespace App\Domain\Plugins;

use App\Commands\ScrapeOpenshiftCommand;

class Backend extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'backend';
  }

  /**
   * @inheritDoc
   */
  public function analyseData() {
    $backend = '';
    $isLagoon = FALSE;
    $isPantheon = FALSE;
    $isAcquiaCloud = FALSE;
    $isAcquiaCloudSiteFactory = FALSE;
    $isPlatformSh = FALSE;
    $lagoonCluster = '';
    $openshiftProject = '';

    // Default the backend to Server, when it is present and not empty.
    $server = $this->response->getHeaderLine('Server');
    if (!empty($server)) {
      $backend = $server;
    }

    // Test for certain headers.
    $lagoon = $this->response->getHeaderLine('X-Lagoon');
    $pantheon = $this->response->getHeaderLine('x-pantheon-styx-hostname');
    $acquia = $this->response->getHeaderLine('x-ah-environment');
    $platformSh = $this->response->getHeaderLine('x-platform-cluster');
    $openshiftError = $this->response->getHeaderLine('x-openshift-error');

    if (!empty($lagoon)) {
      $backend = 'Lagoon';
      $isLagoon = TRUE;
      $openshiftProject = $this->getNamespaceFromHeader();
      $lagoonCluster = self::workOutLagoonClusterFromHeader($lagoon);
    }
    elseif (!empty($pantheon)) {
      $backend = 'Pantheon';
      $isPantheon = TRUE;
    }
    elseif (!empty($acquia)) {
      if (preg_match('#^\d#', $acquia)) {
        $backend = 'Acquia Cloud Site Factory';
        $isAcquiaCloudSiteFactory = TRUE;
      }
      else {
        $backend = 'Acquia Cloud';
        $isAcquiaCloud = TRUE;
      }
    }
    elseif (!empty($platformSh)) {
      $backend = 'Platform.sh';
      $isPlatformSh = TRUE;
    }
    if ($openshiftError == 1) {
      $openshiftError = TRUE;
    }

    // Attempt to work out the Lagoon project from the OpenShift scrape.
    if (empty($this->openshiftProject)) {
      $projects = json_decode(file_get_contents(ScrapeOpenshiftCommand::getCacheFilename()), TRUE);
      foreach ($projects as $namespace => $project) {
        foreach ($project['routes'] as $route) {
          if ($route['host'] === $this->domain) {
            $openshiftProject = $namespace;
            $lagoonCluster = "${project['clusterName']} Lagoon";
            $isLagoon = TRUE;
            break 2;
          }
        }
      }
    }

    return [
      'backend' => $backend,
      'server' => $server,
      'isLagoon' => $isLagoon,
      'openshiftProject' => $openshiftProject,
      'lagoonCluster' => $lagoonCluster,
      'isPantheon' => $isPantheon,
      'isAcquiaCloudSiteFactory' => $isAcquiaCloudSiteFactory,
      'isAcquiaCloud' => $isAcquiaCloud,
      'isPlatformSh' => $isPlatformSh,
      'openshiftError' => $openshiftError,
    ];
  }

  /**
   * e.g. lb6827.govcms1.amazee.io>casa-master:www.casa.gov.au
   * e.g. lb2.vicsdp1.amazee.io>budget-vic-gov-au-production:www.budget.vic.gov.au
   * e.g. lb1400.bi.amazee.io>varnish-5-kwbvz-master-acrosst2d-sa-com>nginx-10-5gr5b
   *
   * @param $header
   *   The contents of the `X-Lagoon` header.
   *
   * @return string
   *   The friendly name of the Lagoon cluster.
   */
  protected static function workOutLagoonClusterFromHeader($header): string {
    if (preg_match('#\.govcms1\.amazee\.io>#', $header)) {
      return 'GovCMS Lagoon';
    }
    if (preg_match('#\.vicsdp1\.amazee\.io>#', $header)) {
      return 'Victoria SDP Lagoon';
    }
    if (preg_match('#\.bi\.amazee\.io>#', $header)) {
      return 'BI1 Lagoon';
    }
    if (preg_match('#\.ch1\.amazee\.io>#', $header)) {
      return 'CH1 Lagoon';
    }
    if (preg_match('#\.au1\.amazee\.io>#', $header)) {
      return 'AU1 Lagoon';
    }
    if (preg_match('#\.us1\.amazee\.io>#', $header)) {
      return 'US1 Lagoon';
    }

    return 'amazee.io Lagoon';
  }

}
