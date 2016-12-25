<?php

error_reporting(-1);
ini_set('display_errors', 'On');

// $output = shell_exec("cd /home/admin060606; /home/admin060606/public_html/oberlin/includes/test.sh");
// $output = shell_exec('cd /home/admin060606/mail; find . -name "*" -type f -delete');
// echo $output;

//echo shell_exec('curl -v -X GET -L -H "Authorization: Bearer IW8vXzaDhq7Hdlh1ADf660mWboWPAx" https://api.buildingos.com/meters/oberlin_ajlc_precipitation/data?reso');


// require 'class.BuildingOS.php';
// $bos = new BuildingOS($db);
// foreach ($db->query('SELECT id, url FROM meters WHERE units = \'\' OR building_url = \'\'') as $meter) {
// 	$meter_json = json_decode($bos->makeCall($meter['url']), true);
// 	$stmt = $db->prepare('UPDATE meters SET building_url = ?, units = ? WHERE id = ?');
//     $stmt->execute(array(
//       $meter_json['data']['building'],
//       $meter_json['data']['displayUnits']['displayName'],
//       $meter['id']
//     ));
// }

require 'db.php';
$stmt = $db->prepare('INSERT INTO charachters (name, content) VALUES (?, ?)');
$stmt->execute(array($_POST['name'], $_POST['content']));