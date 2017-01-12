<?php
require 'class.Meter.php';

/**
 * For the time series
 *
 * @author Tim Robert-Fitzgerald September 2016
 */
class TimeSeries extends Meter {

  /**
   * Gets data for time series and sets some class variables
   *
   * @param $db database connection
   * @param $meter_id of meter
   * @param $start time series starts at this date
   * @param $end time series ends at this date
   * @param $min if not null, the graph will be scaled to this number rather than the datas min
   * @param $max if not null, the graph will be scaled to this number rather than the datas max
   * @param $alt_data if not null, will be used instead of calling getData()
  */
  public function __construct($db, $meter_id, $start, $end, $res = null, $min = null, $max = null, $alt_data = null) {
    parent::__construct($db);
    if ($res === 'daily') {
      $this->data = ($alt_data === null) ? parent::getDailyData($meter_id, $start, $end) : $alt_data;
    }
    else {
      $this->data = ($alt_data === null) ? parent::getData($meter_id, $start, $end, $res) : $alt_data;
    }
    $this->fill = true;
    $this->dashed = true;
    $this->meter_id = $meter_id;
    $this->circlepoints = array(); // The path for the circle to follow
    $this->points = array(); // The points for the chart
    $this->yaxis = array(); // Contains the y-axis labels
    $this->times = array(); // Contains time lables
    $this->units = null;
    $this->db = $db;
    $this->recorded = array_column($this->data, 'recorded');
    $this->value = array_column($this->data, 'value');
    $this->count = count($this->value);
    $this->max = null;
    $this->min = null;
    $this->baseload = PHP_INT_MAX;
    $this->peak = 0;
    $this->pad = 40; // Amount to pad sides of chart in pixels; set to 0 to turn off
    if (empty(array_filter($this->value))) {
      echo "<!--\nCalled with __construct(\$db, $meter_id, $start, $end, $min, $max, $alt_data);\n";
      var_dump($this->data);
      echo "-->\n";
      echo "<image xlink:href=\"images/error.svg\" x=\"400\" y=\"20\" height=\"200\" width=\"200\" /> ";
      echo '<text x="50" y="275" font-weight="600" font-family="\'Roboto\',Helvetica,sans-serif" font-size="35">There are no data for this meter; please select another.</text>';
      echo "\n<script type='text/javascript'>\n";
      echo "// <![CDATA[;\n";
      echo "setTimeout(function(){ window.location.reload(); }, 30000);\n";
      echo "// ]]>\n</script></svg>";
      exit();
    }
    // echo "<!--";
    // echo "$meter_id $start $end";
    // var_dump($this->data);
    // echo "-->\n";
  }

  /**
   * Maps the points for the SVG chart
   */
  public function printChart($height, $width, $offset = 0, $alt_min = null, $alt_max = null) {
    $was400 = 9999;
    $x = $this->pad;
    $y = 0;
    $lastx = $x;
    $last_point = 0;
    $max = ($alt_max === null) ? $this->max : $alt_max;
    $min = ($alt_min === null) ? $this->min : $alt_min;
    $increment = abs($width / ($this->count - 1));
    if ($this->pad !== 0) {
      $increment = $increment * ((400-$this->pad)/400);
    }
    if ($this->fill) { // Need to print polygons that are the 'fill'
      echo "<polygon fill='{$this->color}' fill-opacity='0.25' points='";
      foreach ($this->value as $point) {
        if ($point === null) {
          echo $x-$increment.",{$was400} {$lastx},{$was400}' /><polygon fill='{$this->color}' fill-opacity='0.25' points='";
          $lastx = $x;
        }
        else {
          $y = $this->convertRange(abs($point - $max), 0, $max - $min, 0, $height) + $offset;
          echo round($x, 1) . ',' . round($y, 1) . ' ';
          // echo "{$x},{$y} ";
        }
        array_push($this->circlepoints, array($x, $y));
        $x += $increment;
        $last_point = $point;
      }
      echo "{$width},{$was400} {$lastx},{$was400}' />";
    }
    $x = $this->pad;
    echo "<polyline fill='none' stroke='{$this->color}' stroke-width='2' ";
    echo ($this->dashed) ? "stroke-dasharray='10,10' " : '';
    echo "points='";
    foreach ($this->value as $point) {
      if ($point === null) {
        echo "' /><polyline fill='none' stroke='{$this->color}' stroke-width='2' ";
        echo ($this->dashed) ? "stroke-dasharray='10,10' " : '';
        echo "points='";
      }
      else {
        $y = $this->convertRange(abs($point - $max), 0, $max - $min, 0, $height) + $offset;
        echo round($x, 1) . ',' . round($y, 1) . ' ';
        // echo "{$x},{$y} ";
        if (!$this->fill) {
          array_push($this->circlepoints, array($x, $y));
        }
        if ($y > $this->peak) {
          $this->peak = $y;
        }
        if ($y < $this->baseload) {
          $this->baseload = $y;
        }
      }
      $x += $increment;
    }
    echo "' />";
  }

