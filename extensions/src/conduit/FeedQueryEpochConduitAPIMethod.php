<?php

/*
 * This file is a modified copy of src/applications/feed/conduit/FeedQueryConduitAPIMethod.php
 * that was needed to give the ability to filter transactions based on epoch timestamp values.
 * This Conduit API method adds the epochStart and epochEnd parameters that can be used for
 * example, to return only transactions that occurred after a specific epoch instead of all
 * transactions. The desire is to push this minor change to upstream Phabricator to be included
 * in the upstream feed.query API method.
 */

final class FeedQueryEpochConduitAPIMethod extends FeedConduitAPIMethod {

  public function getAPIMethodName() {
    return 'feed.query_epoch';
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodDescription() {
    return pht('Query the feed for stories');
  }

  private function getDefaultLimit() {
    return 100;
  }

  protected function defineParamTypes() {
    return array(
      'filterPHIDs' => 'optional list <phid>',
      'limit' => 'optional int (default '.$this->getDefaultLimit().')',
      'after' => 'optional int',
      'before' => 'optional int',
      'view' => 'optional string (data, html, html-summary, text)',
      'epochStart' => 'optional start epoch int',
      'epochEnd' => 'optional end epoch int',
    );
  }

  private function getSupportedViewTypes() {
    return array(
      'html' => pht('Full HTML presentation of story'),
      'data' => pht('Dictionary with various data of the story'),
      'html-summary' => pht('Story contains only the title of the story'),
      'text' => pht('Simple one-line plain text representation of story'),
    );
  }

  protected function defineErrorTypes() {

    $view_types = array_keys($this->getSupportedViewTypes());
    $view_types = implode(', ', $view_types);

    return array(
      'ERR-UNKNOWN-TYPE' =>
        pht(
          'Unsupported view type, possibles are: %s',
          $view_types),
    );
  }

  protected function defineReturnType() {
    return 'nonempty dict';
  }

  protected function execute(ConduitAPIRequest $request) {
    $results = array();
    $user = $request->getUser();

    $view_type = $request->getValue('view');
    if (!$view_type) {
      $view_type = 'data';
    }

    $limit = $request->getValue('limit');
    if (!$limit) {
      $limit = $this->getDefaultLimit();
    }

    $query = id(new PhabricatorFeedQuery())
      ->setLimit($limit)
      ->setViewer($user);

    $filter_phids = $request->getValue('filterPHIDs');
    if ($filter_phids) {
      $query->withFilterPHIDs($filter_phids);
    }

    $after = $request->getValue('after');
    if (strlen($after)) {
      $query->setAfterID($after);
    }

    $before = $request->getValue('before');
    if (strlen($before)) {
      $query->setBeforeID($before);
    }

    $epoch_start = $request->getValue('epochStart');
    $epoch_end = $request->getValue('epochEnd');
    if ($epoch_start || $epoch_end) {
      $epoch_start = is_numeric($epoch_start) ? $epoch_start : null;
      $epoch_end = is_numeric($epoch_end) ? $epoch_end : null;
      $query->withEpochInRange($epoch_start, $epoch_end);
    }

    $stories = $query->execute();

    if ($stories) {
      foreach ($stories as $story) {

        $story_data = $story->getStoryData();

        $data = null;

        try {
          $view = $story->renderView();
        } catch (Exception $ex) {
          // When stories fail to render, just fail that story.
          phlog($ex);
          continue;
        }

        $view->setEpoch($story->getEpoch());
        $view->setUser($user);

        switch ($view_type) {
          case 'html':
            $data = $view->render();
          break;
          case 'html-summary':
            $data = $view->render();
          break;
          case 'data':
            $data = array(
              'class' => $story_data->getStoryType(),
              'epoch' => $story_data->getEpoch(),
              'authorPHID' => $story_data->getAuthorPHID(),
              'chronologicalKey' => $story_data->getChronologicalKey(),
              'data' => $story_data->getStoryData(),
            );
          break;
          case 'text':
            $data = array(
              'class' => $story_data->getStoryType(),
              'epoch' => $story_data->getEpoch(),
              'authorPHID' => $story_data->getAuthorPHID(),
              'chronologicalKey' => $story_data->getChronologicalKey(),
              'objectPHID' => $story->getPrimaryObjectPHID(),
              'text' => $story->renderText(),
            );
          break;
          default:
            throw new ConduitException('ERR-UNKNOWN-TYPE');
        }

        $results[$story_data->getPHID()] = $data;
      }
    }

    return $results;
  }

}
