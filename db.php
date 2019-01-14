<?php
$dbname = 'oberlin_environmentaldashboard';
$production_server = (posix_uname()['nodename'] === 'environmentaldashboard.org');
if ($production_server) { // mysql server is on same machine as web server
  require '/var/secret/local.php';
} else { // connect to mysql server remotely
  require '/var/www/html/repos/secret/remote.php';
}

try {
  $db = new PDO($con, "{$username}", "{$password}"); // cast as string bc cant pass as reference
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}
catch (PDOException $e) { die($e->getMessage()); }

$user_id = 1; // Default to Oberlin
if (isset($_SERVER['REQUEST_URI']) && $production_server) { // The browser sets REQUEST_URI, so it will not be set for scripts run on command line
  $symlink = explode('/', $_SERVER['REQUEST_URI'])[1];
  $stmt = $db->prepare('SELECT id FROM users WHERE slug = ?');
  $stmt->execute(array($symlink));
  if ($stmt->rowCount() === 1) {
  	$user_id = intval($stmt->fetchColumn());
  } else {
    $symlink = 'oberlin';
  }
} else {
  $symlink = 'oberlin';
}
