<?php

namespace App\Domain\Plugins\Edge;

class EdgeMatch {

  private string $name = '';
  private bool $hasEdge = FALSE;
  private bool $isOps = FALSE;

  /**
   * Edge constructor.
   *
   * @param string $name
   * @param bool $hasEdge
   * @param bool $isOps
   */
  public function __construct(string $name, bool $hasEdge = FALSE, bool $isOps = FALSE) {
    $this->name = $name;
    $this->hasEdge = $hasEdge;
    $this->isOps = $isOps;
  }

  public function getName(): string {
    return $this->name;
  }

  public function hasEdge(): bool {
    return $this->hasEdge;
  }

  public function isOps(): bool {
    return $this->isOps;
  }

  public function isUnknown(): bool {
    return preg_match('#^Unknown#', $this->name);
  }

}
