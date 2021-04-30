<?php


namespace App\Domain\Plugins;


use App\Domain\DomainDetails;

class Nameservers extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'nameservers';
  }

  /**
   * Find the nameservers for a given root domain
   *
   * @inheritDoc
   */
  public function analyseData() {
    if (empty($this->registrableDomain)) {
      return [
        'dnsProvider' => 'blah',
        'nameservers' => [],
      ];
    }

    $dnsProvider = '';
    $dnsToUse = DomainDetails::CLOUDFLARE_DNS;
    exec("dig @{$dnsToUse} {$this->registrableDomain} NS +short", $nameservers, $returnVar);
    sort($nameservers);

    // Attempt to work out if this nameserver belongs to a known provider.
    foreach ($nameservers as $nameserver) {
      if (preg_match('#\.ns\.cloudflare\.com\.$#', $nameserver)) {
        $dnsProvider = 'Cloudflare';
        break;
      }
      if (preg_match('#\.akam\.net\.$#', $nameserver)) {
        $dnsProvider = 'Akamai';
        break;
      }
      if (preg_match('#\.awsdns-#', $nameserver)) {
        $dnsProvider = 'Amazon Route 53';
        break;
      }
      if (preg_match('#\.azure-dns\.com\.$#', $nameserver)) {
        $dnsProvider = 'Azure';
        break;
      }
      if (preg_match('#\.sge\.net\.$#', $nameserver)) {
        $dnsProvider = 'Verizon Business';
        break;
      }
      if (preg_match('#\.chillit\.net\.au\.$#', $nameserver)) {
        $dnsProvider = 'Chill IT Australia';
        break;
      }
      if (preg_match('#\.ultradns\.#', $nameserver)) {
        $dnsProvider = 'Neustar UltraDNS';
        break;
      }
      if (preg_match('#^ns\d\.amazee\.io\.$#', $nameserver)) {
        $dnsProvider = 'amazee.io DNS';
        break;
      }
      if (preg_match('#\.domaincontrol\.com\.$#', $nameserver)) {
        $dnsProvider = 'GoDaddy DNS';
        break;
      }
      if (preg_match('#\.stabletransit\.com\.$#', $nameserver)) {
        $dnsProvider = 'Rackspace Cloud services DNS';
        break;
      }
      if (preg_match('#\.name-services\.com\.$#', $nameserver)) {
        $dnsProvider = 'enom.com DNS';
        break;
      }
      if (preg_match('#\.websitewelcome\.com\.$#', $nameserver)) {
        $dnsProvider = 'HostGator DNS';
        break;
      }
      if (preg_match('#\.yourhostingaccount\.com\.$#', $nameserver)) {
        $dnsProvider = 'Powweb.com DNS';
        break;
      }
      if (preg_match('#\.hosteurope\.com\.$#', $nameserver)) {
        $dnsProvider = 'Domainbox.com (Domainmonster & Mesh Digital) DNS';
        break;
      }
      if (preg_match('#\.(guardedhost\.com|guardeddns\.net)\.$#', $nameserver)) {
        $dnsProvider = 'AMHmhosting DNS';
        break;
      }
      if (preg_match('#\.registrar-servers\.com\.$#', $nameserver)) {
        $dnsProvider = 'NameCheap DNS';
        break;
      }
      if (preg_match('#\.privatedns\.com\.$#', $nameserver)) {
        $dnsProvider = 'MelbourneIT DNS';
        break;
      }
      if (preg_match('#\.livedns\.co\.uk\.$#', $nameserver)) {
        $dnsProvider = 'FastHosts & UKReg DNS';
        break;
      }
      if (preg_match('#\.orderbox-dns\.com\.$#', $nameserver)) {
        $dnsProvider = 'ResellerClub DNS';
        break;
      }
      if (preg_match('#\.thewebhostserver\.com\.$#', $nameserver)) {
        $dnsProvider = 'EZPZhosting DNS';
        break;
      }
      if (preg_match('#\.rzone\.de\.$#', $nameserver)) {
        $dnsProvider = 'Strato.de DNS';
        break;
      }
      if (preg_match('#\.panthur\.com\.$#', $nameserver)) {
        $dnsProvider = 'Panthur.com.au DNS';
        break;
      }
      if (preg_match('#\.tmns\.net\.au\.$|\.server-dns\.com(\.au)?\.$|\.server-dns-us\.com\.$#', $nameserver)) {
        $dnsProvider = 'Telstra/BigPond DNS';
        break;
      }
      if (preg_match('#\.bigpond\.com\.$#', $nameserver)) {
        $dnsProvider = 'BigPond Basic Hosting DNS';
        break;
      }
      if (preg_match('#\.telstra\.net\.$#', $nameserver)) {
        $dnsProvider = 'Telstra Custdata DNS';
        break;
      }
      if (preg_match('#\.secure\.net\.$#', $nameserver)) {
        $dnsProvider = 'Telstra T-Suite DNS';
        break;
      }
      if (preg_match('#\.catalyst\.net\.nz\.$#', $nameserver)) {
        $dnsProvider = 'Catalyst IT (New Zealand) DNS';
        break;
      }
    }

    return [
      'dnsProvider' => $dnsProvider,
      'nameservers' => $nameservers,
    ];
  }

}