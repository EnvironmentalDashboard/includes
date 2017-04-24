<?php
date_default_timezone_set("America/New_York");
require_once 'db.php';
error_reporting(-1);
ini_set('display_errors', 'On');
/**
 * Methods for retrieving data from the BuildingOS API
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
    $stmt = $db->prepare('SELECT api_id FROM users WHERE id = ?');
    $stmt->execute(array($user_id));
    $this->api_id = $stmt->fetchColumn();
    $this->user_id = $user_id;
    $results = $db->query("SELECT token, token_updated FROM api WHERE id = {$this->api_id}");
    $arr = $results->fetch();
    if ($arr['token_updated'] + 3595 > time()) { // 3595 = 1 hour - 5 seconds to be safe (according to API docs, token expires after 1 hour)
      $this->token = $arr['token'];
    }
    else { // amortized cost
      $results2 = $db->query("SELECT client_id, client_secret, username, password FROM api WHERE id = {$this->api_id}");
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
      $stmt->execute(array($this->token, time(), $this->api_id));
    }
  }

  /**
   * @return $token for API calls
   */
  public function getToken() {
    return $this->token;
  }

  /**
   * Makes a call to the given URL with the 'Authorization: Bearer $token' header.
   *
   * @param $url to fetch
   * @param $debug if set to true will output the URL used
   * @return contents of web page or false if there was an error
   */
  public function makeCall($url, $debug = false) {
    if ($debug) {
      echo "URL: {$url}\n\n";
    }
    $options = array(
      'http' => array(
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $this->token
        )
    );
    $context = stream_context_create($options);
    $data = file_get_contents($url, false, $context);
    if ($data === false) {
      if ($http_response_header[0] === 'HTTP/1.1 429 TOO MANY REQUESTS') {
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
  public function getMeter($meter, $res, $start, $end, $debug = false) {
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
   */
  public function getBuildings() {
    $url = 'https://api.buildingos.com/buildings?per_page=100';
    $return = array();
    $i = 0;
    $j = 0;
    while (true) {
      $result = $this->makeCall($url);
      if ($result === false) {
        return $return;
      }
      $json = json_decode($result, true);
      foreach ($json['data'] as $building) {
        $return[$i] = array(
          'id' => $building['id'],
          'name' => $building['name'],
          'building_type' => $building['buildingType']['displayName'],
          'address' => "{$building['address']} {$building['postalCode']}",
          'loc' => "{$building['location']['lat']},{$building['location']['lon']}",
          'area' => (empty($building['area'])) ? '' : $building['area'],
          'occupancy' => $building['occupancy'],
          'numFloors' => $building['numFloors'],
          'image' => $building['image'],
          'organization' => $building['organization'],
          'meters' => array()
        );
        foreach ($building['meters'] as $meter) {
          $arr = array(
            'name' => $meter['name'],
            'url' => $meter['url'],
            'displayName' => $meter['displayName']
          );
          $return[$i]['meters'][$j] = $arr;
          $j++;
        }
        $i++;
      }
      if ($json['links']['next'] == "") { // No other data
        return $return;
      }
      else { // Other data to fetch
        $url = $json['links']['next'];
      }
    }
  }

  /**
   * Fill the db with buildings and meters
   * @param $org is the organization URL to restrict the collected buildings/meters to
   */
  public function populate_db($org = null) {
    $url = 'https://api.buildingos.com/buildings?per_page=100';
    while (true) {
      $json = json_decode($this->makeCall($url), true);
      foreach ($json['data'] as $building) {
        // example $org = 'https://api.buildingos.com/organizations/1249'
        if ($org !== null && $building['organization'] !== $org) {
          continue;
        }
        $area = (int) (empty($building['area'])) ? 0 : $building['area'];
        if ($this->db->query('SELECT COUNT(*) FROM buildings WHERE bos_id = \''.$building['id'].'\'')->fetch()['COUNT(*)'] > 0) {
          continue;
        }
        $stmt = $this->db->prepare('INSERT INTO buildings (bos_id, name, building_type, address, loc, area, occupancy, floors, img, org_url, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(array(
          $building['id'],
          $building['name'],
          $building['buildingType']['displayName'],
          "{$building['address']} {$building['postalCode']}",
          "{$building['location']['lat']},{$building['location']['lon']}",
          $area,
          $building['occupancy'],
          $building['numFloors'],
          $building['image'],
          $building['organization'],
          $this->user_id
        ));
        $last_id = $this->db->lastInsertId();
        foreach ($building['meters'] as $meter) {
          $meter_json = json_decode($this->makeCall($meter['url']), true);
          if ($this->db->query('SELECT COUNT(*) FROM meters WHERE bos_uuid = \''.$meter_json['data']['uuid'].'\'')->fetch()['COUNT(*)'] > 0) {
            continue;
          }
          $stmt = $this->db->prepare('INSERT INTO meters (bos_uuid, building_id, source, name, url, building_url, units, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
          $stmt->execute(array(
            $meter_json['data']['uuid'],
            $last_id,
            'buildingos',
            $meter_json['data']['displayName'],
            $meter_json['data']['url'],
            $meter_json['data']['building'],
            $meter_json['data']['displayUnits']['displayName'],
            $this->user_id
          ));
        }
      }
      if ($json['links']['next'] == "") { // No other data
        break;
      }
      else { // Other data to fetch
        $url = $json['links']['next'];
      }
    }
  }

  /**
   * Similiar to the cron() function below, but only updates a specific meter given a res and amount
   */
  public function update_meter($meter_id, $res, $amount) {
    $time = time();
    $stmt = $this->db->prepare('SELECT recorded FROM meter_data
      WHERE meter_id = ? AND resolution = ? AND value IS NOT NULL
      ORDER BY recorded DESC LIMIT 1');
    $stmt->execute(array($meter_id, $res));
    if ($stmt->rowCount() === 1) {
      $last_recording = $stmt->fetchColumn();
      $empty = false;
    }
    else {
      $last_recording = $amount;
      $empty = true;
    }
    $stmt = $this->db->prepare('SELECT url FROM meters WHERE id = ?');
    $stmt->execute(array($meter_id));
    $meter_url = $stmt->fetchColumn();
    $meter_data = $this->getMeter($meter_url . '/data', $res, $last_recording, $time, true);
    $meter_data = json_decode($meter_data, true);
    $meter_data = $meter_data['data'];
    if (!empty($meter_data)) {
      // Clean up old data
      $stmt = $this->db->prepare("DELETE FROM meter_data WHERE meter_id = ? AND resolution = ? AND recorded < ?");
      $stmt->execute(array($meter_id, $res, $amount));
      // Delete null data that we're checking again
      $stmt = $this->db->prepare("DELETE FROM meter_data WHERE meter_id = ? AND resolution = ? AND recorded >= ? AND value IS NULL");
      $stmt->execute(array($meter_id, $res, $last_recording));
      $last_value = null;
      $last_recorded = null;
      foreach ($meter_data as $data) { // Insert new data
        $localtime = strtotime($data['localtime']);
        if ($empty || $localtime > $last_recording) {
          $stmt = $this->db->prepare("INSERT INTO meter_data (meter_id, value, recorded, resolution) VALUES (?, ?, ?, ?)");
          $stmt->execute(array($meter_id, $data['value'], $localtime, $res));
          if ($data['value'] !== null) {
            $last_value = $data['value'];
            $last_recorded = $localtime;
          }
        }
      }
      $stmt = $this->db->prepare('UPDATE meters SET last_update_attempt = ? WHERE id = ?');
      $stmt->execute(array($time, $meter_id));
      if ($last_value !== null && $res === 'live') { // Update meters table
        $stmt = $this->db->prepare('UPDATE meters SET current = ?, last_updated = ? WHERE id = ? LIMIT 1');
        $stmt->execute(array($last_value, $last_recorded, $meter_id));
      }
    } // if !empty($meter_data)
  }

  /**
   * Cron job to access BuildingOS API and update meter data in database.
   * This file has the cron() function. Look at ~/scripts/jobs/ for their usage.
   *
   * The job at the 1 minute interval is for collecting minute resolution meter data (going back 2 hours) and updating meters current values
   * The job at the 15 minute interval is for collecting quarterhour resolution meter data (going back 2 weeks)
   * The job at the 1 hour interval is for collecting hour resolution meter meter data (going back 2 months) and updating the relative_values table
   * The job at the 1 month interval is for collecting month resolution meter data (going back 2 years)
   */
  public function cron($meter, $res, $amount) {
    $time = time();
    $meters = $this->db->prepare('SELECT id, bos_uuid, url FROM meters WHERE (gauges_using > 0 OR for_orb > 0 OR orb_server > 0 OR timeseries_using > 0) AND user_id = ? ORDER BY last_update_attempt ASC'); // ORDER BY last_update_attempt because sometimes the API stops responding if it's queried too quickly and not all the meters get updated
    echo "SELECT id, bos_uuid, url FROM meters WHERE (gauges_using > 0 OR for_orb > 0 OR orb_server > 0 OR timeseries_using > 0) AND user_id = '{$this->user_id}' ORDER BY last_update_attempt ASC\n\n";
    $meters->execute(array($this->user_id));
    while ($row = $meters->fetch()) {
      $stmt = $this->db->prepare('UPDATE meters SET last_update_attempt = ? WHERE id = ?');
      $stmt->execute(array($time, $row['id']));
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
          if ($empty || $localtime > $last_recording) {
            $stmt = $this->db->prepare("INSERT INTO meter_data (meter_id, value, recorded, resolution) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($row['id'], $data['value'], $localtime, $res));
            if ($data['value'] !== null) {
              $last_value = $data['value'];
              $last_recorded = $localtime;
            }
            echo "{$data['value']} @ {$localtime}\n";
          }
        }
        if ($res === 'live' && $last_recorded !== null) { // Update meters table
          $stmt = $this->db->prepare('UPDATE meters SET current = ?, last_updated = ? WHERE id = ? LIMIT 1');
          $stmt->execute(array($last_value, $last_recorded, $row['id']));
        }
        if ($res === 'live') { // Update relative_values table
          echo "Updating relative_values table:\n";
          $stmt = $this->db->prepare('SELECT id, grouping FROM relative_values WHERE meter_uuid = ? AND grouping IS NOT NULL');
          $stmt->execute(array($row['bos_uuid']));
          $day_of_week = date('w') + 1; // https://dev.mysql.com/doc/refman/5.5/en/date-and-time-functions.html#function_dayofweek
          foreach ($stmt->fetchAll() as $rv_row) {
            $meter->updateRelativeValueOfMeter($row['id'], $rv_row['grouping'], $rv_row['id'], $last_value);
            echo "Updated relative value record #{$rv_row['id']}\n";
          } // foreach
        } //
      } // if !empty($meter_data)
      echo "==================================================================\n\n\n\n";
    }
  }
  
}
//*
// echo '<pre>';
// $test = new BuildingOS($db, 3);
// $url = 'https://api.buildingos.com/buildings?per_page=100';
// while (true) {
//   $json = json_decode($test->makeCall($url), true);
//   foreach ($json['data'] as $building) {
//     if ($building['organization'] !== 'https://api.buildingos.com/organizations/1249') {
//       continue;
//     }
//     $area = (int) (empty($building['area'])) ? 0 : $building['area'];
//     if ($db->query('SELECT COUNT(*) FROM buildings WHERE bos_id = \''.$building['id'].'\'')->fetch()['COUNT(*)'] > 0) {
//       continue;
//     }
//     $stmt = $db->prepare('INSERT INTO buildings (bos_id, name, building_type, address, loc, area, occupancy, floors, img, org_url, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
//     $stmt->execute(array(
//       $building['id'],
//       $building['name'],
//       $building['buildingType']['displayName'],
//       "{$building['address']} {$building['postalCode']}",
//       "{$building['location']['lat']},{$building['location']['lon']}",
//       $area,
//       $building['occupancy'],
//       $building['numFloors'],
//       $building['image'],
//       $building['organization'],
//       3
//     ));
//     $last_id = $db->lastInsertId();
//     foreach ($building['meters'] as $meter) {
//       $meter_json = json_decode($test->makeCall($meter['url']), true);
//       if ($db->query('SELECT COUNT(*) FROM meters WHERE bos_uuid = \''.$meter_json['data']['uuid'].'\'')->fetch()['COUNT(*)'] > 0) {
//         continue;
//       }
//       $stmt = $db->prepare('INSERT INTO meters (bos_uuid, building_id, source, name, url, building_url, units, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
//       $stmt->execute(array(
//         $meter_json['data']['uuid'],
//         $last_id,
//         'buildingos',
//         $meter_json['data']['displayName'],
//         $meter_json['data']['url'],
//         $meter_json['data']['building'],
//         $meter_json['data']['displayUnits']['displayName'],
//         3
//       ));
//     }
//   }
//   if ($json['links']['next'] == "") { // No other data
//     break;
//   }
//   else { // Other data to fetch
//     $url = $json['links']['next'];
//   }
// }
//*/
// print_r(json_decode($test->makeCall('https://api.buildingos.com/meters/oberlin_allencroft_main_e/data'), true)); // doesnt work
// print_r(json_decode($test->makeCall('https://api.buildingos.com/meters/oberlin_allencroft_main_e'), true)); //works
// var_dump($test->getMeter('https://api.buildingos.com/meters/oberlin_allencroft_main_e/data', 'live', strtotime('-1 day'), time(), true));
// print_r($test->getBuildings());
// print_r($test->getMeter('https://api.buildingos.com/meters/oberlin_allencroft_main_e/data', 'live', strtotime('-1 day'), time()));

?>