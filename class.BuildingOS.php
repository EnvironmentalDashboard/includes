<?php
date_default_timezone_set("America/New_York");
require_once 'db.php';
error_reporting(-1);
ini_set('display_errors', 'On');
/**
 * The first half of this class contains methods for retrieving data from the BuildingOS API
 * The second half of this class has methods for updating the database with data from the API
 * updateMeter() is the most important method, as it is used by the daemons to cache meter data from the API
 * Most of the other methods aren't used much because they're only needed when meta data about a building/meter has changed in BuildingOS
 *
 * @author Tim Robert-Fitzgerald
 */
class BuildingOS {

  /**
   * @param $db The database connection
   * @param $user_id ID of user associated with meters
   *
   * Sets the token for the class.
   */
  public function __construct($db, $user_id = 1) {
    $this->db = $db;
    if ($user_id == 0) { // test account
      $this->token = 0;
      $this->user_id = 0;
      return;
    }
    $stmt = $db->prepare('SELECT api_id FROM users WHERE id = ?');
    $stmt->execute(array($user_id));
    $api_id = $stmt->fetchColumn();
    $this->user_id = $user_id;
    $results = $db->query("SELECT token, token_updated FROM api WHERE id = {$api_id}");
    $arr = $results->fetch();
    if ($arr['token_updated'] + 3595 > time()) { // 3595 = 1 hour - 5 seconds to be safe (according to API docs, token expires after 1 hour)
      $this->token = $arr['token'];
    }
    else { // amortized cost
      $results2 = $db->query("SELECT client_id, client_secret, username, password FROM api WHERE id = {$api_id}");
      $arr2 = $results2->fetch();
      $url = 'https://api.buildingos.com/o/token/';
      $data = array(
        'client_id' => $arr2['client_id'],
        'client_secret' => $arr2['client_secret'],
        'username' => $arr2['username'],
        'password' => $arr2['password'],
        'grant_type' => 'password'
        );
      $options = array(
        'http' => array(
          'method'  => 'POST',
          'content' => http_build_query($data)
          )
      );
      $context = stream_context_create($options);
      $result = file_get_contents($url, false, $context);
      if ($result === false) {
        // Should handle errors better
        die("There was an error connecting with Lucid's servers.\n\n");
      }
      $json = json_decode($result, true);
      $this->token = $json['access_token'];
      $stmt = $db->prepare('UPDATE api SET token = ?, token_updated = ? WHERE id = ?');
      $stmt->execute(array($this->token, time(), $api_id));
    }
  }


  /*
    ====== METHODS TO RETRIEVE DATA FROM THE API ======
   */
  

  /**
   * Makes a call to the given URL with the 'Authorization: Bearer $token' header.
   *
   * @param $url to fetch
   * @param $debug if set to true will output the URL used
   * @return contents of web page or false if there was an error
   */
  private function makeCall($url, $debug = false) {
    if ($debug) {
      echo "URL: {$url}\n\n";
    }
    if ($this->user_id === 0) {
      $options = array(
        'http' => array(
          'method' => 'GET',
          'header' => 'Authorization: Bearer ' . $this->token
          ),
        'ssl' => array(
          'verify_peer' => false,
          'verify_peer_name' => false,
        )
      );
    } else {
      $options = array(
        'http' => array(
          'method' => 'GET',
          'header' => 'Authorization: Bearer ' . $this->token
          )
      );
    }
    $context = stream_context_create($options);
    $data = file_get_contents($url, false, $context);
    if ($data === false) { // If the API didnt return a proper response
      if ($http_response_header[0] === 'HTTP/1.1 429 TOO MANY REQUESTS') {
        // If it was because the API is being queried too quickly, sleep
        sleep( 1 + preg_replace('/\D/', '', $http_response_header[5]) );
      }
      // Try again
      $data = file_get_contents($url, false, $context);
    }
    return $data;
  }

