<?php
date_default_timezone_set("America/New_York");
require_once 'db.php';
error_reporting(-1);
ini_set('display_errors', 'On');
/**
 * Retrieves historical and current data from meters to display in a guage.
 *
 * @author Tim Robert-Fitzgerald June 2016
 */
class BuildingOS {

  /**
   * @param $db The database connection
   * @param $api_id ID of the API record to use
   *
   * Sets the token for the class.
   */
  public function __construct($db, $api_id = 1) {
    $this->db = $db;
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
   * @param $user_id is the user_id to be associated with the buildings/meters this function retrieves
   * @param $org is the organization URL to restrict the collected buildings/meters to
   */
  public function populate_db($user_id, $org = null) {
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
          $user_id
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
            $user_id
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