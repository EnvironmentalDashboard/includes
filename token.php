<?php
require 'db.php';
require 'class.BuildingOS.php';
$bos = new BuildingOS($db);
echo $bos->getToken();