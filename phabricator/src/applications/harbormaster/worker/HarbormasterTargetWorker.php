<?php

/**
 * Execute a build target.
 */
final class HarbormasterTargetWorker extends HarbormasterWorker {

  public function getRequiredLeaseTime() {
    // This worker performs actual build work, which may involve a long wait
    // on external systems.
    return 60 * 60 * 24;
  }

  private function loadBuildTarget() {
    $data = $this->getTaskData();
    $id = idx($data, 'targetID');

    $target = id(new HarbormasterBuildTargetQuery())
      ->withIDs(array($id))
      ->setViewer($this->getViewer())
      ->executeOne();

    if (!$target) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht(
          'Bad build target ID "%d".',
          $id));
    }

    return $target;
  }

  public function doWork() {
    $target = $this->loadBuildTarget();
    $build = $target->getBuild();
    $viewer = $this->getViewer();

    try {
      $implementation = $target->getImplementation();
      if (!$implementation->validateSettings()) {
        $target->setTargetStatus(HarbormasterBuildTarget::STATUS_FAILED);
        $target->save();
      } else {
        $implementation->execute($build, $target);
        $target->setTargetStatus(HarbormasterBuildTarget::STATUS_PASSED);
        $target->save();
      }
    } catch (Exception $ex) {
      $target->setTargetStatus(HarbormasterBuildTarget::STATUS_FAILED);
      $target->save();
    }

    id(new HarbormasterBuildEngine())
      ->setViewer($viewer)
      ->setBuild($build)
      ->continueBuild();
  }

}
