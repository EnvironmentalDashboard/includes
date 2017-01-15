<?php
require_once 'class.Meter.php';

/**
 * Methods for constructing a gauge
 *
 * @author Tim Robert-Fitzgerald June 2016
 */
class Gauge extends Meter {

  public function __construct($db) {
    parent::__construct($db);
    $this->db = $db;
  }

  /**
   * Filters meter data arrays according to supplied parameters
   * TO BE REMOVED FOR parseGrouping
   *
   * @param $arr 2D array to filter formatted with both 'recorded' and 'value' keys
   * @param $data_interval String like [1], [2, 3, 4, 5, 6], [7] to parse
   * @param $ignore_before is the unix timestamp representing how far back the data should go
   * @return $data Filtered array
   */
  public function filterArray($arr, $data_interval, $ignore_before = 0) {
    // Parse $data_interval into the $days array
    $days = array();
    while (strpos($data_interval, '[') !== false) { // Could also be a ']'
      preg_match('#\[(.*?)\]#', $data_interval, $match);
      $replace = str_replace(' ','', $match[1]);
      array_push($days, explode(',', $replace));
      $data_interval = str_replace($match[0], '', $data_interval);
    }
    $hour = 3600; // 3600 seconds = 1 hour but this can be anything
    $now = time();
    $today = date('w', $now) + 1; // Day of the week
    foreach ($days as $key => $value) {
      if (in_array($today, $value)) {
        $index = $key;
        break;
      }
    }
    $start = $now; // Can also be in the past if more than an hour is going to be included
    $end = $now + $hour;
    $time3 = strtotime((date('Y-m-d', $now) . ' 00:00:00' ));
    $time4 = strtotime((date('Y-m-d', $now) . ' 24:00:00' ));
    $p2 = ($start - $time3) / ($time4 - $time3);
    $p3 = ($end - $time3) / ($time4 - $time3);
    $data = array();
    foreach ($arr as $data_point) {
      if ($data_point['value'] === null) {
        continue;
      }
      $time1 = strtotime((date('Y-m-d', $data_point['recorded']) . ' 00:00:00' ));
      $time2 = strtotime((date('Y-m-d', $data_point['recorded']) . ' 24:00:00' ));
      $reading = $data_point['recorded'];
      $p1 = ($reading - $time1) / ($time2 - $time1);
      // Check whether to include this day
      $day = date('w', $reading) + 1;
      // If the current day is one that we're including && it's in the data range provided && $ignore_before < $reading
      if (in_array($day, $days[$index]) && ($p2 <= $p1) && ($p1 <= $p3) && $ignore_before < $reading) {
        array_push($data, $data_point['value']);
      }
    }
    return $data;
  }

  /**
   * Finds the relative value of a gauge given its id.
   * Optionally scale the relative value between $min and $max
   * TO BE REMOVED
   */
  public function getRelativeValue($gauge_id, $min = 0, $max = 100) {
    $stmt = $this->db->prepare('SELECT meter_id, data_interval, start FROM gauges WHERE id = ?');
    $stmt->execute(array($gauge_id));
    $result = $stmt->fetch();
    $meter_id = $result['meter_id'];
    $stmt = $this->db->prepare('SELECT current FROM meters WHERE id = ?');
    $stmt->execute(array($meter_id));
    $current_reading = $stmt->fetch()['current'];
    $data = parent::getDataFromTo($meter_id, strtotime($result['start']), time());
    $data = $this->filterArray($data, $result['data_interval']);
    return parent::relativeValue($data, $current_reading, $min, $max);
  }

  

}
?>