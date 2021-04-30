<?php

namespace App\Domain\Plugins;

class SecurityHeaders extends BasePlugin {

  /**
   * @inheritDoc
   */
  public function getMachineName() {
    return 'securityHeaders';
  }

  /**
   * @inheritDoc
   */
  public function analyseData() {
    $security = [];
    $csp = $this->response->getHeaderLine('Content-Security-Policy');
    $security['Content-Security-Policy'] = !empty($csp) ? $csp : '';

    $xss = $this->response->getHeaderLine('X-XSS-Protection');
    $security['X-XSS-Protection'] = ($xss === '1; mode=block') ? $xss : '';

    $hsts = $this->response->getHeaderLine('Strict-Transport-Security');
    $security['Strict-Transport-Security'] = !empty($hsts) ? $hsts : '';

    $xfo = $this->response->getHeaderLine('X-Frame-Options');
    $security['X-Frame-Options'] = !empty($xfo) ? $xfo : '';

    $xcto = $this->response->getHeader('X-Content-Type-Options');
    if (count($xcto) > 0) {
      $security['X-Content-Type-Options'] = ($xcto[0] === 'nosniff') ? $xcto[0] : '';
    }
    else {
      $security['X-Content-Type-Options'] = '';
    }

    $rf = $this->response->getHeaderLine('Referrer-Policy');
    $security['Referrer-Policy'] = !empty($rf) ? $rf : '';

    $fp = $this->response->getHeaderLine('Feature-Policy');
    $security['Feature-Policy'] = !empty($fp) ? $fp : '';

    return $security;
  }

}
