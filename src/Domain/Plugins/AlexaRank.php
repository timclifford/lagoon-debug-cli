<?php


namespace App\Domain\Plugins;


class AlexaRank extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'alexa';
  }

  /**
   * @inheritDoc
   */
  public function analyseData() {
    $totalRank = -1;
    $country = '';
    $countryRank = -1;

    // Simple response caching.
    $cacheFile = $this->getCacheFilename();
    if (!file_exists($cacheFile)) {
      $response = $this->httpClient->request('GET', "https://www.alexa.com/siteinfo/{$this->registrableDomain}");
      file_put_contents($cacheFile, (string) $response->getBody());
    }
    $body = file_get_contents($cacheFile);

    // <span class="hash">#</span> 51,516
    preg_match('#<span class="hash">\#</span> ([\d,]+)#', $body, $matches);
    if (isset($matches[1])) {
      $totalRank = (int) str_replace(',', '', $matches[1]);
    }

    // 🇳🇿 New Zealand <span class="pull-right">#102</span></li>
    preg_match('#([a-zA-Z ]+) <span class="pull-right">\#([\d,]+)</span></li>#', $body, $matches);
    if (isset($matches[1]) && isset($matches[2])) {
      $country = trim($matches[1]);
      $countryRank = (int) str_replace(',', '', $matches[2]);
    }

    return [
      'totalRank' => $totalRank,
      'countryRank' => [
        'country' => $country,
        'rank' => $countryRank,
      ],
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
    return getenv('TEMP_FOLDER') . "/alexa-{$this->registrableDomain}-{$now->getTimestamp()}.html";
  }

}
