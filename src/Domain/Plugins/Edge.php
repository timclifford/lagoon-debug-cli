<?php

namespace App\Domain\Plugins;

use App\Domain\DomainDetails;
use App\Domain\Plugins\Edge\EdgeMatch;
use Psr\Http\Message\ResponseInterface;

class Edge extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'edge';
  }

  /**
   * @inheritDoc
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Exception
   */
  public function analyseData() {
    // Stub for edit domains for GovCMS to speed things up.
    if (preg_match('#(?!www)\.govcms\.gov\.au$|\.govcms\.amazee\.io$#', $this->domain)) {
      return [
        'isOps' => TRUE,
        'hasEdge' => TRUE,
        'ipv4Text' => 'GovCMS Origin Protection System (OPS)',
        'ipv6Valid' => TRUE,
        'ipv6Text' => 'GovCMS Origin Protection System (OPS)',
      ];
    }

    // Check for missing records and query for them.
    if (is_null($this->ipv4Records)) {
      $this->ipv4Records = DomainDetails::getDnsRecords($this->domain, 'CNAME');
    }
    if (is_null($this->ipv6Records)) {
      $this->ipv6Records = DomainDetails::getDnsRecords($this->domain, 'AAAA');
    }

    // IPv4.
    $ipV4EdgeMatch = $this->analyseFirstRecord($this->ipv4Records);

    // Attempt to match some CDNs that utilise other cloud services.
    if ($ipV4EdgeMatch->isUnknown() && !is_null($this->response)) {
      $ipV4EdgeMatch = $this->identifyHeaders($this->response);
    }

    // IPv6.
    $ipV6EdgeMatch = $this->analyseFirstRecord($this->ipv6Records);
    $ipv6Valid = $this->isIpv6Valid($this->ipv6Records);

    return [
      'isOps' => $ipV4EdgeMatch->isOps(),
      'hasEdge' => $ipV4EdgeMatch->hasEdge(),
      'ipv4Text' => $ipV4EdgeMatch->getName(),
      'ipv6Valid' => $ipv6Valid,
      'ipv6Text' => $ipv6Valid ? $ipV6EdgeMatch->getName() : 'No AAAA record',
    ];
  }

  /**
   * Ensure there is at least 1 valid AAAA record.
   *
   * @param $records
   *
   * @return bool
   */
  private function isIpv6Valid($records):bool {
    if (empty($records)) {
      return FALSE;
    }
    foreach ($records as $record) {
      if (filter_var($record, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Analyse the first record in a stack. If this fails, the next record is
   * attempted to be matched as so forth.
   *
   * @param array $records
   *
   * @return EdgeMatch
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function analyseFirstRecord(array $records):EdgeMatch {
    // Remove the first record from the list.
    $firstRecord = array_shift($records);
    if (is_null($firstRecord)) {
      return new EdgeMatch('Unknown');
    }

    // If IP, no recursion.
    if (filter_var($firstRecord, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      $edgeMatch = $this->identifyIp($firstRecord);
    }
    // CNAME resolution. Potential to recurse.
    else {
      $edgeMatch = $this->identifyRecord($firstRecord);
      if ($edgeMatch->isUnknown()) {
        $edgeMatch = $this->analyseFirstRecord($records);
      }
    }

    return $edgeMatch;
  }

  /**
   * Attempt to work out the edge based on the HTTP response headers.
   *
   * @param ResponseInterface $response
   *
   * @return EdgeMatch
   */
  private function identifyHeaders(ResponseInterface $response):EdgeMatch {
    if ($this->response->getHeaderLine('section-io-id')) {
      return new EdgeMatch('Section.io (via unicast)', TRUE);
    }
    if ($this->response->getHeaderLine('x-sucuri-id')) {
      return new EdgeMatch('Sucuri', TRUE);
    }
    if ($this->response->getHeaderLine('x-wix-request-id')) {
      return new EdgeMatch('Wix', TRUE);
    }

    return new EdgeMatch('Unknown');
  }

  /**
   * Identify where an alias points (e.g. a CNAME).
   *
   * @param string $record
   *   The CNAME.
   *
   * @return EdgeMatch
   *   The human readable version of where this record points.
   */
  private function identifyRecord(string $record):EdgeMatch {
    if (in_array($record, [
      'cdn.amazee.io.',
      'amazeeio.map.fastly.net.',
    ])) {
      if (preg_match('#((?!www)\.govcms\.gov\.au|\.govcms\.amazee\.io)$#', $this->domain)) {
        return new EdgeMatch('GovCMS Origin Protection System (OPS)', TRUE, TRUE);
      }
      else {
        return new EdgeMatch('amazee.io CDN', TRUE);
      }
    }
    if (in_array($record, [
      'govcmshosting.govcms.gov.au.',
      'nlb-openshift-router-f85d6d5b67bd33e7.elb.ap-southeast-2.amazonaws.com.',
    ])) {
      return new EdgeMatch('GovCMS AWS origin');
    }
    if (in_array($record, [
        'cdn.govcms.gov.au.',
        'paascdn.govcms.gov.au.',
        'seccdn.govcms.gov.au.',
      ]) || preg_match('#\.(akamaiedge|edgekey)\.net\.$#', $record)) {
      return new EdgeMatch('GovCMS Akamai', TRUE);
    }
    if (preg_match('#\.section\.io\.$#', $record)) {
      return new EdgeMatch('Section.io (via anycast)', TRUE);
    }
    if (preg_match('#\.trafficmanager\.net\.$#', $record)) {
      return new EdgeMatch('Azure', TRUE);
    }
    if (preg_match('#\.fastly\.net\.$#', $record)) {
      return new EdgeMatch('Fastly', TRUE);
    }
    if (preg_match('#\.cloudfront\.net\.$#', $record)) {
      return new EdgeMatch('Cloudfront', TRUE);
    }
    if (preg_match('#\.cloudflare\.net\.$#', $record)) {
      return new EdgeMatch('Cloudflare', TRUE);
    }
    if (preg_match('#\.incapdns\.net\.$#', $record)) {
      return new EdgeMatch('Incapsula Imperva', TRUE);
    }
    if (preg_match('#\.adobecqms\.net\.$#', $record)) {
      return new EdgeMatch('Adobe Experience Manager CDN', TRUE);
    }
    if (preg_match('#\.red-shield\.net\.$#', $record)) {
      return new EdgeMatch('RedShield', TRUE);
    }
    if (preg_match('#\.cdn77\.org\.$#', $record)) {
      return new EdgeMatch('CDN77', TRUE);
    }
    # amazee.io clusters.
    if (in_array($record, [
      'au.amazee.io.',
      'openshift-router-nlb-3cbe03ab403ff943.elb.ap-southeast-2.amazonaws.com.',
    ])) {
      return new EdgeMatch('amazee.io AU1 direct');
    }
    if (in_array($record, [
      'au2.amazee.io.',
    ])) {
      return new EdgeMatch('amazee.io AU2 direct');
    }
    if (in_array($record, [
      'sdp1.amazee.io.',
    ])) {
      return new EdgeMatch('amazee.io SDP1 direct');
    }
    if (in_array($record, [
      'us.amazee.io.',
      'openshift-tcp-lb-759fb7958b9a9577.elb.us-east-1.amazonaws.com.',
    ])) {
      return new EdgeMatch('amazee.io US1 direct');
    }
    if (in_array($record, [
      'ch.amazee.io.',
      'lagoon.ch.amazee.io.',
    ])) {
      return new EdgeMatch('amazee.io CH1 direct');
    }
    if (in_array($record, [
      'bi.amazee.io.',
      'lb.lagoon.bi.amazee.io.',
      'openshift-tcp-nlb-routers-625ef6439c062fba.elb.eu-west-1.amazonaws.com.',
    ])) {
      return new EdgeMatch('amazee.io BI1 direct');
    }
    if (in_array($record, [
      'de3.amazee.io.',
      'amazeeio-de3-default-ingress-e39ddc06000178cb.elb.eu-central-1.amazonaws.com.',
    ])) {
      return new EdgeMatch('amazee.io DE3 direct');
    }
    if (in_array($record, [
      'dh1.amazee.io.',
      'openshift-router-nlb-3cbe03ab403ff943.elb.ap-southeast-2.amazonaws.com.',
    ])) {
      return new EdgeMatch('amazee.io DH1 direct');
    }
    if (in_array($record, [
      'sdp1.amazee.io.',
    ])) {
      return new EdgeMatch('amazee.io SDP1 direct');
    }

    // Default.
    return new EdgeMatch("Unknown ($record)");
  }

  /**
   * Attempt to work out where an IP is pointed.
   *
   * @param string $record
   *   IPv4 address.
   *
   * @return EdgeMatch
   *   Human readable version of the IP.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function identifyIp(string $record):EdgeMatch {
    if ($record === '103.29.195.64') {
      return new EdgeMatch('GovCMS HTTPS redirect service');
    }
    if ($record === '103.29.195.62') {
      return new EdgeMatch('GovCMS HTTP redirect service');
    }
    if ($record === '165.12.220.124') {
      return new EdgeMatch('Department of Education Skills and Employment redirect service');
    }
    if (in_array($record, [
      '151.101.2.191',
      '151.101.66.191',
      '151.101.130.191',
      '151.101.194.191',
      ])) {
      return new EdgeMatch('GovCMS Origin Protection System (OPS)', TRUE, TRUE);
    }
    if (in_array($record, [
      '13.210.235.255',
      '52.63.199.242',
      '52.65.130.121',
      ])) {
      return new EdgeMatch('GovCMS AWS origin');
    }
    if ($record === '13.55.153.74') {
      return new EdgeMatch('amazee.io AU1 direct');
    }
    if (in_array($record, [
      '13.236.53.245',
      '54.252.24.130',
      '54.253.29.221',
    ])) {
      return new EdgeMatch('amazee.io AU2 direct');
    }
    if ($record === '20.193.15.132') {
      return new EdgeMatch('amazee.io SDP1 direct');
    }
    if ($record === '34.237.122.21') {
      return new EdgeMatch('amazee.io US1 direct');
    }
    if (in_array($record, [
      '34.206.115.145',
      '52.203.73.2',
      '54.90.185.146',
    ])) {
      return new EdgeMatch('amazee.io US2 direct');
    }
    if (in_array($record, [
      '5.102.151.21',
      '5.102.151.53',
    ])) {
      return new EdgeMatch('amazee.io CH1 direct');
    }
    if (in_array($record, [
      '52.50.94.28',
      '52.212.200.219',
      '18.203.240.52',
    ])) {
      return new EdgeMatch('amazee.io BI1 direct');
    }
    if (in_array($record, [
      '51.107.70.55',
    ])) {
      return new EdgeMatch('amazee.io CH2 direct');
    }
    if (in_array($record, [
      '34.65.14.103',
    ])) {
      return new EdgeMatch('amazee.io CH3 direct');
    }
    if (in_array($record, [
      '18.194.225.1',
      '3.125.161.174',
      '3.125.200.158',
    ])) {
      return new EdgeMatch('amazee.io DE3 direct');
    }
    if (in_array($record, [
      '20.193.15.132',
    ])) {
      return new EdgeMatch('amazee.io SDP1 direct');
    }

    // IP ranges.
    if ($this->isCloudflare($record)) {
      return new EdgeMatch('Cloudflare', TRUE);
    }
    if ($this->isFastly($record)) {
      return new EdgeMatch('Fastly', TRUE);
    }
    if ($this->isAmazon($record, 'CLOUDFRONT')) {
      return new EdgeMatch('Cloudfront', TRUE);
    }
    if ($this->isAkamai($record)) {
      return new EdgeMatch('Akamai', TRUE);
    }
    if ($this->isAmazon($record, 'EC2')) {
      return new EdgeMatch('Amazon Web Services EC2');
    }
    if ($this->isAmazon($record, 'S3')) {
      return new EdgeMatch('Amazon Web Services S3');
    }
    if ($this->isAmazon($record, 'AMAZON')) {
      return new EdgeMatch('Amazon Web Services');
    }
    if ($this->isIncapsula($record)) {
      return new EdgeMatch('Incapsula Imperva', TRUE);
    }
    if ($this->isAzure($record, 'AzureFrontDoor.Frontend')) {
      return new EdgeMatch('Azure Front Door', TRUE);
    }
    if ($this->isAzure($record, 'AzureFrontDoor.Backend')) {
      return new EdgeMatch('Azure Front Door', TRUE);
    }

    // CIDR.
    if ($this->isIpInRange($record, '76.76.21.0/24')) {
      return new EdgeMatch('Vercel');
    }
    if ($this->isIpInRange($record, '122.201.64.0/19')) {
      return new EdgeMatch('Vodien Australia');
    }
    if ($this->isIpInRange($record, '141.243.20.0/24')) {
      return new EdgeMatch('Department of Environment and Climate Change');
    }
    if ($this->isIpInRange($record, '23.215.224.0/20')) {
      return new EdgeMatch('GovCMS Akamai');
    }
    if ($this->isIpInRange($record, '104.100.0.0/20')) {
      return new EdgeMatch('GovCMS Akamai');
    }
    if ($this->isIpInRange($record, '175.106.28.0/22')) {
      return new EdgeMatch('Australian Taxation Office');
    }
    if ($this->isIpInRange($record, '150.101.0.0/16')) {
      return new EdgeMatch('Internode Australia');
    }
    if ($this->isIpInRange($record, '103.18.109.0/24')) {
      return new EdgeMatch('Net Virtue Australia');
    }
    if ($this->isIpInRange($record, '180.235.128.0/22')) {
      return new EdgeMatch('NetRegistry Australia');
    }
    if ($this->isIpInRange($record, '202.124.240.0/21')) {
      return new EdgeMatch('NetRegistry Australia');
    }
    if ($this->isIpInRange($record, '203.19.117.0/24')) {
      return new EdgeMatch('Civil Aviation Safety Authority');
    }
    if ($this->isIpInRange($record, '161.146.224.0/20')) {
      return new EdgeMatch('Department of Human Services');
    }
    if ($this->isIpInRange($record, '192.199.32.0/21')) {
      return new EdgeMatch('Macquarie Telecom Australia');
    }
    if ($this->isIpInRange($record, '141.243.34.0/24')) {
      return new EdgeMatch('Office of Environment and Heritage Australia');
    }
    if ($this->isIpInRange($record, '165.12.0.0/16')) {
      return new EdgeMatch('Department of Education Skills and Employment Australia');
    }

    // Default.
    return new EdgeMatch("Unknown ($record)");
  }

}
