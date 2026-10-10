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

$where='';
$parameters=array();


if (is_numeric($_POST['level']))
{
    $level=$_POST['level'];;
}

if ($level)
{
    $where.=" and agtAgents.level=:agentlevel";
    $parameters[':agentlevel']=$level;
}

if (is_numeric($_POST['agenttype']))
{
    $agenttype=$_POST['agenttype'];
}
if ($agenttype)
{
    $where.=" and agtAgents.divisionID=:agenttype";
    $parameters[':agenttype']=$agenttype;
}
else
{
    $where.=" and agtAgents.isLocator=1";
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
$routetable="routefullsmall";
if ($_POST['route']==1)
{
$routetable="routesmall";
}
?>
<html>
<head>
<title>Agent Finder</title>
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
<tr><th>Agent Name</th><th>Agent Level</th><th>Division</th><th>locator</th><th>Station Name</th><th>System</th><th>Security Level</th><th>Route Length</th><th>Corporation</th></tr>
</thead>
<tbody>
<?php
$corpfilter="";
if (count($corpidlist))
{
$corpfilter="agtAgents.corporationid in (".join(",",$corpidlist).") and";
}
$sql="select agentname.itemname agentname,agentid,stationname,solarsystemname,level,round(mapSolarSystems.security,1) security,greatest(length,0) length,invNames.itemname,divisionname,islocator  from eve.agtAgents join eve.staStations on (agtAgents.locationid=staStations.stationid) join evesupport.$routetable on (end=solarsystemid) join eve.invNames on (itemid=agtAgents.corporationid) join eve.mapSolarSystems on (mapSolarSystems.solarsystemid=staStations.solarsystemid) join eve.invNames agentname on (agtAgents.agentid=agentname.itemid) join eve.crpNPCDivisions on (crpNPCDivisions.divisionID=agtAgents.divisionID) where $corpfilter start=:start";

$sql.=$where;
$sql.=" order by length asc limit $limit";
$stmt = $dbh->prepare($sql);

$parameters[":start"]=$originid;

$stmt->execute($parameters);

while ($row=$stmt->fetchObject()) {
$locator="No";
if ($row->islocator)
{
$locator="Yes";
}
    echo "<tr><td><span onclick='CCPEVE.showInfo(1337, $row->agentid);'>$row->agentname</span></td><td>$row->level</td><td>$row->divisionname</td><td>$locator</td><td>$row->stationname</td><td>".$row->solarsystemname."</td><td>$row->security</td><td>$row->length</td><td>$row->itemname</td></tr>\n";
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

