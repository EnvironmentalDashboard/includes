<?php
/**
 * For the OpenWeatherMap API
 *
 * @author Tim Robert-Fitzgerald July 2016
 */
class OpenWeatherMap {

  private $key = 'aac6c14f6ed11c4787ed18ed20a5c18b';

  public function __construct($load_cities = false) {
    if ($load_cities) {
      require 'city.list.us.json.php';
    }
  }

  /**
   * Makes a call to OpenWeatherMap for current weather data with one of 
   */
  public function currentWeather($city_id = null, $city_name = null, $lat = null, $lon = null, $zip = null) {
    if ($city_id !== null) {
      $url = 'http://api.openweathermap.org/data/2.5/weather?id=' . $city_id . '&appid=' . $this->key;
      return json_decode(file_get_contents($url), true);
    }
    if ($city_name !== null) {
      $url = 'api.openweathermap.org/data/2.5/weather?q=' . $city_name . '&appid=' . $this->key;
      return json_decode(file_get_contents($url), true);
    }
    if ($lat !== null && $lon !== null) {
      $url = 'api.openweathermap.org/data/2.5/weather?lat=' . $lat . '&lon=' . $lon . '&appid=' . $this->key;
      return json_decode(file_get_contents($url), true);
    }
    if ($zip !== null) {
      $url = 'api.openweathermap.org/data/2.5/weather?zip=' . $zip . ',us&appid=' . $this->key;
      return json_decode(file_get_contents($url), true);
    }
  }

}
?>