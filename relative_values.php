<?php
require 'db.php';
require 'class.Meter.php';
$meter = new Meter($db);
foreach ($db->query('SELECT
  relative_values.id, relative_values.grouping, relative_values.meter_uuid, meters.id AS meter_id
  FROM relative_values INNER JOIN meters ON meters.bos_uuid = relative_values.meter_uuid
  WHERE relative_values.grouping IS NOT NULL') as $row) {
  $stmt = $db->prepare('SELECT current FROM meters WHERE bos_uuid = ?');
  $stmt->execute(array($row['meter_uuid']));
  $current = $stmt->fetchColumn();
  $meter->updateRelativeValueOfMeter($row['meter_id'], $row['grouping'], $row['id'], $current);
}
?>