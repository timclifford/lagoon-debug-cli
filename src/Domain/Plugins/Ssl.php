<?php


namespace App\Domain\Plugins;

use Spatie\SslCertificate\SslCertificate;

class Ssl extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'ssl';
  }

  /**
   * @inheritDoc
   */
  public function analyseData() {
    try {
      $certificate = SslCertificate::createForHostName($this->domain, 5);
    }
    catch (\Exception $e) {
      return [];
    }
    return [
      'daysUntilExpirationDate' => $certificate->daysUntilExpirationDate(),
      'domains' => $certificate->getDomains(),
      'expirationDate' => $certificate->expirationDate()->format('Y-m-d'),
      'fingerprint' => $certificate->getFingerprint(),
      'issuer' => $this->getFriendlyIssuer($certificate->getIssuer()),
      'isValid' => $certificate->isValid(),
      'lifespanInDays' => $certificate->lifespanInDays(),
      'organization' => $certificate->getOrganization(),
      'signatureAlgorithm' => $certificate->getSignatureAlgorithm(),
      'usesSha1Hash' => $certificate->usesSha1Hash(),
      'validFromDate' => $certificate->validFromDate()->format('Y-m-d'),
    ];
  }

  /**
   * Normalise the Issuer for display. Lets Encrypt changed it's CA on the
   * 2020-12-02 to `R3` https://letsencrypt.org/certificates/ and this is not
   * very descriptive to the average user.
   *
   * @param string $issuer
   *
   * @return string
   */
  private function getFriendlyIssuer(string $issuer): string {
    switch ($issuer) {
      case 'R3':
        return "Let's Encrypt R3";
      case 'R4':
        return "Let's Encrypt R4 (backup)";
      case 'E1':
        return "Let's Encrypt E1";
      case 'E2':
        return "Let's Encrypt E2 (backup)";
    }
    return $issuer;
  }

}
