<?php
require_once(__DIR__.'/../db.inc.php');
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

define('AU_METRES', 149597870700);

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Security as the client shows it: one decimal, except anything above 0.0
// but below 0.05 shows (and counts) as 0.1.
function sec_display($sec)
{
    if ($sec > 0 && $sec < 0.05) {
        return '0.1';
    }
    return number_format(round($sec, 1), 1);
}

function sec_class($sec)
{
    $s = (float)sec_display($sec);
    if ($s >= 0.5) {
        return 'sec-high';
    }
    if ($s > 0) {
        return 'sec-low';
    }
    return 'sec-null';
}

// Planet types that support PI, with the raw materials each yields.
// typeID => array('name'=>'Barren', 'materials'=>array(materialTypeID=>name, ...))
// Taken from the extractors (group 1026): planetRestriction (1632) is the
// planet type it can be built on, harvesterType (709) the material it pulls.
function pi_planet_types($dbh)
{
    $sql = "select distinct t.typeID planetTypeID, t.typeName, m.typeID materialTypeID, m.typeName material
            from invTypes e
            join dgmTypeAttributes pr on (pr.typeID=e.typeID and pr.attributeID=1632)
            join dgmTypeAttributes ht on (ht.typeID=e.typeID and ht.attributeID=709)
            join invTypes t on (t.typeID=coalesce(pr.valueInt, pr.valueFloat))
            join invTypes m on (m.typeID=coalesce(ht.valueInt, ht.valueFloat))
            where e.groupID=1026 and e.published=1
            order by t.typeName, m.typeName";
    $types = array();
    foreach ($dbh->query($sql, PDO::FETCH_OBJ) as $row) {
        $id = (int)$row->planetTypeID;
        if (!isset($types[$id])) {
            $types[$id] = array(
                'name' => preg_replace('/^Planet \((.*)\)$/', '$1', $row->typeName),
                'materials' => array(),
            );
        }
        $types[$id]['materials'][(int)$row->materialTypeID] = $row->material;
    }
    return $types;
}

// Every PI commodity, from raw materials (tier 0) up to P4.
// typeID => array('name'=>..., 'tier'=>0-4, 'inputs'=>array(typeID, ...))
function pi_commodities($dbh)
{
    $tiers = array(1032 => 0, 1033 => 0, 1035 => 0, 1042 => 1, 1034 => 2, 1040 => 3, 1041 => 4);
    $sql = "select distinct t.typeID, t.typeName, t.groupID
            from planetSchematicsTypeMap m
            join invTypes t on (t.typeID=m.typeID)
            where t.groupID in (".implode(',', array_keys($tiers)).")
            order by t.typeName";
    $commodities = array();
    foreach ($dbh->query($sql, PDO::FETCH_OBJ) as $row) {
        $commodities[(int)$row->typeID] = array(
            'name' => $row->typeName,
            'tier' => $tiers[(int)$row->groupID],
            'inputs' => array(),
        );
    }
    $sql = "select o.typeID output, i.typeID input
            from planetSchematicsTypeMap o
            join planetSchematicsTypeMap i on (i.schematicID=o.schematicID and i.isInput=1)
            where o.isInput=0";
    foreach ($dbh->query($sql, PDO::FETCH_OBJ) as $row) {
        if (isset($commodities[(int)$row->output])) {
            $commodities[(int)$row->output]['inputs'][] = (int)$row->input;
        }
    }
    return $commodities;
}

// The raw materials (tier 0 typeIDs) a commodity is ultimately made from.
function pi_raw_needs($commodities, $typeid, &$needs = array())
{
    if ($commodities[$typeid]['tier'] == 0) {
        $needs[$typeid] = true;
    }
    foreach ($commodities[$typeid]['inputs'] as $input) {
        pi_raw_needs($commodities, $input, $needs);
    }
    return $needs;
}

function page_head($title)
{
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo h($title); ?></title>
<link href="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/themes/base/jquery-ui.css" rel="stylesheet" type="text/css"/>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script>
<?php include('/home/web/fuzzwork/htdocs/bootstrap/header.php'); ?>
<style>
.sec-high { color: #2fa84f; font-weight: bold; }
.sec-low { color: #d98c00; font-weight: bold; }
.sec-null { color: #d9534f; font-weight: bold; }
.checkbox-inline { font-weight: normal; white-space: nowrap; }
.type-picker .checkbox-inline { margin: 0 14px 0 0; }
.num { text-align: right; }
.zero { opacity: 0.35; }
.wanted { font-weight: bold; }
.materials { font-size: 90%; }
</style>
</head>
<body>
<?php include('/home/web/fuzzwork/htdocs/menu/menubootstrap.php'); ?>
<div class="container">
<?php
}

function page_foot()
{
?>
</div>
<?php include('/home/web/fuzzwork/htdocs/bootstrap/footer.php'); ?>
</body>
</html>
<?php
}
