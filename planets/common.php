<?php
require_once(__DIR__.'/../db.inc.php');
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

define('AU_METRES', 149597870700);

// Systems where planets can't be colonised. CCP doesn't export this in the
// SDE; the list comes from the EVE University wiki
// (https://wiki.eveuniversity.org/Planetary_Industry), which gives the
// reason as "high traffic or storyline reasons".
$pi_restricted_systems = array(
    30002187, // Amarr
    30005001, // Arnon
    30002657, // Aunia
    30002656, // Auvergne
    30002528, // Balginia
    30002659, // Dodixie
    30002734, // Fricoure
    30045329, // Ichoriya
    30002792, // Irjunen
    30002739, // Isaziwa
    30001426, // Isinokka
    30000142, // Jita
    30002545, // Lustrevik
    30002819, // Motsu
    30004969, // Oursulaert
    30002510, // Rens
    30002783, // Sankkasen
    30001409, // Umokka
);

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

// Raw material typeID => planet typeIDs it can be extracted from.
function pi_sources($ptypes)
{
    $sources = array();
    foreach ($ptypes as $id => $t) {
        foreach ($t['materials'] as $mid => $name) {
            $sources[$mid][] = $id;
        }
    }
    return $sources;
}

// Planet type name without the "Planet (...)" wrapper.
function planet_type_name($typeName)
{
    return preg_replace('/^Planet \((.*)\)$/', '$1', $typeName);
}

// A solar system by ID (all digits) or exact name, with its constellation,
// region and star. Returns an object, or false if there's no such system.
function pi_lookup_system($dbh, $key)
{
    $sql = "select ms.solarSystemID, ms.solarSystemName, ms.security, c.constellationName, r.regionName, st.typeName star
            from mapSolarSystems ms
            join mapConstellations c on (c.constellationID=ms.constellationID)
            join mapRegions r on (r.regionID=ms.regionID)
            left join mapDenormalize sd on (sd.solarSystemID=ms.solarSystemID and sd.groupID=6)
            left join invTypes st on (st.typeID=sd.typeID)
            where ".(ctype_digit((string)$key) ? 'ms.solarSystemID=?' : 'ms.solarSystemName=?');
    $stmt = $dbh->prepare($sql);
    $stmt->execute(array($key));
    return $stmt->fetchObject();
}

