<?php
// JSON data source for jump.php (the WebGPU jump drive route planner).
//
// Everything the planner needs in one request:
//   systems - every k-space system, coordinates in raw SDE metres at full
//             double precision (the client does all range maths on these)
//   regions - regionID => name
//   ships   - published ships with a jump drive, stats read live from dogma
//   skills  - per-level bonuses of the jump skills, read live from dogma
//   noCyno  - regions where a cyno can't be lit / jumped into

require_once(__DIR__.'/../db.inc.php');

header('Content-Type: application/json');
header('Cache-Control: public, max-age=86400');

// shortest round-trip representation of every double - no rounding
ini_set('serialize_precision', '-1');

$dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// dgmTypeAttributes.valueFloat and invTraits.bonus are single-precision
// float columns, so 0.1 arrives as 0.10000000149011612. Seven significant
// digits is all a float32 holds, which recovers the SDE's own decimal
// exactly. (Coordinates and security are doubles and are passed as-is.)
function dogma($v)
{
return $v===null ? null : (float)sprintf('%.7g', (float)$v);
}

// dogma attributes
define('ATTR_JUMP_FUEL_TYPE', 866);
define('ATTR_JUMP_RANGE', 867);
define('ATTR_JUMP_FUEL_AMOUNT', 868);
define('ATTR_JUMP_RANGE_BONUS', 870);
define('ATTR_FUEL_BONUS', 1296);
define('ATTR_FATIGUE_MULTIPLIER', 1971);

// skills
define('SKILL_JUMP_DRIVE_CALIBRATION', 21611);
define('SKILL_JUMP_FUEL_CONSERVATION', 21610);

// Jove regions (UUA-F4, J7HZ-F, A821-A) are unreachable, Pochven and
// Zarzakh's region (Yasna Zakh) don't allow cynos
$noCyno=array(10000004, 10000017, 10000019, 10000070, 10001000);

$systems=array('id'=>array(), 'name'=>array(), 'x'=>array(), 'y'=>array(), 'z'=>array(), 'sec'=>array(), 'region'=>array());
$sql="select solarSystemID, solarSystemName, x, y, z, security, regionID
from mapSolarSystems
where regionID < 11000000
order by solarSystemName";
$stmt = $dbh->prepare($sql);
$stmt->execute();
while ($row = $stmt->fetch())
{
$systems['id'][]=(int)$row['solarSystemID'];
$systems['name'][]=$row['solarSystemName'];
$systems['x'][]=(float)$row['x'];
$systems['y'][]=(float)$row['y'];
$systems['z'][]=(float)$row['z'];
$systems['sec'][]=(float)$row['security'];
$systems['region'][]=(int)$row['regionID'];
}

$regions=array();
$sql="select regionID, regionName from mapRegions where regionID < 11000000 order by regionName";
$stmt = $dbh->prepare($sql);
$stmt->execute();
while ($row = $stmt->fetch())
{
$regions[(string)$row['regionID']]=$row['regionName'];
}

$sql="select t.typeID, t.typeName, g.groupName,
       max(case when a.attributeID = :range then coalesce(a.valueFloat, a.valueInt) end) jumpRange,
       max(case when a.attributeID = :amount then coalesce(a.valueFloat, a.valueInt) end) fuelAmount,
       max(case when a.attributeID = :fueltype then coalesce(a.valueFloat, a.valueInt) end) fuelTypeID,
       max(case when a.attributeID = :fatigue then coalesce(a.valueFloat, a.valueInt) end) fatigueMultiplier
from invTypes t
join invGroups g on g.groupID = t.groupID
join dgmTypeAttributes a on a.typeID = t.typeID
where t.published = 1 and g.categoryID = 6
  and a.attributeID in (:range2, :amount2, :fueltype2, :fatigue2)
