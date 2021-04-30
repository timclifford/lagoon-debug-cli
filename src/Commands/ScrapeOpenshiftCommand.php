<?php

namespace App\Commands;

use App\Api\LagoonApi;
use App\Api\OpenshiftApi;
use App\Domain\DomainDetails;
use App\Domain\Plugins\Edge;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ScrapeOpenshiftCommand extends Command {

  /**
   * @var \GuzzleHttp\Client
   */
  protected Client $httpClient;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('scrape:openshift')
      ->setDescription('Scrape data from Openshift.')
      ->setHelp(
        <<<EOT
The <info>scrape:openshift</info> command will gather information about current projects, routes, pods.
EOT
      )
      ->addOption(
        'scrape-domain-info',
        's',
        InputOption::VALUE_NONE,
        'If you want to scrape the DNS information, and where the domain route to, enable this option. It will slow down the scrape by roughly 4 times.'
      )
      ->setAliases(['so']);
  }

  /**
   * {@inheritdoc}
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $io->title('Scrape Openshift');
    $this->httpClient = new Client([
      'timeout' => 5,
      'connect_timeout' => 2,
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'headers' => [
        'User-Agent' => 'govcms-debug/1.0',
        'Accept' => 'text/html',
        'Fastly-Debug' => '1',
      ],
    ]);

    $allKubernetesClusters = self::getKubernetesClusterCredentials();
    $json = [];
    foreach ($allKubernetesClusters as $kubernetesCluster) {
      $clusterName = $kubernetesCluster['name'];
      $consoleUrlPublic = $kubernetesCluster['consoleUrlPublic'];
      $kibnanaUrl = $kubernetesCluster['kibnanaUrl'];

      // Only Openshift is supported at present.
      if ($kubernetesCluster['cluster'] !== 'openshift') {
        $io->writeln(">> <fg=red>Skipping</> cluster <options=bold>{$clusterName}</> as only openshift is supported at present.");
        continue;
      }

      $io->writeln("Scraping Openshift cluster <options=bold>{$clusterName}</> [{$kubernetesCluster['consoleUrl']}].");
      $openshiftApi = OpenshiftApi::getInstance($clusterName, $kubernetesCluster['consoleUrl'], $kubernetesCluster['token']);
      $io->writeln("Running as the service account <options=bold>{$openshiftApi->whoAmI()}</>.");
      $namespaces = $openshiftApi->getAllNamespaces($kubernetesCluster['labelSelector']);

      $countNamespaces = count($namespaces);
      $currentCount = 0;
      foreach ($namespaces as $namespace) {
        $namespaceName = $namespace['metadata']['name'];
        $currentCount++;
        $io->writeln("> [<fg=green>{$currentCount}</>/<fg=green>{$countNamespaces}</>] [{$clusterName}] : Reading <options=bold>{$namespaceName}</>");

        // Skips some system namespaces.
        if (preg_match('#(^appuio-|^default$|^lagoon|^netdata$|^kube-|^gitlab-|^openshift|^syn$|^syn-|^acme-|^management-infra$|^operators$|^olm$|^mysqltuner$|^logging$|^principio-|^prometheus-|^tiller$|^yara$)#', $namespaceName)) {
          $io->writeln(">> <fg=red>Skipping</> namespace <options=bold>{$namespaceName}</> as it matches skip regex.");
          continue;
        }

        // Deal for namespaces with no labels.
        $labels = [];
        if (isset($namespace['metadata']['labels']) && !empty($namespace['metadata']['labels'])) {
          if (isset($namespace['metadata']['labels']['lagoon.sh/project']) || isset($namespace['metadata']['labels']['lagoon/project'])) {
            $project = $namespace['metadata']['labels']['lagoon.sh/project'] ?? $namespace['metadata']['labels']['lagoon/project'];
            $branch = $namespace['metadata']['labels']['lagoon.sh/environment'] ?? 'master';
          }
          else {
            preg_match('#(.+)-([a-zA-Z0-9_]+)#', $namespaceName, $matches);
            $project = $matches[1] ?? $namespaceName;
            $branch = $matches[2] ?? 'master';
          }

          // Labels - anything starting with `lagoon.sh`.
          foreach ($namespace['metadata']['labels'] as $key => $value) {
            if (preg_match('#^lagoon(\.sh)?/#', $key)) {
              $labels[$key] = $value;
            }
          }
        }
        else {
          preg_match('#(.+)-([a-zA-Z0-9_]+)#', $namespaceName, $matches);
          $project = $matches[1] ?? $namespaceName;
          $branch = $matches[2] ?? NULL;
          if (!$branch) {
            $io->writeln(">> <fg=red>Skipping namespace <options=bold>{$namespaceName}</> as there are no labels and no easy way to find the branch name.");
            continue;
          }
        }

        // Routes. We need to enhance each route with additional metadata.
        $hasExposerPods = FALSE;
        $hasMissingRoutes = FALSE;
        $hasInactiveRoutes = FALSE;
        $routes = $openshiftApi->getAllRoutesForNamespace($namespace['metadata']['name']);
        $routes = array_map(function($route) use (&$hasExposerPods, &$hasMissingRoutes, &$hasInactiveRoutes, $input) {
          $isExposerPod = FALSE;
          if (preg_match('#^exposer-#', $route['metadata']['name'])) {
            $hasExposerPods = TRUE;
            $isExposerPod = TRUE;
          }
          $tlsAcme = FALSE;
          if (isset($route['metadata']['annotations']['kubernetes.io/tls-acme']) && $route['metadata']['annotations']['kubernetes.io/tls-acme'] === 'true') {
            $tlsAcme = TRUE;
          }
          $isEditDomain = FALSE;
          if (preg_match('#(?!www)\.govcms\.gov\.au|\.amazee\.io#', $route['spec']['host'])) {
            $isEditDomain = TRUE;
          }
          $isInternalService = FALSE;
          if (preg_match('#\.svc$#', $route['spec']['host'])) {
            $isInternalService = TRUE;
          }
          $isActive = TRUE;
          if (isset($route['status']['ingress'][0]['conditions'][0]['status'])) {
            $isActive = $route['status']['ingress'][0]['conditions'][0]['status'] === 'True';
          }
          $response = [
            'name' => $route['metadata']['name'],
            'host' => $route['spec']['host'],
            'tlsAcme' => $tlsAcme,
            'isEditDomain' => $isEditDomain,
            'isInternalService' => $isInternalService,
            'isActive' => $isActive,
            'isExposerPod' => $isExposerPod,
            'isPreferredDomain' => FALSE,
          ];
          // Avoid the `dig` on some low value domains to speed up the scrape.
          $domainExists = FALSE;
          if ($isEditDomain || $isInternalService) {
            $domainExists = TRUE;
          }
          elseif (!$isExposerPod) {
            $domainExists = self::domainExists($route['spec']['host']);
            if (!$domainExists) {
              $hasMissingRoutes = TRUE;
            }
          }
          // Attempt to load more information about this domain and where it is
          // pointing. This slows down the scrape, so it is behind a flag.
          if ($domainExists && !$isInternalService && $input->getOption('scrape-domain-info')) {
            $edge = new Edge($route['spec']['host'], $this->httpClient);
            $response = array_merge($edge->analyseData(), $response);
          }
          // Add the reason why the route is not active.
          if (!$isActive) {
            $hasInactiveRoutes = TRUE;
            $response['isActiveReason'] = $route['status']['ingress'][0]['conditions'][0]['reason'];
          }
          $response['domainExists'] = $domainExists;
          return $response;
        }, $routes);

        // Find the preferred domain, and set it. The preferred route can be a
        // label on the namespace, so check that first.
        $preferredDomain = $namespace['metadata']['labels']['lagoon.sh/preferredRoute'] ?? $this->getPreferredDomain($routes);
        foreach ($routes as $key => $route) {
          if ($preferredDomain === $route['host']) {
            $routes[$key]['isPreferredDomain'] = TRUE;
            break;
          }
        }

        // Get the HPA for Nginx. Attempt to work out if it is a 'standard' HPA,
        // the 'covid' HPA, or something more custom.
        $hpas = $openshiftApi->getAllHpasForNamespace($namespace['metadata']['name']);
        $nginxHpa = [];
        foreach ($hpas as $hpa) {
          if ($hpa['metadata']['name'] === 'nginx') {
            $type = 'custom';
            if ($hpa['spec']['minReplicas'] === 2 && $hpa['spec']['maxReplicas'] === 5 && $hpa['spec']['targetCPUUtilizationPercentage'] === 8000) {
              $type = 'standard';
            }
            elseif ($hpa['spec']['minReplicas'] === 4 && $hpa['spec']['maxReplicas'] === 12 && $hpa['spec']['targetCPUUtilizationPercentage'] === 3000) {
              $type = 'covid';
            }
            $nginxHpa = [
              'type' => $type,
              'minReplicas' => $hpa['spec']['minReplicas'],
              'maxReplicas' => $hpa['spec']['maxReplicas'],
              'targetCPUUtilizationPercentage' => $hpa['spec']['targetCPUUtilizationPercentage'],
              'lastScaleTime' => $hpa['status']['lastScaleTime'] ?? NULL,
              'currentReplicas' => $hpa['status']['currentReplicas'],
              'desiredReplicas' => $hpa['status']['desiredReplicas'],
              'currentCPUUtilizationPercentage' => $hpa['status']['currentCPUUtilizationPercentage'] ?? 0,
            ];
          }
        }

        // Get the last 2 builds.
        $builds = $openshiftApi->getAllRecentBuildsForNamespace($namespace['metadata']['name']);
        $recentBuilds = [];
        foreach ($builds as $build) {
          $recentBuilds[] = [
            'created' => $build['metadata']['creationTimestamp'],
            'status' => $build['status']['phase'],
            'duration' => isset($build['status']['duration']) ? $build['status']['duration'] / 1000000000 : 0,
          ];
        }
        usort($recentBuilds, function($a, $b) {
          $createdA = new \DateTime($a['created'], new \DateTimeZone('UTC'));
          $createdB = new \DateTime($b['created'], new \DateTimeZone('UTC'));
          return $createdB <=> $createdA;
        });
        $recentBuilds = array_slice($recentBuilds,0,2);

        // Get the Lagoon metadata, set defaults if missing.
        $lagoonProject = LagoonApi::getInstance()->getLagoonProject($project);
        $metadata = isset($lagoonProject['metadata']) ? json_decode($lagoonProject['metadata'], TRUE) ?? [] : [];
        $metadata += [
          'project-status' => 'unknown',
          'govcms-type' => 'unknown',
          'govcms-version' => 'unknown',
        ];

        // Form the JSON object.
        $json[$namespace['metadata']['name']] = [
          'clusterName' => $clusterName,
          'consoleUrlPublic' => $consoleUrlPublic,
          'kibnanaUrl' => $kibnanaUrl,
          'project' => $project,
          'branch' => $branch,
          'name' => $namespace['metadata']['name'],
          'created' => $namespace['metadata']['creationTimestamp'],
          'labels' => $labels,
          'preferredDomain' => $preferredDomain,
          'environmentType' => $namespace['metadata']['labels']['lagoon.sh/environmentType'] ?? 'development',
          'hasExposerPods' => $hasExposerPods,
          'hasMissingRoutes' => $hasMissingRoutes,
          'hasInactiveRoutes' => $hasInactiveRoutes,
          'routes' => $routes,
          'nginxHpa' => $nginxHpa,
          'recentBuilds' => $recentBuilds,
          'metadata' => $metadata,
          'gitUrl' => $lagoonProject['gitUrl'] ?? NULL,
        ];
      }
    }

    $cachedFilename = self::getCacheFilename();
    file_put_contents($cachedFilename, json_encode($json, JSON_PRETTY_PRINT));

    $io->success("Successfully read {$countNamespaces} namespaces, cached to file {$cachedFilename}");
    return 0;
  }

  /**
   * Attempt to find the preferred domain. This logic more or less emulates the
   * logic in the domain mapper.
   *
   * @param $routes
   *
   * @return string
   */
  private function getPreferredDomain($routes):string {
    $preferredRoute = '';
    foreach ($routes as $route) {
      if ($route['isEditDomain']) {
        continue;
      }
      if (preg_match('#^www\.(?!beta)[a-zA-Z0-9-.]+\.gov\.au$#', $route['host'])) {
        $preferredRoute = $route['host'];
        break;
      }
      if (preg_match('#(?!beta).*\.gov\.au$#', $route['host'])) {
        $preferredRoute = $route['host'];
      }
      else if ($preferredRoute === '' && preg_match('#\.au$#', $route['host'])) {
        $preferredRoute = $route['host'];
      }
      else if ($preferredRoute === '' && preg_match('#\.(org|com)$#', $route['host'])) {
        $preferredRoute = $route['host'];
      }
    }

    return $preferredRoute;
  }

  /**
   * The file based cache of data. This is not accessible from nginx.
   *
   * @return string
   */
  public static function getCacheFilename():string {
    return getenv('TEMP_FOLDER') . "/openshift-routes.json";
  }

  /**
   * The file based cache of cluster read only credentials. This is not
   * accessible from nginx.
   *
   * @return string
   */
  public static function getClusterCredentialsFilename():string {
      return getenv('TEMP_FOLDER') . "/kubernetes-cluster-creds.json";
//    return "/app/public/assets/kubernetes-cluster-creds.json";
  }

  /**
   * Get the latest scrape data.
   *
   * @return array
   */
  public static function getProjects():array {
    return json_decode(file_get_contents(self::getCacheFilename()),TRUE);
  }

  /**
   * Get the totals of all the routes found that are active.
   *
   * @return array
   */
  public static function getRouteTotals():array {
    $routeTotals = [];
    foreach (self::getProjects() as $project) {
      foreach ($project['routes'] as $route) {
        if ($route['isExposerPod'] || !$route['domainExists'] || $route['isInternalService']) {
          continue;
        }
        $routeTotals[$route['ipv4Text']][] = $route['host'];
      }
    }
    arsort($routeTotals);
    return $routeTotals;
  }

  /**
   * Get the kubernetes cluster credentials.
   *
   * @param string $name [optional]
   *   The name of the cluster.
   *
   * @return array
   */
  public static function getKubernetesClusterCredentials(string $name = ''):array {
    $credentials = json_decode(file_get_contents(self::getClusterCredentialsFilename()),TRUE);
    if ($name) {
      foreach ($credentials as $credential) {
        if ($credential['name'] === $name) {
          return $credential;
        }
      }
      return [];
    }
    return $credentials;
  }

  /**
   * Get the last time the scraps was run.
   *
   * @return int
   */
  public static function getLastUpdated():int {
    return filemtime(self::getCacheFilename());
  }

  /**
   * @param $domain
   *
   * @return bool
   * @throws \Exception
   */
  public static function domainExists($domain):bool {
    $output = DomainDetails::getDnsRecords($domain);
    $output = trim(implode('', $output));
    return !empty($output);
  }

  /**
   * e.g. console-govcms.amazeeio.hosting
   *
   * @param $hostname
   *
   * @return string
   */
  private function getClusterFriendlyName($hostname):string {
    if (preg_match('#console-(.+)\.amazeeio\.hosting#', $hostname, $matches)) {
      return $matches[1];
    }

    return $hostname;
  }

}