// Systems near $originid that contain every planet type in $wanted and a
// source for every raw material in $needs (typeID => true).
// Returns array('results'=>rows, 'excluded'=>rows, 'error'=>string). Each
// row has solarSystemID, solarSystemName, security, regionName, jumps,
// planets, stations and a t<planetTypeID> count per PI planet type.
// 'excluded' holds systems that matched but don't allow PI.
function pi_find_systems($dbh, $ptypes, $originid, $wanted, $needs, $maxjumps, $highroute, $secfilter, $station, $limit)
{
    global $pi_restricted_systems;
    $sources = pi_sources($ptypes);

    $counts = array();
    foreach ($ptypes as $id => $t) {
        $counts[] = "sum(d.typeID=".(int)$id.") t".(int)$id;
    }
    $having = array();
    foreach ($wanted as $id) {
        $having[] = "t".(int)$id.">0";
    }
    // Each raw material needs at least one planet of a type that yields it.
    foreach ($needs as $mid => $x) {
        $having[] = '(t'.implode('+t', $sources[$mid]).')>0';
    }
    $where = '';
    if ($secfilter == 'high') {
        $where .= ' and ms.security>=0.45';
    } elseif ($secfilter == 'low') {
        $where .= ' and ms.security>0 and ms.security<0.45';
    } elseif ($secfilter == 'null') {
        $where .= ' and ms.security<=0';
    }
    if ($station) {
        $where .= ' and exists (select 1 from staStations s where s.solarSystemID=ms.solarSystemID)';
    }

    // mapRoutes stores the origin itself as pathLength 1.
    $restricted = implode(',', $pi_restricted_systems);
    $sql = "select ms.solarSystemID, ms.solarSystemName, ms.security, r.regionName,
                   mr.pathLength-1 jumps, count(*) planets, ".implode(', ', $counts).",
                   (select count(*) from staStations s where s.solarSystemID=ms.solarSystemID) stations
            from evesupport.mapRoutes mr
            join mapSolarSystems ms on (ms.solarSystemID=mr.toSolarSystemID)
            join mapRegions r on (r.regionID=ms.regionID)
            join mapDenormalize d on (d.solarSystemID=ms.solarSystemID and d.groupID=7)
            where mr.fromSolarSystemID=:origin and mr.highSecOnly=:high and mr.pathLength<=:maxpath $where
            and ms.solarSystemID %s ($restricted)
            group by ms.solarSystemID, ms.solarSystemName, ms.security, r.regionName, mr.pathLength
            ".($having ? 'having '.implode(' and ', $having) : '')."
            order by jumps, ms.solarSystemName
            %s";
    $run = function($sql) use ($dbh, $originid, $highroute, $maxjumps) {
        $stmt = $dbh->prepare($sql);
        $stmt->bindValue(':origin', (int)$originid, PDO::PARAM_INT);
        $stmt->bindValue(':high', $highroute ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':maxpath', (int)$maxjumps + 1, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    };
    $results = $run(sprintf($sql, 'not in', "limit ".(int)$limit));
    // Restricted systems that would otherwise have matched, so we can say what was left out.
    $excluded = $run(sprintf($sql, 'in', ''));

    $error = '';
    if (!$results) {
        $check = $dbh->prepare('select 1 from evesupport.mapRoutes where fromSolarSystemID=? and highSecOnly=? limit 1');
        $check->execute(array($originid, $highroute ? 1 : 0));
        if (!$check->fetch()) {
            $stmt = $dbh->prepare('select solarSystemName from mapSolarSystems where solarSystemID=?');
            $stmt->execute(array($originid));
            $origin = $stmt->fetchColumn();
            $error = $highroute
                ? "There's no high-sec-only route data from $origin. It may not be in high-sec; try \"Through any security\"."
                : "There's no route data for $origin.";
        }
    }
    return array('results' => $results, 'excluded' => $excluded, 'error' => $error);
}

// Planets in a system, keyed by itemID, in orbit order. Each has itemID,
// typeID, itemName, celestialIndex, x, y, z, radius, typeName, temperature,
// plus counts of the moons, belts and stations around it.
function pi_system_planets($dbh, $systemid)
{
    $sql = "select d.itemID, d.typeID, d.itemName, d.celestialIndex, d.x, d.y, d.z, d.radius, t.typeName, cs.temperature
            from mapDenormalize d
            join invTypes t on (t.typeID=d.typeID)
            left join mapCelestialStatistics cs on (cs.celestialID=d.itemID)
            where d.solarSystemID=? and d.groupID=7
            order by d.celestialIndex";
    $stmt = $dbh->prepare($sql);
    $stmt->execute(array($systemid));
    $planets = array();
    foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $p) {
        $p->moons = 0;
        $p->belts = 0;
        $p->stations = 0;
        $planets[(int)$p->itemID] = $p;
    }

    // Moons and belts orbit a planet; stations orbit either a planet or one of its moons.
    $stmt = $dbh->prepare('select itemID, groupID, orbitID from mapDenormalize where solarSystemID=? and groupID in (8,9,15)');
    $stmt->execute(array($systemid));
    $moonplanet = array();
    $stations = array();
    foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $o) {
        if ($o->groupID == 15) {
            $stations[] = (int)$o->orbitID;
        } elseif (isset($planets[(int)$o->orbitID])) {
            if ($o->groupID == 8) {
                $planets[(int)$o->orbitID]->moons++;
                $moonplanet[(int)$o->itemID] = (int)$o->orbitID;
            } else {
                $planets[(int)$o->orbitID]->belts++;
            }
        }
    }
    foreach ($stations as $orbit) {
        if (isset($planets[$orbit])) {
            $planets[$orbit]->stations++;
        } elseif (isset($moonplanet[$orbit])) {
            $planets[$moonplanet[$orbit]]->stations++;
        }
    }
    return $planets;
}

// Orbit radius of a planet row in AU.
function planet_orbit_au($p)
{
    return sqrt((float)$p->x * (float)$p->x + (float)$p->y * (float)$p->y + (float)$p->z * (float)$p->z) / AU_METRES;
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
/* Bootstrap 3 only scrolls these below 768px; wide commodity searches need it at any width. */
.table-responsive { overflow-x: auto; }
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
