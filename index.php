<?php
$expires = 3599;
header("Pragma: public");
header("Cache-Control: maxage=".$expires);
header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');

require_once('db.inc.php');


$headers=apache_request_headers();
$system='Jita';
if ($headers['HTTP_EVE_SOLARSYSTEMNAME'])
{
$system=$headers['HTTP_EVE_SOLARSYSTEMNAME'];
}

?>
<html>
<head>
<title>Route Planners</title>
  <link href="style.css" rel="stylesheet" type="text/css"/>
  <link href="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/themes/base/jquery-ui.css" rel="stylesheet" type="text/css"/>
  <script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
  <script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script>

<script>
<?php

$sql='select solarsystemname from mapSolarSystems order by solarsystemname';


$stmt = $dbh->prepare($sql);

$stmt->execute();

echo "source=[";
$row = $stmt->fetchObject();
echo  '"'.$row->solarsystemname.'"';
while ($row = $stmt->fetchObject()){
echo ',"'.$row->solarsystemname.'"';
}
echo "];\n";
?>

$(document).ready(function() {
    $("input#origin").autocomplete({ source: source });
    $("input#origins").autocomplete({ source: source });
    $("input#originagent").autocomplete({ source: source });
});
</script>

<?php include('/home/web/fuzzwork/htdocs/bootstrap/header.php'); ?>
</head>
<body>
<?php include('/home/web/fuzzwork/htdocs/menu/menubootstrap.php'); ?>
<div class="container">

<h1><a href="jump/jump.php">Jump Planner</a></h1>
<p>Jump drive routes for capitals, black ops and jump freighters, with range spheres, cyno locations, fuel and fatigue.</p>
<hr>
<h1><a href="planets/">Planet Finder</a></h1>
<p>Find the nearest systems with the planet types you need for planetary interaction, and list every planet in a system.</p>
<hr>
<h1>Route to Region.</h1>
<form action="region.php" method="get">
<label for=origin>Starting System</label><input type=text name="origin" value='<?php echo $system; ?>' id=origin>
<select name="regionid">
<?php
$sql='select regionid,regionname from eve.mapRegions order by regionname';

$stmt = $dbh->prepare($sql);

$stmt->execute();

while ($row = $stmt->fetchObject()){
echo "<option value=".$row->regionid;
if ($row->regionid==10000002)
{
echo " selected";
}
echo ">".$row->regionname.'</option>';
}
?>
</select>
<input type=submit value="Find Closest">
</form>
<hr>
<h1>Find Station Service</h1>
<form action="service.php" method="post">
<label for=origins>Starting System</label><input type=text name="origin" value='<?php echo $system; ?>' id=origins>
<select name="service">
<?php
$sql='select serviceName,serviceID from staServices';

$stmt = $dbh->prepare($sql);

$stmt->execute();

while ($row = $stmt->fetchObject()){
echo "<option value=".$row->serviceID;
echo ">".$row->serviceName.'</option>';
}
?>
</select>

<select name="corpid[]" multiple>
<?php
$sql='select distinct itemname,itemid from staStations join invNames on (staStations.corporationid=invNames.itemid) order by itemname asc';

$stmt = $dbh->prepare($sql);

$stmt->execute();

while ($row = $stmt->fetchObject()){
echo "<option value=".$row->itemid;
echo ">".$row->itemname.'</option>';
}
?>

</select>
<select name=limit>
<option>10</option>
<option>25</option>
<option>100</option>
</select>
<select name=route>
<option value=0>Through any security</option>
<option value=1>Through high security only</option>
</option>
<input type=submit value="Find Closest">
</form>
<hr>

<h1>Find Agents</h1>
<p>Find agents for Corporations. Including Locator agents. Excludes tutorial agents.</p>
<form action="agents.php" method="post">
<label for=originagent>Starting System</label><input type=text name="origin" value='<?php echo $system; ?>' id=origins>
<select name="agenttype">
<option value=0>Locator Agent</option>
<?php
$sql='select divisionid,divisionname from eve.crpNPCDivisions where divisionid in (22,23,24,18)';

$stmt = $dbh->prepare($sql);

$stmt->execute();

while ($row = $stmt->fetchObject()){
echo "<option value=".$row->divisionid;
echo ">".$row->divisionname.'</option>';
}
?>
</select>

<select name="corpid[]" multiple>
<?php
$sql='select distinct itemname,itemid from staStations join invNames on (staStations.corporationid=invNames.itemid) order by itemname asc';

$stmt = $dbh->prepare($sql);

$stmt->execute();

while ($row = $stmt->fetchObject()){
echo "<option value=".$row->itemid;
echo ">".$row->itemname.'</option>';
}
?>

</select>
<label for="level">Agent level</label>
<select name=level>
<option value="0">Don't care</option>
<option>1</option>
<option>2</option>
<option>3</option>
<option>4</option>
<option>5</option>
</select>

<select name=limit>
<option>10</option>
<option>25</option>
<option>100</option>
</select>
<select name=route>
<option value=0>Through any security</option>
<option value=1>Through high security only</option>
</option>
<input type=submit value="Find Closest">
</form>
</div>
<?php include('/home/web/fuzzwork/htdocs/bootstrap/footer.php'); ?>

</body>
</html>
