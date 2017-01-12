<?php
date_default_timezone_set("America/New_York");
/**
 * For retrieving meter data from the BuildingOS API
 *
 * @author Tim Robert-Fitzgerald June 2016
 */
class Meter {

  /**
   * Pass in the database connection to have as class variable for all methods
   *
   * @param $db The database connection
   */
  public function __construct($db) {
    $this->db = $db;
  }

  /**
   * Determines the placement of the relative level indicator.
   * Scales returned number if $min/$max given
   *
   * @param $typical is an array of historical data
   * @param $current is the current value
   * @param $min value to scale to
   * @param $max value to scale to
   * @return $relative value
   */
  public function relativeValue($typical, $current, $min = 0, $max = 100) {
    $typical = array_filter($typical); // Remove null values
    array_push($typical, $current);
    sort($typical, SORT_NUMERIC);
    $index = array_search($current, $typical) + 1;
    $relative_value = (($index) / count($typical)) * 100; // Get percent (0-100)
    return ($relative_value / 100) * ($max - $min) + $min; // Scale to $min and $max and return
  }

  public function relativeValueNow($meter_id, $grouping, $from, $to, $res = 'quarterhour', $min = 0, $max = 100) {
    $sanitize = array_map('intval', $this->currentGrouping($grouping)); // map to intval to protect against SQL injection as we're concatenating this directly into the query
    $implode = implode(',', $sanitize);
    $stmt = $this->db->prepare(
            'SELECT value FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL
            AND recorded > ? AND recorded < ? AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.$implode.')
            ORDER BY value ASC');
    $stmt2 = $this->db->prepare('SELECT current FROM meters WHERE id = ?');
    $stmt->execute(array($meter_id, $from, $to, $res));
    $stmt2->execute(array($meter_id));
    $current = floatval($stmt->fetchColumn());
    $typical = array_map('floatval', array_column($stmt->fetchAll(), 'value'));
    array_push($typical, $current);
    sort($typical, SORT_NUMERIC);
    $index = array_search($current, $typical) + 1;
    $relative_value = (($index) / count($typical)) * 100; // Get percent (0-100)
    return ($relative_value / 100) * ($max - $min) + $min; // Scale to $min and $max and return
  }

  /**
   * Fetches data for a given range, determining the resolution by the amount of data requested.
   * Quarterhour resoltion is stored for meters for the previous two days, hourly resolution for two months and daily resoultion for two years
   *
   * @param $meter_id is the id of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return Multidimensional array indexed with 'value' for the reading and 'recorded' for the time the reading was recorded
   */
  public function getData($meter_id, $from, $to, $res = null) {
    if ($res === null) {
      $res = $this->pickResolution($from);
    }
    $stmt = $this->db->prepare('SELECT value, recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ? AND recorded > ? AND recorded < ?
      ORDER BY recorded ASC');
    $stmt->execute(array($meter_id, $res, $from, $to));
    return $stmt->fetchAll();
  }

  /**
   * @param  Int meter id
   * @param  Int unix timestamp
   * @param  int unix timestamp
   * @param  String grouping
   * @param  String res
   * @return Array
   */
  public function getTypicalData($meter_id, $from, $to, $grouping, $res = null) {
    $return = array();
    if ($res === null) {
      $res = $this->pickResolution($from);
    }
    foreach ($this->grouping($grouping) as $group) {
      $implode = implode(',', $group);
      $stmt = $this->db->prepare(
            'SELECT value, recorded FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL
            AND recorded > ? AND recorded < ? AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.$implode.')
            ORDER BY value ASC');
      $stmt->execute(array($meter_id, $from, $to, $res));
      $return[$implode] = $stmt->fetchAll();
    }
    return $return;
  }

  /**
   * Fetches data using a meter URL by fetching the URLs id and calling getData()
   *
   * @param $meter_url is the URL of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return Multidimensional array indexed with 'value' for the reading and 'recorded' for the time the reading was recorded
   */
  public function getDataByMeterURL($meter_url, $from, $to, $res = null) {
    $stmt = $this->db->prepare('SELECT id FROM meters WHERE url = ? LIMIT 1');
    $stmt->execute(array($meter_url));
    $meter_id = $stmt->fetch()['id'];
    return $this->getData($meter_id, $from, $to, $res);
  }
  /**
   * Fetches data using a meter UUID by fetching the id and calling getData()
   *
   * @param $uuid is the UUID of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return Multidimensional array indexed with 'value' for the reading and 'recorded' for the time the reading was recorded
   */
  public function getDataByUUID($uuid, $from, $to, $res = null) {
    $stmt = $this->db->prepare('SELECT id FROM meters WHERE bos_uuid = ? LIMIT 1');
    $stmt->execute(array($uuid));
    $meter_id = $stmt->fetch()['id'];
    return $this->getData($meter_id, $from, $to, $res);
  }

  /**
   * Like getData(), but changes resolution to 24hrs
   *
   * @param $meter_id is the id of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return see above
   */
  public function getDailyData($meter_id, $from, $to) {
    $stmt = $this->db->prepare('SELECT value, recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ? AND recorded > ? AND recorded < ?
      ORDER BY recorded ASC');
    $stmt->execute(array($meter_id, 'hour', $from, $to));
    $return = array();
    $once = 0;
    foreach ($stmt->fetchAll() as $row) {
      if ($once === 0) {
        $once++;
        $day = date('w', $row['recorded']);
        $buffer = array($row['value']);
        $recorded = $row['recorded'];
      }
      else {
        if (date('w', $row['recorded']) !== $day) {
          $return[] = array('value' => (array_sum($buffer)/count($buffer)),
                            'recorded' => mktime(11, 0, 0, date('n', $recorded), date('j', $recorded), date('Y', $recorded)));
          $recorded = $row['recorded'];
          $day = date('w', $row['recorded']);
          $buffer = array($row['value']);
        }
        else {
          $buffer[] = $row['value'];
          $day = date('w', $row['recorded']);
        }
      }
    }
    if (count($buffer) > 0) {
      $return[] = array('value' => (array_sum($buffer)/count($buffer)), 'recorded' => mktime(11, 0, 0, date('n', $recorded), date('j', $recorded), date('Y', $recorded)));
    }
    return $return;
  }

  /**
   * @param  String grouping like [1,2,3,4,5,6,7]
   * @return Array of days
   */
  private function currentGrouping($grouping) {
    $day = date('w')+1;
    while (strpos($grouping, '[') !== false) { // Could also be a ']'
      preg_match('#\[(.*?)\]#', $grouping, $match);
      $replace = str_replace(' ','', $match[1]);
      $days = explode(',', $replace);
      if (in_array($day, $days)) {
        return $days;
      }
      $grouping = str_replace($match[0], '', $grouping);
    }
  }

  /**
   * @param  String grouping like [1,2,3,4,5,6,7]
   * @return Array of days
   */
  private function grouping($grouping) {
    $return = array();
    while (strpos($grouping, '[') !== false) { // Could also be a ']'
      preg_match('#\[(.*?)\]#', $grouping, $match);
      $replace = str_replace(' ','', $match[1]);
      $days = explode(',', $replace);
      $return[] = $days;
      $grouping = str_replace($match[0], '', $grouping);
    }
    return $return;
  }

  /**
   * Picks the resolution based on what is stored in the database
   *
   * @param $from How far back should the data go? (is a unix timestamp)
   * @return resolution string
   */
  private function pickResolution($from) {
    if ($from >= strtotime('-2 hours')) {
      return 'live';
    }
    elseif ($from >= strtotime('-2 weeks')) {
      return 'quarterhour';
    }
    elseif ($from >= strtotime('-2 months')) {
      return 'hour';
    }
    else {
      return 'month';
    }
  }
  
}

// require 'db.php';
// echo '<pre>';
// $m = new Meter($db);
// print_r($m->getData(3, strtotime('-1 hour'), time()));
// print_r($m->getData(2, strtotime('-1 day'), time()));
?>