  /**
   * Scales a number from an old range to a new range
   */
  public function convertRange($val, $old_min, $old_max, $new_min, $new_max) {
    return ((($new_max - $new_min) * ($val - $old_min)) / ($old_max - $old_min)) + $new_min;
  }

  /**
   * Sets the $this->units class variable
   */
  public function setUnits() {
    $stmt = $this->db->prepare('SELECT units FROM meters WHERE id = ? LIMIT 1');
    $stmt->execute(array($this->meter_id));
    $this->units = $stmt->fetch()['units'];
  }

  /**
   * Gets the meter name
   */
  public function getName() {
    $stmt = $this->db->prepare('SELECT name FROM meters WHERE id = ? LIMIT 1');
    $stmt->execute(array($this->meter_id));
    return $stmt->fetch()['name'];
  }

  /**
   * Fills an array with the times the readings were recorded
   */
  public function setTimes() {
    $i = 0;
    foreach ($this->recorded as $time) {
      $this->times[$i++] = date('n\/j g:i a', $time);
    }
  }

  /**
   * These functions set class variables
   */
  public function dashed($dashed = true) { $this->dashed = $dashed; }
  public function fill($fill) { $this->fill = $fill; }
  public function color($color) { $this->color = $color; }
  public function stroke_width($stroke_width) { $this->stroke_width = $stroke_width; }
  public function setMax($max = null) { $this->max = ($max === null) ? max($this->value) : $max; }
  public function setMin($min = null) { $this->min = ($min === null) ? min(array_filter($this->value)) : $min; }

  /**
   * Sets the y-axis
   * See: http://stackoverflow.com/a/9007526/2624391
   */
  public function yAxis($ticks = 7) {
    // This routine creates the Y axis values for a graph.
    //
    // Calculate Min amd Max graphical labels and graph
    // increments.  The number of ticks defaults to
    // 10 which is the SUGGESTED value.  Any tick value
    // entered is used as a suggested value which is
    // adjusted to be a 'pretty' value.
    //
    // Output will be an array of the Y axis values that
    // encompass the Y values.
    if ($this->min === null || $this->max === null) {
      die('Need to set min/max before calling yAxis()');
    }
    $yMax = $this->max;
    $yMin = $this->min;
    if ($this->units !== null) {
      switch ($this->units) {
        case 'Kilowatts':
          $yMin = 0;
          break;
        case 'Gallons / hour':
          $yMin = 0;
          break;
        case 'Gallons per minute':
          $yMin = 0;
          break;
        case 'Pounds of steam / hour':
          $yMin = 0;
          break;
        case 'KiloBTU / hour':
          $yMin = 0;
          break;
        case 'Liters':
          $yMin = 0;
          break;
        case 'Liters / hour':
          $yMin = 0;
          break;
      }
    }
    if (isset($_GET['start']) && is_numeric($_GET['start'])) {
      $yMin = $_GET['start'];
    }
    // If yMin and yMax are identical, then
    // adjust the yMin and yMax values to actually
    // make a graph. Also avoids division by zero errors.
    if ($yMin == $yMax) {
      $yMin = $yMin - 1; // some small value
      $yMax = $yMax + 1; // some small value
    }
    // Determine Range
    $range = $yMax - $yMin;
    // Adjust ticks if needed
    if ($ticks < 2) {
      $ticks = 2;
    }
    else if ($ticks > 2) {
      $ticks -= 2;
    }
    // Get raw step value
    $tempStep = $range/$ticks;
    // Calculate pretty step value
    $mag = floor(log10($tempStep));
    $magPow = pow(10,$mag);
    $magMsd = (int)($tempStep/$magPow + 0.5);
    $stepSize = $magMsd*$magPow;

    // build Y label array.
    // Lower and upper bounds calculations
    $lb = $stepSize * floor($yMin/$stepSize);
    $ub = $stepSize * ceil(($yMax/$stepSize));
    // Build array
    $val = $lb;
    $this->yaxis_min = $val;
    while (1) {
      $this->yaxis[] = $this->bd_nice_number($val);
      $val += $stepSize;
      if ($val > $ub) {
        break;
      }
    }
    $this->yaxis_max = $val;
  }

