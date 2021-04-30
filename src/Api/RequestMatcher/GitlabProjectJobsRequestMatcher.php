<?php

namespace App\Api\RequestMatcher;

use Kevinrob\GuzzleCache\Strategy\Delegate\RequestMatcherInterface;
use Psr\Http\Message\RequestInterface;

/**
 *
 */
class GitlabProjectJobsRequestMatcher implements RequestMatcherInterface {

  protected function isProjectRequest(RequestInterface $request): bool {
    return preg_match('#^/api/v4/projects$#', $request->getUri()->getPath());
  }

  protected function isJobsRequest(RequestInterface $request): bool {
    return preg_match('#^/api/v4/projects/\d+/jobs$#', $request->getUri()->getPath());
  }

  /**
   * @inheritDoc
   */
  public function matches(RequestInterface $request) {
    return $this->isProjectRequest($request) || $this->isJobsRequest($request);
  }

}
