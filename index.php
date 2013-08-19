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
  <link href="//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css" rel="stylesheet" type="text/css"/>
  <script src="//ajax.googleapis.com/ajax/libs/jquery/1.5/jquery.min.js"></script>
  <script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.8/jquery-ui.min.js"></script>

<script>
<?

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



<?php include('/home/web/fuzzwork/htdocs/menu/menuhead.php'); ?>
</head>
<body>
<?php include('/home/web/fuzzwork/htdocs/menu/menu.php'); ?>

<h1>Route to Region.</h1>
<form action="region.php" method="get">
<label for=origin>Starting System</label><input type=text name="origin" value='<? echo $system; ?>' id=origin>
<select name="regionid">
<?
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
<label for=origins>Starting System</label><input type=text name="origin" value='<? echo $system; ?>' id=origins>
<select name="service">
<?
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
<?
$sql='select itemname,itemid from crpNPCCorporations join invNames on (crpNPCCorporations.corporationid=invNames.itemid) where stationCount>0 order by itemname asc';

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
<label for=originagent>Starting System</label><input type=text name="origin" value='<? echo $system; ?>' id=origins>
<select name="agenttype">
<?
$sql='select divisionid,divisionname from crpNPCDivisions where divisionid in (22,23,24,18)';

$stmt = $dbh->prepare($sql);

$stmt->execute();

while ($row = $stmt->fetchObject()){
echo "<option value=".$row->divisionid;
echo ">".$row->divisionname.'</option>';
}
?>
<option value=0>Locator Agent</option>
</select>

<select name="corpid[]" multiple>
<?
$sql='select itemname,itemid from crpNPCCorporations join invNames on (crpNPCCorporations.corporationid=invNames.itemid) where stationCount>0 order by itemname asc';

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

<?php include('/home/web/fuzzwork/analytics.php'); ?>
</body>
</html>