  private function bd_nice_number($n) {
    $n = (0+str_replace(",","",$n));
    if(!is_numeric($n)) return false;
    if(abs($n)>=1000000000000) return round(($n/1000000000000),1).'T';
    else if(abs($n)>=1000000000) return round(($n/1000000000),1).'B';
    else if(abs($n)>=1000000) return round(($n/1000000),1).'M';
    else if(abs($n)>=1000) return round(($n/1000),1).'k';
    
    return number_format($n);
  }

  // /**
  //  * Helper function for RamerDouglasPeucker()
  //  */
  // private function perpendicularDistance($ptX, $ptY, $l1x, $l1y, $l2x, $l2y) {
  //   $result = 0;
  //   if ($l2x == $l1x) {
  //     // vertical lines - treat this case specially to avoid dividing by zero
  //     $result = abs($ptX - $l2x);
  //   }
  //   else {
  //     $slope = (($l2y-$l1y) / ($l2x-$l1x));
  //     $passThroughY = (0-$l1x)*$slope + $l1y;
  //     $result = (abs(($slope * $ptX) - $ptY + $passThroughY)) / (sqrt($slope*$slope + 1));
  //   }
  //   return $result;
  // }

  // /**
  //  * Smooths jaggy data
  //  * See: https://en.wikipedia.org/wiki/Ramer–Douglas–Peucker_algorithm
  //  */
  // private function RamerDouglasPeucker($pointList, $epsilon) {
  //   if ($epsilon <= 0) {
  //     throw new Exception('Non-positive epsilon.');
  //   }
  //   if (count($pointList) < 2) {
  //     return $pointList;
  //   }
  //   // Find the point with the maximum distance
  //   $dmax = 0;
  //   $index = 0;
  //   $totalPoints = count($pointList);
  //   for ($i = 1; $i < ($totalPoints - 1); $i++) {
  //     $d = $this->perpendicularDistance(
  //                 $pointList[$i][0], $pointList[$i][1],
  //                 $pointList[0][0], $pointList[0][1],
  //                 $pointList[$totalPoints-1][0],
  //                 $pointList[$totalPoints-1][1]);
  //     if ($d > $dmax) {
  //       $index = $i;
  //       $dmax = $d;
  //     }
  //   }
  //   $resultList = array();
  //   // If max distance is greater than epsilon, recursively simplify
  //   if ($dmax >= $epsilon) {
  //     // Recursive call on each 'half' of the polyline
  //     $recResults1 = $this->RamerDouglasPeucker(array_slice($pointList, 0, $index + 1), $epsilon);
  //     $recResults2 = $this->RamerDouglasPeucker(array_slice($pointList, $index, $totalPoints - $index), $epsilon);
  //     // Build the result list
  //     $resultList = array_merge(array_slice($recResults1, 0, count($recResults1) - 1), array_slice($recResults2, 0, count($recResults2)));
  //   }
  //   else {
  //     $resultList = array($pointList[0], $pointList[$totalPoints-1]);
  //   }
  //   // Return the result
  //   return $resultList;
  // }

}
?>