group by t.typeID, t.typeName, g.groupName
having jumpRange is not null
order by g.groupName, t.typeName";
$stmt = $dbh->prepare($sql);
$stmt->execute(array(
    ':range'=>ATTR_JUMP_RANGE, ':amount'=>ATTR_JUMP_FUEL_AMOUNT,
    ':fueltype'=>ATTR_JUMP_FUEL_TYPE, ':fatigue'=>ATTR_FATIGUE_MULTIPLIER,
    ':range2'=>ATTR_JUMP_RANGE, ':amount2'=>ATTR_JUMP_FUEL_AMOUNT,
    ':fueltype2'=>ATTR_JUMP_FUEL_TYPE, ':fatigue2'=>ATTR_FATIGUE_MULTIPLIER
));
$ships=array();
$fuelTypes=array();
while ($row = $stmt->fetch())
{
$fuelTypeID=(int)$row['fuelTypeID'];
$fuelTypes[$fuelTypeID]=true;
$ships[(int)$row['typeID']]=array(
    'typeID'=>(int)$row['typeID'],
    'name'=>$row['typeName'],
    'group'=>$row['groupName'],
    'range'=>dogma($row['jumpRange']),
    'fuelAmount'=>dogma($row['fuelAmount']),
    'fuelTypeID'=>$fuelTypeID,
    // ships without the attribute take full fatigue
    'fatigueMultiplier'=>$row['fatigueMultiplier']===null ? 1.0 : dogma($row['fatigueMultiplier']),
    'hullFuelBonus'=>null
);
}

$fuelNames=array();
if (count($fuelTypes))
{
$ids=array_keys($fuelTypes);
$stmt = $dbh->prepare('select typeID, typeName from invTypes where typeID in ('.implode(',', array_fill(0, count($ids), '?')).')');
$stmt->execute($ids);
while ($row = $stmt->fetch())
{
$fuelNames[(string)$row['typeID']]=$row['typeName'];
}
}

// hull fuel bonuses (the Jump Freighters skill on JFs) are only described
// by ship traits, so read the per-level reduction from there
$sql="select tr.typeID, tr.skillID, tr.bonus, s.typeName skillName
from invTraits tr
join invTypes s on s.typeID = tr.skillID
where tr.bonusText like '%jump fuel%'";
$stmt = $dbh->prepare($sql);
$stmt->execute();
while ($row = $stmt->fetch())
{
$typeID=(int)$row['typeID'];
if (isset($ships[$typeID]))
{
$ships[$typeID]['hullFuelBonus']=array(
    'skillID'=>(int)$row['skillID'],
    'skillName'=>$row['skillName'],
    // percent reduction per level
    'perLevel'=>dogma($row['bonus'])
);
}
}

$skills=array();
$sql="select t.typeID, t.typeName, coalesce(a.valueFloat, a.valueInt) value
from invTypes t
join dgmTypeAttributes a on a.typeID = t.typeID
where (t.typeID = :jdc and a.attributeID = :jdcattr) or (t.typeID = :jfc and a.attributeID = :jfcattr)";
$stmt = $dbh->prepare($sql);
$stmt->execute(array(
    ':jdc'=>SKILL_JUMP_DRIVE_CALIBRATION, ':jdcattr'=>ATTR_JUMP_RANGE_BONUS,
    ':jfc'=>SKILL_JUMP_FUEL_CONSERVATION, ':jfcattr'=>ATTR_FUEL_BONUS
));
while ($row = $stmt->fetch())
{
$key=((int)$row['typeID']==SKILL_JUMP_DRIVE_CALIBRATION) ? 'rangeBonus' : 'fuelBonus';
// percent per level: +20 range, -10 fuel
$skills[$key]=array('typeID'=>(int)$row['typeID'], 'name'=>$row['typeName'], 'perLevel'=>dogma($row['value']));
}

echo json_encode(array(
    'metresPerLy'=>9460730472580800,
    'systems'=>$systems,
    'regions'=>$regions,
    'ships'=>array_values($ships),
    'fuelNames'=>$fuelNames,
    'skills'=>$skills,
    'noCyno'=>$noCyno
));
