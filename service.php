<?php
require_once('db.inc.php');

$corpids=isset($_POST['corpid'])?$_POST['corpid']:array();
$corpidlist=array();

foreach ($corpids as $corpid)
if (is_numeric($corpid))
{
    $corpidlist[]=$corpid;
}

$origin=$_POST['origin'];
$originsql="select solarSystemID from mapSolarSystems where solarsystemname=?";
$originstmt = $dbh->prepare($originsql);
$originstmt->execute(array($origin));
$row=$originstmt->fetchObject();
$originid=$row->solarSystemID;

if (is_numeric($_POST['service']))
{
    $serviceid=$_POST['service'];;
}
$limit=10;
if (is_numeric($_POST['limit']))
{
    $limit=$_POST['limit'];;
}
if ($limit>100)
{
$limit=100;
}
if ($limit<10)
{
$limit=10;
}
$routetable=" and highseconly=0";
if ($_POST['route']==1)
{
$routetable="and highseconly=1";
}
?>
<html>
<head>
<title>Service Finder</title>
  <link href="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/themes/base/jquery-ui.css" rel="stylesheet" type="text/css"/>
  <script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
  <script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script>
  <link href="//ajax.aspnetcdn.com/ajax/jquery.dataTables/1.9.4/css/jquery.dataTables.css" rel="stylesheet" type="text/css"/>
  <script type="text/javascript" src="//ajax.aspnetcdn.com/ajax/jquery.dataTables/1.9.4/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function()
    {
        $("#stations").dataTable();
    }
);
</script>
<link href="/lpstore/style.css" rel="stylesheet" type="text/css"/>

<?php include('/home/web/fuzzwork/htdocs/bootstrap/header.php'); ?>
</head>
<body>
<?php include('/home/web/fuzzwork/htdocs/menu/menubootstrap.php'); ?>
<div class="container">


<table border=1 id="stations" class="tablesorter">
<thead>
<tr><th>Station Name</th><th>System</th><th>Security Level</th><th>Route Length</th><th>Corporation</th></tr>
</thead>
<tbody>
<?php
$corpfilter="";
if (count($corpidlist))
{
$corpfilter="corporationid in (".join(",",$corpidlist).") and";
}
$sql="select stationName,solarsystemname,round(mapSolarSystems.security,1) security,greatest(pathLength,1) -1 length,itemname  from staStations join staOperationServices on (staStations.operationID=staOperationServices.operationID) join evesupport.mapRoutes on (toSolarsystemID=solarsystemid) join invNames on (itemid=corporationid) join mapSolarSystems on (mapSolarSystems.solarsystemid=staStations.solarsystemid) where $corpfilter serviceID=:service and fromSolarSystemID=:start $routetable order by pathLength asc limit $limit";
$stmt = $dbh->prepare($sql);
$stmt->execute(array(":service"=>$serviceid,":start"=>$originid));

while ($row=$stmt->fetchObject()) {

    echo "<tr><td>$row->stationName</td><td>".$row->solarsystemname."</td><td>$row->security</td><td>$row->length</td><td>$row->itemname</td></tr>\n";
}
?>
</tbody>
</table>
<br>
</div>
<?php include('/home/web/fuzzwork/htdocs/bootstrap/footer.php'); ?>


<!-- Generated <?php echo date(DATE_RFC822);?> -->
</body>
</html>

