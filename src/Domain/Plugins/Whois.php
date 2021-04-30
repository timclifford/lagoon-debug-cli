<?php

namespace App\Domain\Plugins;

use Iodev\Whois\Factory;

class Whois extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'whois';
  }

  /**
   * @inheritDoc
   * @throws \Exception
   */
  public function analyseData() {
    // Simple response caching.
    $cacheFile = $this->getCacheFilename();
    if (!file_exists($cacheFile)) {
      $whois = Factory::get()->createWhois();
      try {
        $info = $whois->loadDomainInfo($this->registrableDomain);
      }
      catch (\Exception $e) {
        return $e->getMessage();
      }
      file_put_contents($cacheFile, serialize($info));
    }
    $info = unserialize(file_get_contents($cacheFile));

    return [
      'owner' => $info->owner ?? '',
      'registrar' => $info->registrar ?? '',
      'creationDate' => $info->creationDate ?? '',
      'expirationDate' => $info->expirationDate ?? '',
      'states' => $info->states ?? '',
      'extra' => isset($info) ? $info->getExtra() : [],
    ];
  }

  /**
   * Simple caching system.
   *
   * @return string
   * @throws \Exception
   */
  private function getCacheFilename() {
    $now = new \DateTime('now', new \DateTimeZone('UTC'));
    $now->modify('first day of this month');
    $now->setTime(0, 0, 0);
    return getenv('TEMP_FOLDER') . "/whois-{$this->registrableDomain}-{$now->getTimestamp()}.html";
  }

}
