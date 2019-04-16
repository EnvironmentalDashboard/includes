<?php
$dbname = 'oberlin_environmentaldashboard';
$production_server_domain = 'environmentaldashboard.org';

// Find the dnsdomainname from the FQDN
$fqdn = posix_uname()['nodename'];
$split_fqdn = explode('.', $fqdn, 2);

$dnsdomainname = sizeof($split_fqdn) > 1 ? $split_fqdn[1] : $fqdn;

$production_server = ($dnsdomainname === $production_server_domain || $fqdn === $production_server_domain);

if ($production_server) { // mysql server is on same machine as web server
  require '/var/secret/local.php';
} else { // connect to mysql server remotely
  require '/var/secret/remote.php';
}

try {
  $db = new PDO($con, "{$username}", "{$password}"); // cast as string bc cant pass as reference
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}
catch (PDOException $e) { die($e->getMessage()); }

$user_id = 1; // Default to Oberlin
if (isset($_SERVER['REQUEST_URI']) && $production_server) { // The browser sets REQUEST_URI, so it will not be set for scripts run on command line
  $subdomain = explode('.', $_SERVER['HTTP_HOST'])[0];
  $stmt = $db->prepare('SELECT id FROM users WHERE slug = ?');
  $stmt->execute(array($subdomain));
  if ($stmt->rowCount() === 1) {
  	$user_id = intval($stmt->fetchColumn());
  } else {
    $subdomain = 'oberlin';
  }
} else {
  $subdomain = 'oberlin';
}
