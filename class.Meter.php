<?php
date_default_timezone_set("America/New_York");
/**
 * For retrieving meter data from the database
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
   * Defines what 'relative value' is
   * Note: null values should be removed from $typical array beforehand as they will be interpreted as '0' which is not necessarily right
   *
   * @param $typical is an array of historical data
   * @param $current is the current value
   * @param $min value to scale to
   * @param $max value to scale to
   * @return $relative value
   */
  public function relativeValue($typical, $current, $min = 0, $max = 100) {
    array_push($typical, $current);
    sort($typical, SORT_NUMERIC);
    $index = array_search($current, $typical) + 1;
    $relative_value = (($index) / count($typical)) * 100; // Get percent (0-100)
    return ($relative_value / 100) * ($max - $min) + $min; // Scale to $min and $max and return
  }

  /**
   * Returns the relative value of a meters data
   * @param  [type]  $meter_id [description]
   * @param  [type]  $grouping [description]
   * @param  [type]  $from     [description]
   * @param  [type]  $to       [description]
   * @param  string  $res      [description]
   * @param  integer $min      [description]
   * @param  integer $max      [description]
   * @return Array
   */
  public function relativeValueOfMeterFromTo($meter_id, $grouping, $from, $to, $res = null, $min = 0, $max = 100) {
    if ($res === null) {
      $res = $this->pickResolution($from);
    }
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
    $current = floatval($stmt2->fetchColumn());
    $typical = array_map('floatval', array_column($stmt->fetchAll(), 'value'));
    return $this->relativeValue($typical, $current, $min, $max);
  }

  /**
   * Returns the relative value of a meters data
   * @param  [type]  $meter_id [description]
   * @param  [type]  $grouping [description]
   * @param  [type]  $npoints  [description]
   * @param  string  $res      Should not change from 'hour' unless you want to take multiple data points from the same hour
   * @param  integer $min      [description]
   * @param  integer $max      [description]
   * @return [type]            [description]
   */
  public function relativeValueOfMeterWithPoints($meter_id, $grouping, $npoints, $res = 'hour', $min = 0, $max = 100) {
    $sanitize = array_map('intval', $this->currentGrouping($grouping)); // map to intval to protect against SQL injection as we're concatenating this directly into the query
    $implode = implode(',', $sanitize);
    $stmt = $this->db->prepare(
            'SELECT value FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.$implode.')
            ORDER BY recorded DESC LIMIT ' . intval($npoints));
    $stmt2 = $this->db->prepare('SELECT current FROM meters WHERE id = ?');
    $stmt->execute(array($meter_id, $res));
    $stmt2->execute(array($meter_id));
    $current = floatval($stmt2->fetchColumn());
    $typical = array_map('floatval', array_column($stmt->fetchAll(), 'value'));
    return $this->relativeValue($typical, $current, $min, $max);
  }

  /**
   * Fetches data for a given range, determining the resolution by the amount of data requested.
   *
   * @param $meter_id is the id of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return Multidimensional array indexed with 'value' for the reading and 'recorded' for the time the reading was recorded
   */
  public function getDataFromTo($meter_id, $from, $to, $res = null) {
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
   * Fetches data returning a specified number of records
   * @param  [type] $meter_id [description]
   * @param  [type] $limit    [description]
   * @param  string $res      Should not change from 'hour' unless you want to take multiple data points from the same hour
   * @return [type]           [description]
   */
  public function getDataWithPoints($meter_id, $limit, $res = 'hour') {
    $limit = intval($limit);
    $stmt = $this->db->prepare('SELECT * FROM (
      SELECT value, recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ?
      ORDER BY recorded DESC LIMIT '.$limit.')
      AS T1 ORDER BY recorded ASC');
    $stmt->execute(array($meter_id, $res));
    return $stmt->fetchAll();
  }

  /**
   * Fetches 'typical' data defined by the day groupings (e.g. [1,7],[2,3,4,5,6]) and a start and stop time
   * 
   * @param  Int meter id
   * @param  Int unix timestamp
   * @param  int unix timestamp
   * @param  String grouping
   * @param  String res Resoultion of data but not required
   * @return Array
   */
  public function getTypicalDataFromTo($meter_id, $from, $to, $grouping, $res = null) {
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
   * Fetches 'typical' data defined by the day groupings and the number of points to use.
   * The resolution of the data can not be automatically chosen this way of getting the data so the resolution defaults to hour
   * Note: Whether or not there is enough data in the database needs to checked before calling this function; all the available data will be returned for the given grouping, but it is not guaranteed you get the amount of data back that you ask for
   * @param  Int $meter_id
   * @param  Int $npoints Number of points to go back
   * @param  String $grouping
   * @param  String $res
   * @return Array
   */
  public function getTypicalDataWithPoints($meter_id, $npoints, $grouping, $res = 'hour') {
    $return = array();
    $npoints = intval($npoints);
    foreach ($this->grouping($grouping) as $group) {
      $implode = implode(',', $group);
      $stmt = $this->db->prepare(
            'SELECT value, recorded FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL
            AND recorded > ? AND recorded < ? AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.$implode.')
            ORDER BY recorded DESC LIMIT ' . $npoints);
      $stmt->execute(array($meter_id, $res));
      $return[$implode] = $stmt->fetchAll();
    }
    return $return;
  }

  /**
   * Fetches data using a meter URL by fetching the URLs id and calling getDataFromTo()
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
    return $this->getDataFromTo($meter_id, $from, $to, $res);
  }
  /**
   * Fetches data using a meter UUID by fetching the id and calling getDataFromTo()
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
    return $this->getDataFromTo($meter_id, $from, $to, $res);
  }

  /**
   * [UUIDtoID description]
   * @param [type] $uuid [description]
   */
  public function UUIDtoID($uuid) {
    $stmt = $this->db->prepare('SELECT id FROM meters WHERE bos_uuid = ? LIMIT 1');
    $stmt->execute(array($uuid));
    $meter_id = $stmt->fetch()['id'];
    return $this->getDataFromTo($meter_id, $from, $to, $res);
  }

  /**
   * Like getDataFromTo(), but changes resolution to 24hrs
   *
   * @param $meter_id is the id of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return see above
   */
  public function getDailyData($meter_id, $from, $to) {
    $stmt = $this->db->prepare('SELECT value, recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ? AND recorded > ? AND recorded < ?
      ORDER BY recorded DESC');
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
   * @param Int $meter_id
   * @return Int unix timestamp
   */
  public function lastUpdated($meter_id) {
    $stmt = $this->db->prepare('SELECT last_updated FROM meters WHERE id = ?');
    $stmt->execute(array($meter_id));
    return $stmt->fetch()['last_updated'];
  }

  /**
   * Gets units for meter
   * @param  Int $meter_id
   * @return String units
   */
  public function getUnits($meter_id) {
    $stmt = $this->db->prepare('SELECT units FROM meters WHERE id = ? LIMIT 1');
    $stmt->execute(array($meter_id));
    $result = $stmt->fetch();
    return $result['units'];
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
// print_r($m->getDataFromTo(3, strtotime('-1 hour'), time()));
// print_r($m->getDataFromTo(2, strtotime('-1 day'), time()));
?>