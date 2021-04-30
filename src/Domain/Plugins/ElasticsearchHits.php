<?php

namespace App\Domain\Plugins;

use App\Commands\ScrapeHitsCommand;

class ElasticsearchHits extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName(): string {
    return 'elasticsearchHits';
  }

  /**
   * @inheritDoc
   */
  public function analyseData(): array {
    $hits = ScrapeHitsCommand::getHits();
    if ($this->getNamespaceFromHeader()) {
      return $hits[$this->getNamespaceFromHeader()] ?? [];
    }
    return [];
  }

}
