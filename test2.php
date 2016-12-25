<?php
require 'db.php';
$i = 0;
foreach ($db->query('SELECT * FROM charachters WHERE name = "SQ_Emot_4-5_BlowKisses"') as $row) {
	echo "<div id='frame{$i}' style='display:none;position:absolute;top:0;left:0;'>{$row['content']}'</div>";
	$i++;
}
?>
<script>
document.addEventListener("DOMContentLoaded", function(event) { 
  var i = 0;
var int = setInterval(function(){
	document.getElementById('frame' + i++).style.display = 'initial';
	if (i == <?php echo $i ?>) {
		for (var j = <?php echo $i ?> - 1; j >= 1; j--) {
			document.getElementById('frame' + j).style.display = 'none';
		}
		i = 0;
	}
}, 150);
});
</script>