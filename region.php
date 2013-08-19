<?php
error_reporting(E_ALL);
require_once('graph.php');
require_once("db.inc.php");

$routetable="evesupport.mapSolarSystemJumpLists";
if ($_GET['type']=="high")
{
    $routetable="evesupport.mapSolarSystemJumpListsHigh";
}

        $destinationregion=$_GET['regionid'];
        $origin=$_GET['origin'];

        $originsql="select solarSystemID from mapSolarSystems where solarsystemname=?";
        $originstmt = $dbh->prepare($originsql);
        $originstmt->execute(array($origin));

        $row=$originstmt->fetchObject();
        $originid=$row->solarSystemID;
        $destinationsql="select solarSystemID from mapSolarSystems where regionid=?";
        $destinationstmt = $dbh->prepare($destinationsql);
        $destinationstmt->execute(array($destinationregion));
	$toarray = array();
        while ($row=$destinationstmt->fetchObject()) {
                $toarray[]=$row->solarSystemID;
        }

        $route=array();
        $routeplan=array();
        $max=5000;
        $maxjump=5000;

		$jumpArray = array();

		$query="SELECT * FROM $routetable";
                $stmt = $dbh->prepare($query);
                $stmt->execute();

		$previousSystem = "";
		$arrayContent = "";

		while ($row=$stmt->fetchObject()) {
			$systemId = trim($row->fromSolarSystemID);
			$jumpArray[$systemId]= explode(",", strtoupper($row->toSolarSystemID));
		}



        foreach($toarray as $to)
        {
		$jumpNum = 1;
                $route=array();
		foreach( $jumpArray[$originid] as $n ) {
			if ($n == $to) {
				$jumpNum = 2;
				$route[] = "$to";
				break;
			}
		}

		if ($jumpNum == 1) {
			foreach( graph_find_path( $jumpArray, $originid, $to,$max) as $n ) {
				if ($jumpNum > 1) {
					$route[]=  $n;
				}
				$jumpNum++;
			}
		}
                if ($jumpNum<$maxjump)
                {
                    $maxjump=$jumpNum-1;
                    $finaldestination=$to;
                    $routeplan=$route;
                }
         }

?>
<html>
<head><title>Route planner - Regional</title>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.5/jquery.min.js"></script>
<?php include('/home/web/fuzzwork/htdocs/menu/menuhead.php'); ?>
</head>
<body>
<?php include('/home/web/fuzzwork/htdocs/menu/menu.php'); ?>

<p>If a route is possible, it will be displayed below.</p> 
<table>
<tr><th>System</th></tr>
<?
 $routesql="select solarSystemName,solarSystemID from mapSolarSystems where solarsystemid=?";
        $routestmt = $dbh->prepare($routesql);

foreach($routeplan as $system)
{
        $routestmt->execute(array($system));
        $row=$routestmt->fetchObject();
        $name=$row->solarSystemName;
        echo "<tr><td onclick='CCPEVE.showRouteTo(".$row->solarSystemID.",".$originid.")'>".$name."</td></tr>";

}

 
?>
<tr><th>Route Length: <? echo $maxjump-1; ?></th><tr>
</table>
</body>
</html>