  /**
   * Fetches data for a meter.
   *
   * @param $meter url e.g. https://api.buildingos.com/meters/oberlin_harkness_main_e/data
   * @param $res can be day, hour, or live
   * @param $start start unix timestamp
   * @param $end end unix timestamp
   * @param $debug if set to true will output the URL used
   * @return contents of web page or false if there was an error
   */
  private function getMeter($meter, $res, $start, $end, $debug = false) {
    $start = date('c', $start);
    $end = date('c', $end);
    if ($start === false || $end === false) {
      die('Error parsing $start/$end dates');
    }
    $res = strtolower($res);
    if ($res != "live" && $res != "hour" && $res != "quarterhour" && $res != "day" && $res != "month") {
      die('$res must be live/quarterhour/hour/day/month');
    }
    $data = array(
      'resolution' => $res,
      'start' => $start,
      'end' => $end
    );
    $data = http_build_query($data);
    return $this->makeCall($meter . "?" . $data, $debug);
  }

  /**
   * Retrieves a list of buildings with their meter and other data stored in a multidimensional array.
   * @param $org array of organization URLs to restrict data collection to. If empty, buildings for all orgs will be collected
   */
  public function getBuildings($org = array()) {
    $url = 'https://api.buildingos.com/buildings?per_page=100';
    $buffer = array();
    $i = 0;
    $j = 0;
    $not_empty = !empty($org);
    while (true) {
      $result = $this->makeCall($url);
      if ($result === false) {
        throw new Exception('Failed to open building URL ' . $url);
        return false;
      }
      $json = json_decode($result, true);
      foreach ($json['data'] as $building) {
        if ($not_empty && !in_array($building['organization'], $org)) {
          continue;
        }
        echo "Fetched building: {$building['name']}\n";
        $buffer[$i] = array( // make an array that can be fed directly into a query 
          ':bos_id' => $building['id'],
          ':name' => $building['name'],
          ':building_type' => $building['buildingType']['displayName'],
          ':address' => "{$building['address']} {$building['postalCode']}",
          ':loc' => "{$building['location']['lat']},{$building['location']['lon']}",
          ':area' => ($building['area'] == '') ? 0 : $building['area'],
          ':occupancy' => $building['occupancy'],
          ':numFloors' => $building['numFloors'],
          ':image' => $building['image'],
          ':organization' => $building['organization'],
          ':user_id' => $this->user_id,
          'meters' => array() // remove if feeding directly into query
        );
        foreach ($building['meters'] as $meter) {
          $meter_result = $this->makeCall($meter['url']);
          if ($result === false) {
            throw new Exception('Failed to open meter URL ' . $meter['url']);
            return false;
          }
          $meter_json = json_decode($meter_result, true);
          $arr = array(
            ':bos_uuid' => $meter_json['data']['uuid'],
            ':building_id' => null, // need to fill this in later if inserting into db
            ':source' => 'buildingos',
            ':scope' => $meter_json['data']['scope']['displayName'],
            ':name' => $meter_json['data']['displayName'],
            ':url' => $meter_json['data']['url'],
            ':building_url' => $meter_json['data']['building'],
            ':units' => $meter_json['data']['displayUnit']['displayName'],
            ':user_id' => $this->user_id
          );
          $buffer[$i]['meters'][$j] = $arr;
          $j++;
        }
        $i++;
      }
      if ($json['links']['next'] == "") { // No other data
        return $buffer;
      }
      else { // Other data to fetch
        $url = $json['links']['next'];
      }
    }
  }

  /**
   * Returns all the organizations for a buildingos account.
   * Can feed directly into $this->getBuildings($this->getOrganizations())
   */
  public function getOrganizations() {
    $buffer = array();
    $json = json_decode($this->makeCall('https://api.buildingos.com/organizations'), true);
    if ($json === false) {
      return false;
    }
    foreach ($json['data'] as $organization) {
      $buffer[$organization['name']] = $organization['url'];
    }
    return $buffer;
  }


  /*
    ====== METHODS TO UPDATE THE DATABASE WITH BUILDING/METER DATA ======
   */
  

