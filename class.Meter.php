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
    $index = array_search($current, $typical);
    $relative_value = (($index) / count($typical)) * 100; // Get percent (0-100)
    return ($relative_value / 100) * ($max - $min) + $min; // Scale to $min and $max and return
  }

  /**
   * Fetches all data points for a given meter and resolution
   *
   * @param $meter_id is the id of the meter
   * @param $res is the resolution to get data for ('quarterhour', 'hour', 'day')
   * @return Multidimensional array indexed with 'value' for the reading and 'recorded' for the time the reading was recorded
   */
  public function getAllData($meter_id, $res) {
    $stmt = $this->db->prepare('SELECT value, recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ?
      ORDER BY recorded ASC');
    $stmt->execute(array($meter_id, $res));
    return $stmt->fetchAll();
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
    // echo "SELECT value, recorded FROM meter_data WHERE meter_id = $meter_id AND resolution = $res AND recorded > $from AND recorded < $to ORDER BY recorded ASC";
    // var_dump($stmt->fetchAll());
    // exit;
    return $stmt->fetchAll();
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
   * Picks the resolution based on what is stored in the database
   * For Lucid data only as external CSV data might be stored in a different way
   * (in which case the last paramter of getData() should be used)
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