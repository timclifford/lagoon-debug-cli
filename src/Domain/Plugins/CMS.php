<?php

namespace App\Domain\Plugins;

class CMS extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'cms';
  }

  /**
   * Take a guess at the CMS based on the homepage response.
   *
   * <meta name="generator" content="Drupal 7 (http://drupal.org) + govCMS (http://govcms.gov.au)" />
   *
   * @inheritDoc
   */
  public function analyseData() {
    $body = (string) $this->response->getBody();

    // Lowercase the metatag names for consistent matching.
    $metaTags = array_change_key_case($this->metatags, CASE_LOWER);
    if (isset($metaTags['generator'])) {
      switch (strtolower($metaTags['generator'])) {
        case 'drupal 7 (http://drupal.org) + govcms (http://govcms.gov.au)':
          return 'Drupal 7 (GovCMS)';
        case 'drupal 8 (http://drupal.org) + govcms (http://govcms.gov.au)':
          return 'Drupal 8 (GovCMS)';
        case 'drupal 7 (https://www.drupal.org)':
        case 'drupal 7 (http://drupal.org)':
          return 'Drupal 7';
        case 'drupal 8 (https://www.drupal.org)':
        case 'drupal 8 (http://drupal.org)':
          // Basic matching for District CMS.
          if (preg_match('#,district_base\\\/#', $body)) {
            return 'Drupal 8 (District CMS)';
          }
          return 'Drupal 8';
        case 'drupal 9 (https://www.drupal.org)':
          return 'Drupal 9';
      }

      return ucwords($metaTags['generator']);
    }

    // Drupal 8 or 9 has
    // data-drupal-selector="drupal-settings-json"
    // Drupal 7 has
    // jQuery.extend(Drupal.settings
    if (preg_match('#jQuery\.extend\(Drupal\.settings#', $body)) {
      return 'Drupal 7';
    }
    if (preg_match('#data-drupal-selector="drupal-settings-json"#', $body)) {
      return 'Drupal 8 or 9';
    }

    // Test for certain cookies.
    $cookies = $this->response->getHeader('set-cookie');
    foreach ($cookies as $cookie) {
      if (preg_match('#^SC_ANALYTICS_GLOBAL_COOKIE#', $cookie)) {
        return 'Sitecore';
      }
    }

    return 'Unknown';
  }

}