  /**
   * Helper for updateMeter()
   */
  private function pickAmount($res) {
    switch ($res) {
      case 'live':
        return strtotime('-2 hours');
      case 'quarterhour':
        return strtotime('-2 weeks');
      case 'hour':
        return strtotime('-2 months');
      case 'month':
        return strtotime('-2 years');
      default:
        return null;
    }
  }

  /**
   * Helper for updateMeter()
   */
  private function pickCol($res) {
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
   * Helper for updateMeter()
   * the amounts returned are kind of arbitrary but are meant to move meters back in the queue of what's being updated by updateMeter() so they don't hold up everything if updateMeter() keeps failing for some reason. note that if updateMeter() does finish, it pushes the meter to the end of the queue by updating the last_updated_col to the current time otherwise the $last_updated_col remains the current time minus the amount this function returns
   */
  private function move_back_amount($res) {
    switch ($res) {
      case 'live':
        return 120;
      case 'quarterhour':
        return 300;
      case 'hour':
        return 600;
      case 'month':
        return 43200;
      default:
        return null;
    }
  }

  /**
   * Used by daemons to update individual meters
   */
  public function updateMeter($meter_id, $meter_uuid, $meter_url, $res, $meterClass, $debug = false) {
    if ($debug) {
      $log = array();
    }
    $amount = $this->pickAmount($res);
    $last_updated_col = $this->pickCol($res);
    $time = time(); // end date
    // Move the meter back in the queue to be tried again soon in case this function does not complete and the $last_updated_col is not updated to the current time
    $stmt = $this->db->prepare("UPDATE meters SET {$last_updated_col} = ? WHERE id = ?");
    $stmt->execute(array($time - $this->move_back_amount($res), $meter_id));
    // Get the most recent recording. Data fetched from the API will start at $last_recording and end at $time
    $stmt = $this->db->prepare('SELECT recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ? AND value IS NOT NULL
      ORDER BY recorded DESC LIMIT 1');
    $stmt->execute(array($meter_id, $res));
    $last_recording = ($stmt->rowCount() === 1) ? $stmt->fetchColumn() : $amount; // start date
    if ($debug) {
      ob_start();
      $meter_data = $this->getMeter($meter_url, $res, $last_recording, $time, $debug);
      $log['URL'] = ob_get_clean();
    } else {
      $meter_data = $this->getMeter($meter_url, $res, $last_recording, $time, $debug);
    }
    if ($meter_data === false) { // file_get_contents returned false, so problem with API
      // return array('false', $meter_url, $res, $last_recording, $time, 4);
      return false;
    }
    $meter_data = json_decode($meter_data, true);
    $meter_data = $meter_data['data'];
    if ($debug) {
      $log['Start time of requested data'] = date('F j, Y, g:i a', $last_recording);
      $log['Current time'] = date('F j, Y, g:i a');
      $log['Meter data'] = var_export($meter_data, true);
    }
    if (!empty($meter_data)) {
      // Delete data older than $amount
      $stmt = $this->db->prepare('DELETE FROM meter_data WHERE meter_id = ? AND resolution = ? AND recorded < ?');
      $stmt->execute(array($meter_id, $res, $amount));
      // Delete null data newer than $last_recording that we're checking again
      $stmt = $this->db->prepare('DELETE FROM meter_data WHERE meter_id = ? AND resolution = ? AND recorded >= ? AND value IS NULL');
      $stmt->execute(array($meter_id, $res, $last_recording));
      $last_value = null;
      $last_recorded = null;
      foreach ($meter_data as $data) { // Insert new data
        $localtime = strtotime($data['localtime']);
        if ($localtime > $last_recording) { // just to make sure
          $stmt = $this->db->prepare('INSERT INTO meter_data (meter_id, value, recorded, resolution) VALUES (?, ?, ?, ?)');
          $stmt->execute(array($meter_id, $data['value'], $localtime, $res));
          if ($data['value'] !== null) {
            $last_value = $data['value'];
            $last_recorded = $localtime;
          }
        }
      }
      if ($last_recorded !== null && $res === 'live') {
        // Update meters table
        $stmt = $this->db->prepare('UPDATE meters SET current = ? WHERE id = ? LIMIT 1');
        $stmt->execute(array($last_value, $meter_id));
        // Update relative_value records
        $stmt = $this->db->prepare('SELECT DISTINCT grouping FROM relative_values WHERE meter_uuid = ? AND grouping != \'[]\' AND grouping != \'\' AND grouping IS NOT NULL AND permission IS NOT NULL');
        // SELECT id, grouping FROM relative_values WHERE meter_uuid = ? AND grouping IS NOT NULL
        $stmt->execute(array($meter_uuid));
        foreach ($stmt->fetchAll() as $rv_row) {
          $meterClass->updateRelativeValueOfMeter($meter_id, $rv_row['grouping'], $last_value);
        }
      }
      $stmt = $this->db->prepare("UPDATE meters SET {$last_updated_col} = ? WHERE id = ?");
      $stmt->execute(array($time, $meter_id));
    } // if !empty($meter_data)
    // return array(json_encode($meter_data), $meter_url, $res, $last_recording, $time, 4);
    if ($debug) {
      echo json_encode($log);
    }
    return true;
  }

  /**
   * Retrieves the meter scope for each meter in the db and updates it.
   */
  public function updateMeterScope() {
    foreach ($this->db->query("SELECT url FROM meters WHERE scope = '' AND source = 'buildingos' AND user_id = {$this->user_id}") as $meter) {
      $contents = $this->makeCall($meter['url']);
      if ($contents === false) {
        continue;
      }
      $json = json_decode($contents, true);
      $scope = $json['data']['scope']['displayName'];
      $stmt = $this->db->prepare('UPDATE meters SET scope = ? WHERE url = ?');
      $stmt->execute(array($scope, $meter['url']));
    }
  }

  public function updateMeterUnits() {
    foreach ($this->db->query("SELECT url FROM meters WHERE units = '' AND source = 'buildingos' AND user_id = {$this->user_id}") as $meter) {
      $contents = $this->makeCall($meter['url']);
      if ($contents === false) {
        continue;
      }
      $json = json_decode($contents, true);
      $units = $json['meta']['units']['value']['displayName'];
      $stmt = $this->db->prepare('UPDATE meters SET units = ? WHERE url = ?');
      $stmt->execute(array($units, $meter['url']));
    }
  }

  /**
   * Adds buildings from the BuildingOS API that aren't already in the database.
   * Optionally delete buildings/meters that no longer exist in the API
   * @param  $org fed into getBuildings()
   * @param  $delete_not_found delete buildings/meters that exist in the database but not the API
   */
  public function syncBuildings($org = array(), $delete_not_found = false) {
    // Get a list of all buildings to compare against what's in db
    $buildings = $this->getBuildings($org);
    echo "Fetched all buildings\n";
    if ($buildings !== false) {
      if ($delete_not_found) { // Delete buildings in db not found in $buildings
        $bos_ids = array_column($buildings, ':bos_id');
        foreach ($this->db->query('SELECT id, bos_id FROM buildings WHERE user_id = '.intval($this->user_id)) as $building) {
          if (!in_array($building['bos_id'], $bos_ids)) {
            $stmt = $this->db->prepare('DELETE FROM buildings WHERE bos_id = ?');
            $stmt->execute(array($building['bos_id']));
            // also delete meters that belong to those buildings
            $stmt = $this->db->prepare('DELETE FROM meters WHERE building_id = ?');
            $stmt->execute(array($building['id']));
            // also delete relative_value records that belong to those meters
            $stmt = $this->db->prepare('DELETE FROM relative_values WHERE meter_uuid IN (SELECT bos_uuid FROM meters WHERE building_id = ?)');
            $stmt->execute(array($building['id']));
            // also delete data from meter_data table
            $stmt = $this->db->prepare('DELETE FROM meter_data WHERE meter_id IN (SELECT id FROM meters WHERE building_id = ?)');
            $stmt->execute(array($building['id']));
          }
        }
      }
      $counter = 0;
      foreach ($buildings as $building) {
        echo "Processed building " . (++$counter) . " out of " . count($buildings) . "\n";
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM buildings WHERE bos_id = ?');
        $stmt->execute(array($building[':bos_id']));
        if ($stmt->fetchColumn() === '0') { // building doesnt exist in db
          $stmt = $this->db->prepare('INSERT INTO buildings (bos_id, name, building_type, address, loc, area, occupancy, floors, img, org_url, user_id) VALUES (:bos_id, :name, :building_type, :address, :loc, :area, :occupancy, :numFloors, :image, :organization, :user_id)');
          foreach (array(':bos_id', ':name', ':building_type', ':address', ':loc', ':area', ':occupancy', ':numFloors', ':image', ':organization', ':user_id') as $param) {
            $stmt->bindValue($param, $building[$param]);
          }
          $stmt->execute();
          $building_id = $this->db->lastInsertId();
        } else { // building does exist, just fetch id
          $stmt = $this->db->prepare('SELECT id FROM buildings WHERE bos_id = ?');
          $stmt->execute(array($building[':bos_id']));
          $building_id = $stmt->fetchColumn();
        }
        // $building is now guaranteed to be a row in the db
        if ($delete_not_found) { // delete meters not found in $buildings['meters']
          $bos_uuids = array_column($building['meters'], ':bos_uuid');
          foreach ($this->db->query('SELECT id, bos_uuid FROM meters WHERE building_id = ' . intval($building_id)) as $meter) {
            if (!in_array($meter['bos_uuid'], $bos_uuids)) {
              $stmt = $this->db->prepare('DELETE FROM meters WHERE bos_uuid = ?');
              $stmt->execute(array($meter['bos_uuid']));
              $stmt = $this->db->prepare('DELETE FROM relative_values WHERE meter_uuid = ?');
              $stmt->execute(array($meter['bos_uuid']));
              $stmt = $this->db->prepare('DELETE FROM meter_data WHERE meter_id = ?');
              $stmt->execute(array($meter['id']));
            }
          }
        }
        // make sure all the meters are there
        foreach ($building['meters'] as $meter) {
          $stmt = $this->db->prepare('SELECT COUNT(*) FROM meters WHERE url = ?');
          $stmt->execute(array($meter[':url']));
          if ($stmt->fetchColumn() === '0') { // meter is not in db
            $meter[':building_id'] = $building_id;
            $stmt = $this->db->prepare('INSERT INTO meters (bos_uuid, building_id, source, scope, name, url, building_url, units, user_id) VALUES (:bos_uuid, :building_id, :source, :scope, :name, :url, :building_url, :units, :user_id)');
            $stmt->execute($meter);
          }
        }
      }
    }
  }

  /**
   * This used to be the mechanism of retrieving data from the BOS API
   * It fetches data for all the meters associated with the user_id used to instantiate the class
   * The crons used to be:
   */
  // */2 * * * * /var/www/html/oberlin/scripts/jobs/minute.sh
  // */15 * * * * /var/www/html/oberlin/scripts/jobs/quarterhour.sh
  // 0 * * * * /var/www/html/oberlin/scripts/jobs/hour.sh
  // 0 0 1 * * /var/www/html/oberlin/scripts/jobs/month.sh
  /*
  public function cron($meterClass, $res) {
    $amount = $this->pickAmount($res);
    $last_updated_col = $this->pickCol($res);
    $time = time();
    // Get all the meters belonging to the user id used to instantiate the class
    $sql = "SELECT id, bos_uuid, url FROM meters WHERE (gauges_using > 0 OR for_orb > 0 OR timeseries_using > 0) OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = 'orb_server') AND user_id = ? ORDER BY {$last_updated_col} ASC";
    $meters = $this->db->prepare($sql);
    echo "{$sql}\n\n";
    $meters->execute(array($this->user_id));
    while ($row = $meters->fetch()) {
      echo "Fetching meter #{$row['id']}\n";
      // Check to see what the last recorded value is
      // I just added 'AND value IS NOT NULL' because sometimes BuildingOS returns null data and later fixes it? ...weird
      $stmt = $this->db->prepare('SELECT recorded FROM meter_data
        WHERE meter_id = ? AND resolution = ? AND value IS NOT NULL
        ORDER BY recorded DESC LIMIT 1');
      $stmt->execute(array($row['id'], $res));
      if ($stmt->rowCount() === 1) {
        $last_recording = $stmt->fetchColumn();
        $empty = false;
        echo "Last recording at " . date('F j, Y, g:i a', $last_recording) . "\n";
      }
      else {
        $last_recording = $amount;
        $empty = true;
        echo "No data exists for this meter, fetching all data\n";
      }

      $meter_data = $this->getMeter($row['url'] . '/data', $res, $last_recording, $time, true);
      $meter_data = json_decode($meter_data, true);
      if ($res === 'month') {
        // Update the units in case they've changed (only do this for 1 cron job)
        $units = $meter_data['meta']['units']['value']['displayName'];
        $stmt = $this->db->prepare("UPDATE meters SET units = ? WHERE id = ? LIMIT 1");
        $stmt->execute(array($units, $row['id']));
      }
      $meter_data = $meter_data['data'];
      echo "Raw meter data from BuildingOS:\n";
      print_r($meter_data);
      if (!empty($meter_data)) {
        echo "Cleaning up old data\n";
        // Clean up old data
        $stmt = $this->db->prepare("DELETE FROM meter_data WHERE meter_id = ? AND resolution = ? AND recorded < ?");
        $stmt->execute(array($row['id'], $res, $amount));
        // Delete null data that we're checking again
        $stmt = $this->db->prepare("DELETE FROM meter_data WHERE meter_id = ? AND resolution = ? AND recorded >= ? AND value IS NULL");
        $stmt->execute(array($row['id'], $res, $last_recording));
        echo "Query ran: DELETE FROM meter_data WHERE meter_id = {$row['id']} AND resolution = {$res} AND recorded < {$amount}\n";
        echo "Query ran: DELETE FROM meter_data WHERE meter_id = {$row['id']} AND resolution = {$res} AND recorded >= {$last_recording}\n";
        echo "Iterating over and inserting data into database:\n";
        $last_value = null;
        $last_recorded = null;
        foreach ($meter_data as $data) { // Insert new data
          $localtime = strtotime($data['localtime']);
          if ($localtime > $last_recording) { // just to make sure
            $stmt = $this->db->prepare("INSERT INTO meter_data (meter_id, value, recorded, resolution) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($row['id'], $data['value'], $localtime, $res));
            if ($data['value'] !== null) {
              $last_value = $data['value'];
              $last_recorded = $localtime;
            }
            echo "{$data['value']} @ {$localtime}\n";
          }
        }
        $stmt = $this->db->prepare("UPDATE meters SET {$last_updated_col} = ? WHERE id = ?");
        $stmt->execute(array($time, $meter['id']));
        if ($res === 'live' && $last_recorded !== null) {
          // Update meters table
          $stmt = $this->db->prepare('UPDATE meters SET current = ? WHERE id = ? LIMIT 1');
          $stmt->execute(array($last_value, $row['id']));
          // Update relative value records
          echo "Updating relative_values table:\n";
          $stmt = $this->db->prepare('SELECT DISTINCT grouping FROM relative_values WHERE meter_uuid = ? AND grouping IS NOT NULL');
          $stmt->execute(array($row['bos_uuid']));
          foreach ($stmt->fetchAll() as $rv_row) {
            $meterClass->updateRelativeValueOfMeter($row['id'], $rv_row['grouping'], $last_value);
            echo "Updated relative value record #{$rv_row['id']}\n";
          } // foreach
        }
      } // if !empty($meter_data)
      echo "==================================================================\n\n\n\n";
    }
  }
  */
  
}
?>