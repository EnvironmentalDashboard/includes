<?php
require 'db.php';
require 'class.Meter.php';
$meter = new Meter($db);
foreach ($db->query('SELECT relative_values.id, relative_values.grouping, relative_values.meter_uuid,
  meters.id AS meter_id, meters.current AS current
  FROM relative_values INNER JOIN meters ON meters.bos_uuid = relative_values.meter_uuid
  WHERE relative_values.grouping IS NOT NULL') as $row) {
  $meter->updateRelativeValueOfMeter($row['meter_id'], $row['grouping'], $row['id'], $row['current']);
}
?>