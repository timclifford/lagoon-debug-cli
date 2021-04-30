<?php

namespace App\Domain\Plugins;

class CachingHeaders extends BasePlugin {

  /**
   * {@inheritDoc}
   */
  public function getMachineName() {
    return 'CachingHeaders';
  }

  /**
   * {@inheritDoc}
   */
  public function analyseData() {
    $cacheControl = $this->response->getHeaderLine('Cache-Control');
    $surrogateControl = $this->response->getHeaderLine('Surrogate-Control');
    $drupalPageCache = $this->response->getHeaderLine('X-Drupal-Cache');

    $browserCache = -1;
    $proxyCache = -1;
    $browserCacheable = FALSE;
    $proxyCacheable = FALSE;

    // max-age=600, public, s-maxage=2764800
    if (!empty($cacheControl)) {
      $headers = $this->parseCacheControl($cacheControl);
      if (isset($headers['max-age'])) {
        $browserCache = (int) $headers['max-age'];
        $proxyCache = (int) $headers['max-age'];
      }
      if (isset($headers['s-maxage'])) {
        $proxyCache = (int) $headers['s-maxage'];
      }
      if (isset($headers['public'])) {
        $browserCacheable = TRUE;
        $proxyCacheable = TRUE;
      }
    }

    // max-age=2764800, public, stale-while-revalidate=3600, stale-if-error=3600
    if (!empty($surrogateControl)) {
      $headers = $this->parseCacheControl($surrogateControl);
      if (isset($headers['max-age'])) {
        $proxyCache = (int) $headers['max-age'];
      }
      $proxyCacheable = isset($headers['public']);
    }

    return [
      'browserCacheable' => $browserCacheable,
      'proxyCacheable' => $proxyCacheable,
      'cacheControl' => $cacheControl,
      'surrogateControl' => $surrogateControl,
      'browserCache' => $browserCache,
      'browserCacheFriendly' => $browserCache > 0 ? self::secondsToHuman($browserCache) : '',
      'proxyCache' => $proxyCache,
      'proxyCacheFriendly' => $proxyCache > 0 ? self::secondsToHuman($proxyCache) : '',
      'drupalPageCache' => $drupalPageCache,
    ];
  }

  /**
   * Parse the cache-control string into a key value array.
   *
   * @param string $header
   *   The cache-control string.
   *
   * @return array
   *   Returns a key value array.
   */
  private function parseCacheControl($header) {
    $cacheControl = array_map('trim', explode(',', $header));
    $cacheControlParsed = array();
    foreach ($cacheControl as $value) {
      if (strpos($value, '=') !== FALSE) {
        $temp = [];
        parse_str($value, $temp);
        $cacheControlParsed += $temp;
      }
      else {
        $cacheControlParsed[$value] = TRUE;
      }
    }
    return $cacheControlParsed;
  }

}
