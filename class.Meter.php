<?php
date_default_timezone_set("America/New_York");
/**
 * For retrieving meter data from the database
 * updateRelativeValueOfMeter() was used by the daemons to update a meters relative value and was most of the reason for this class
 * but now that is taken care of by the C daemons
 *
 * @author Tim Robert-Fitzgerald
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
  public static function scale($pct, $min, $max) {
    return ($pct / 100) * ($max - $min) + $min;
  }

  /**
   * Scales a number from an old range to a new range
   */
  public static function convertRange($val, $old_min, $old_max, $new_min, $new_max) {
    if (!is_numeric($val) || !is_numeric($old_min) || !is_numeric($old_max) || !is_numeric($new_min) || !is_numeric($new_max)) {
      throw new Exception('All arguments to convertRange must be numeric');
      
    }
    if ($old_max == $old_min) {
      return 0;
    }
    return ((($new_max - $new_min) * ($val - $old_min)) / ($old_max - $old_min)) + $new_min;
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
  public static function relativeValue($typical, $current, $min = 0, $max = 100) {
    $count = count($typical);
    if ($count === 0) {
      return 0;
    }
    array_push($typical, $current);
    sort($typical, SORT_NUMERIC);
    $index = array_search($current, $typical);
    // If the $typical data contains lots of entries that are the same as $current, taking the first occurrance of $current with array_search() understates the relative value
    $occurrances = 0;
    for ($i = 0; $i < count($typical); $i++) { 
      if ($typical[$i] === $current) {
        $occurrances++;
      }
    }
    if ($occurrances > 1) {
      $index += floor($occurrances / 2);
    }
    $relative_value = ($index / (count($typical)-1)) * 100; // Get percent (0-100)
    return self::scale($relative_value, $min, $max); // Scale to $min and $max and return
  }

  /**
   * Updates a row in the relative_values table
   * A meters relative value is a number 0-100 that indicates if the current reading is above or below typical usage
   * Typical usage is the median of historical data recorded in the same hour as the current hour and on a day in the same group as the current day
   * Day groups can look back a number of data points (one data point corresponds to one day because hour resolution data is used) or an amount of time such as "-2 weeks"
   * @param  $meter_id
   * @param  $grouping Example JSON: [{"days":[1,2,3,4,5],"npoints":8},{"days":[1,7],"start":"-2 weeks"}]
   * @param  $current
   */
  public function updateRelativeValueOfMeter($meter_id, $grouping, $current = null, $debug = false) {
    if ($debug) {
      $log = array('Grouping' => $grouping);
    }
    $day_of_week = date('w') + 1; // https://dev.mysql.com/doc/refman/5.5/en/date-and-time-functions.html#function_dayofweek
    foreach (json_decode($grouping, true) as $group) {
      if (in_array($day_of_week, $group['days'])) {
        // Either calculate the current value based on the average of the last n minutes or the last non null point
        if ($current === null) {
          if (array_key_exists('minsAveraged', $group)) {
            $stmt = $this->db->prepare('SELECT AVG(value) FROM meter_data WHERE meter_id = ? AND resolution = \'live\' AND recorded >= ? AND value IS NOT NULL');
            $stmt->execute(array( $meter_id, time()-(intval($group['minsAveraged'])*60) ));
            $current = floatval($stmt->fetchColumn());
          }
          // if minsAveraged doesnt exist or it does but all the data in the past n minutes is null
          if ($current === null || $current === false) {
            // current col is guaranteed not to be null
            $stmt = $this->db->prepare('SELECT current FROM meters WHERE id = ?');
            $stmt->execute(array($meter_id));
            $current = floatval($stmt->fetchColumn());
          }
        }
        if (array_key_exists('npoints', $group)) {
          $amount = intval($group['npoints']);
          $days = implode(',', array_map('intval', $group['days'])); // prevent sql injection with intval as we're concatenating directly into query
          if ($debug) { // also grab recorded col for verification
            $stmt = $this->db->prepare(
            "SELECT value, FROM_UNIXTIME(recorded, '%W, %M %D, %h:%i %p') AS `time` FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ({$days})
            ORDER BY recorded DESC LIMIT " . $amount);
          } else {
            $stmt = $this->db->prepare(
            "SELECT value FROM meter_data
            WHERE meter_id = ? AND value IS NOT NULL AND resolution = ?
            AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
            AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ({$days})
            ORDER BY recorded DESC LIMIT " . $amount); // ORDER BY recorded DESC is needed because we're trying to extract the most recent $amount points
          }
          $stmt->execute(array($meter_id, 'hour'));
          $typical = $stmt->fetchAll();
          if ($debug) {
            $log['Typical data'] = var_export($typical, true);
            $log['Current reading'] = $current;
          }
          $typical = array_map('floatval', array_column($typical, 'value'));
          $relative_value = $this->relativeValue($typical, $current);
          if ($debug) {
            $log['Relative value'] = $relative_value;
          }
        } else if (array_key_exists('start', $group)) {
          $amount = strtotime($group['start']);
          if ($amount === false) {
            throw new Exception("{$group['start']} is not a parseable date");
          }
          $days = implode(',', array_map('intval', $group['days']));
          if ($debug) {
            $stmt = $this->db->prepare(
              "SELECT value, FROM_UNIXTIME(recorded, '%W, %M %D, %h:%i %p') AS `time` FROM meter_data
              WHERE meter_id = ? AND value IS NOT NULL
              AND recorded > ? AND recorded < ? AND resolution = ?
              AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
              AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ({$days})
              ORDER BY value ASC");
          } else {
            $stmt = $this->db->prepare(
              "SELECT value FROM meter_data
              WHERE meter_id = ? AND value IS NOT NULL
              AND recorded > ? AND recorded < ? AND resolution = ?
              AND HOUR(FROM_UNIXTIME(recorded)) = HOUR(NOW())
              AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ({$days})
              ORDER BY value ASC"); // ORDER BY value ASC is efficient here because the relativeValue() method will sort the data like this (and there's no need to sort by recorded -- the amount of data is determined by $amount, which is a unix timestamp representing when the data should start)
          }
          $stmt->execute(array($meter_id, $amount, time(), 'hour'));
          $typical = $stmt->fetchAll();
          if ($debug) {
            $log['Typical data'] = var_export($typical, true);
            $log['Current reading'] = $current;
          }
          $typical = array_map('floatval', array_column($typical, 'value'));
          $relative_value = $this->relativeValue($typical, $current);
          if ($debug) {
            $log['Relative value'] = $relative_value;
          }
        }
        $uuid = $this->IDtoUUID($meter_id);
        $stmt = $this->db->prepare('UPDATE relative_values SET relative_value = ? WHERE meter_uuid = ? AND grouping = ?');
        $stmt->execute(array(round($relative_value), $uuid, $grouping));
        // $rows_updated = $stmt->rowCount(); // if $relative_value doesnt change, it doesnt increment rowCount()
        if ($debug) {
          $rows_updated = $this->db->prepare('SELECT COUNT(*) FROM relative_values WHERE meter_uuid = ? AND grouping = ?');
          $rows_updated->execute(array($uuid, $grouping));
          $log['Relative value configurations updated'] = $rows_updated->fetchColumn();
          echo json_encode($log);
        }
        return true;
      }
    }
    return false; // relative value json is missing current day
  }

  /**
   * Fetches data for a given range, determining the resolution by the amount of data requested.
   *
   * @param $meter_id is the id of the meter
   * @param $from is the unix timestamp for the starting period of the data
   * @param $to is the unix timestamp for the ending period of the data
   * @return Multidimensional array indexed with 'value' for the reading and 'recorded' for the time the reading was recorded
   */
  public function getDataFromTo($meter_id, $from, $to, $res = null, $null = true) {
    if ($res === null) {
      $res = $this->pickResolution($from);
    }
    if ($null) {
      $stmt = $this->db->prepare('SELECT value, recorded FROM meter_data
        WHERE meter_id = ? AND resolution = ? AND recorded >= ? AND recorded <= ?
        ORDER BY recorded ASC');
    } else {
      $stmt = $this->db->prepare('SELECT value, recorded FROM meter_data
        WHERE meter_id = ? AND resolution = ? AND recorded >= ? AND recorded <= ? AND value IS NOT NULL
        ORDER BY recorded ASC');
    }
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
   * [UUIDtoID description]
   * @param [type] $uuid [description]
   */
  public function UUIDtoID($uuid) {
    $stmt = $this->db->prepare('SELECT id FROM meters WHERE bos_uuid = ? LIMIT 1');
    $stmt->execute(array($uuid));
    return $stmt->fetchColumn();
  }

  /**
   * [IDtoUUID description]
   * @param [type] $id [description]
   */
  public function IDtoUUID($id) {
    $stmt = $this->db->prepare('SELECT bos_uuid FROM meters WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
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

  public function pickCol($res) {
    switch ($res) {
      case 'live':
        return 'live_last_updated';
      case 'quarterhour':
        return 'quarterhour_last_updated';
      case 'hour':
        return 'hour_last_updated';
      case 'month':
        return 'month_last_updated';
      default:
        return null;
    }
  }

  /**
   * @param Int $meter_id
   * @return Int unix timestamp
   */
  public function lastUpdated($meter_id, $res) {
    $last_updated_col = $this->pickCol($res);
    $stmt = $this->db->prepare("SELECT {$last_updated_col} FROM meters WHERE id = ?");
    $stmt->execute(array($meter_id));
    return $stmt->fetchColumn();
  }

  /**
   * Gets units for meter
   * @param  Int $meter_id
   * @return String units
   */
  public function getUnits($meter_id) {
    $stmt = $this->db->prepare('SELECT units FROM meters WHERE id = ? LIMIT 1');
    $stmt->execute(array($meter_id));
    return $stmt->fetchColumn();
  }

  /**
   * Gets units for meter
   * @param  Int $meter_id
   * @return String units
   */
  public function getName($meter_id) {
    $stmt = $this->db->prepare('SELECT name FROM meters WHERE id = ? LIMIT 1');
    $stmt->execute(array($meter_id));
    return $stmt->fetchColumn();
  }

  /**
   * Gets units for meter
   * @param  Int $meter_id
   * @return String units
   */
  public function getBuildingName($meter_id) {
    $stmt = $this->db->prepare('SELECT name FROM buildings WHERE id IN (SELECT building_id FROM meters WHERE id = ?) LIMIT 1');
    $stmt->execute(array($meter_id));
    return $stmt->fetchColumn();
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