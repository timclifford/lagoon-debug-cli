<?php

namespace App\Api\Gitlab;

class Job {

  private int $duration;
  private \DateTime $created;

  /**
   * Job constructor.
   */
  public function __construct(array $job) {
    $this->duration = (int) $job['duration'];
    $this->created = new \DateTime($job['created_at'], new \DateTimeZone('Australia/Sydney'));
  }

  public function getCreatedMonth():string {
    return $this->created->format('Y-m');
  }

  public function getDuration():int {
    return $this->duration;
  }

  public function toArray():array {
    return [
      'created' => $this->created->getTimestamp(),
      'duration' => $this->duration,
    ];
  }

}
