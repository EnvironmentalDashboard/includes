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
   * Scale a percent (value which is 0-100) to a new min and max
   * @param  $pct value to scale
   * @param  $min new min of range
   * @param  $max new max of range
   */
  public function scale($pct, $min, $max) {
    return ($pct / 100) * ($max - $min) + $min;
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
    return $this->scale($relative_value, $min, $max); // Scale to $min and $max and return
  }

  /**
   * Gets the cached relative value. See ~/scripts/cron.php for more info
   * @param  [type] $meter_uuid [description]
   * @return [type]           [description]
   */
  public function relativeValueOfCachedMeter($meter_uuid, $perm = null, $min = 0, $max = 100) {
    if ($perm === null) {
      $stmt = $this->db->prepare('SELECT relative_value FROM relative_values WHERE meter_uuid = ? LIMIT 1');
      $stmt->execute(array($meter_uuid));
    } else {
      $stmt = $this->db->prepare('SELECT relative_value FROM relative_values WHERE meter_uuid = ? AND permission = ? LIMIT 1');
      $stmt->execute(array($meter_uuid, $perm));
    }
    return ($stmt->fetchColumn() / 100) * ($max - $min) + $min;
  }

  /**
   * Gets the cached relative value. See ~/scripts/cron.php for more info
   * @param  [type] $meter_id [description]
   * @return [type]           [description]
   */
  public function relativeValueOfCachedMeterById($meter_id, $min = 0, $max = 100) {
    $stmt = $this->db->prepare('SELECT relative_value FROM relative_values WHERE meter_uuid IN (SELECT bos_uuid FROM meters WHERE id = ?) LIMIT 1');
    $stmt->execute(array($meter_id));
    return ($stmt->fetchColumn() / 100) * ($max - $min) + $min;
  }

  /**
   * Updates a row in the relative_values table
   * @param  $meter_id
   * @param  $grouping Example JSON: [{"days":[1,2,3,4,5],"npoints":8},{"days":[1,7],"start":"-2 weeks"}]
   * @param  $rv_id
   * @param  $current
   */
  public function updateRelativeValueOfMeter($meter_id, $grouping, $rv_id, $current) {
    $day_of_week = date('w') + 1; // https://dev.mysql.com/doc/refman/5.5/en/date-and-time-functions.html#function_dayofweek
    foreach (json_decode($grouping, true) as $group) {
      if (in_array($day_of_week, $group['days'])) {
        if (array_key_exists('npoints', $group)) {
          $amount = intval($group['npoints']);
          $days = implode(',', array_map('intval', $group['days'])); // prevent sql injection with intval as we're concatenating directly into query
          $stmt = $this->db->prepare(
            "SELECT value FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ({$days})
            ORDER BY recorded DESC LIMIT " . $amount); // ORDER BY recorded DESC is needed because we're trying to extract the most recent $amount points
          $stmt->execute(array($meter_id, 'hour'));
          $typical = array_map('floatval', array_column($stmt->fetchAll(), 'value'));
          echo "Typical: "; var_dump($typical);
          echo "\nCurrent: {$current}\n";
          $relative_value = $this->relativeValue($typical, $current);
          echo "relative_value: {$relative_value}\n";
        } else if (array_key_exists('start', $group)) {
          $amount = strtotime($group['start']);
          if ($amount === false) {
            return;
          }
          $days = implode(',', array_map('intval', $group['days']));
          $stmt = $this->db->prepare(
            "SELECT value, recorded FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL
            AND recorded > ? AND recorded < ? AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ({$days})
            ORDER BY value ASC"); // ORDER BY value ASC is efficient here because the relativeValue() method will sort the data like this (and there's no need to sort by recorded -- the amount of data is determined by $amount, which is a unix timestamp representing when the data should start)
          $stmt->execute(array($meter_id, $amount, time(), 'hour'));
          $typical = array_map('floatval', array_column($stmt->fetchAll(), 'value'));
          echo "Typical: "; var_dump($typical);
          echo "Current: {$current}\n";
          $relative_value = $this->relativeValue($typical, $current);
          echo "relative_value: {$relative_value}\n";
        }
        $stmt = $this->db->prepare('UPDATE relative_values SET relative_value = ? WHERE id = ?');
        $stmt->execute(array(round($relative_value), $rv_id));
        break;
      }
    }
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
    return $stmt->fetchColumn();
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