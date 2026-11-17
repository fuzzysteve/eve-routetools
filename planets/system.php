<?php
require_once(__DIR__.'/common.php');

$ptypes = pi_planet_types($dbh);
$commodities = pi_commodities($dbh);
$origin = isset($_GET['origin']) ? trim($_GET['origin']) : '';
$picked = array();
$needs = array();
if (isset($_GET['commodity']) && is_array($_GET['commodity'])) {
    foreach ($_GET['commodity'] as $c) {
        if (isset($commodities[(int)$c]) && !in_array((int)$c, $picked)) {
            $picked[] = (int)$c;
            pi_raw_needs($commodities, (int)$c, $needs);
        }
    }
}
$backquery = $origin !== '' ? 'origin='.urlencode($origin) : '';
foreach ($picked as $id) {
    $backquery .= ($backquery ? '&amp;' : '').'commodity[]='.$id;
}
$key = isset($_GET['system']) ? trim($_GET['system']) : '';

$system = pi_lookup_system($dbh, $key);

if (!$system) {
    page_head('Planet Finder');
    echo '<div class="alert alert-warning">Unknown system "'.h($key).'".</div><p><a href="index.php">Back to the Planet Finder</a></p>';
    page_foot();
    exit;
}
$systemid = (int)$system->solarSystemID;

$planets = pi_system_planets($dbh, $systemid);

$summary = array();
foreach ($planets as $p) {
    $name = preg_replace('/^Planet \((.*)\)$/', '$1', $p->typeName);
    $summary[$name] = isset($summary[$name]) ? $summary[$name] + 1 : 1;
}
ksort($summary);

page_head($system->solarSystemName.' planets');
?>
<p><a href="index.php<?php echo $backquery ? '?'.$backquery : ''; ?>">&larr; Planet Finder</a></p>
<h1><?php echo h($system->solarSystemName); ?> <small class="<?php echo sec_class($system->security); ?>"><?php echo sec_display($system->security); ?></small></h1>
<p><?php echo h($system->constellationName); ?> &middot; <?php echo h($system->regionName); ?><?php echo $system->star ? ' &middot; '.h($system->star) : ''; ?></p>
<?php if (in_array($systemid, $pi_restricted_systems)) { ?>
<div class="alert alert-warning">Planetary interaction isn't allowed in <?php echo h($system->solarSystemName); ?>. Its planets can't be colonised.</div>
<?php } ?>
<p><strong><?php echo count($planets); ?> planet<?php echo count($planets) == 1 ? '' : 's'; ?></strong><?php
foreach ($summary as $name => $c) {
    echo ' &middot; '.$c.' '.h($name);
}
?></p>
<?php if ($needs) {
    $missing = array();
    foreach ($needs as $mid => $x) {
        $found = false;
        foreach ($planets as $p) {
            if (isset($ptypes[(int)$p->typeID]['materials'][$mid])) {
                $found = true;
            }
        }
        if (!$found) {
            $missing[] = h($commodities[$mid]['name']);
        }
    }
    sort($missing);
    $pickednames = array();
    foreach ($picked as $id) {
        $pickednames[] = h($commodities[$id]['name']);
    }
?>
<p>Raw materials for <?php echo implode(', ', $pickednames); ?> are shown in bold.<?php echo $missing ? ' <strong>Not available here:</strong> '.implode(', ', $missing).'.' : ''; ?></p>
<?php } ?>

<?php if (!$planets) { ?>
<div class="alert alert-info">This system has no planets.</div>
<?php } else { ?>
<div class="table-responsive">
<table class="table table-striped table-condensed" id="planets">
<thead>
<tr><th>Planet</th><th>Type</th><th class="num">Orbit (AU)</th><th class="num">Radius (km)</th><th class="num">Temp (K)</th><th class="num">Moons</th><th class="num">Belts</th><th class="num">Stations</th><th>PI resources</th></tr>
</thead>
<tbody>
<?php foreach ($planets as $p) {
    $au = planet_orbit_au($p);
?>
<tr>
<td><?php echo h($p->itemName); ?></td>
<td><?php echo h(preg_replace('/^Planet \((.*)\)$/', '$1', $p->typeName)); ?></td>
<td class="num"><?php echo number_format($au, 2); ?></td>
<td class="num"><?php echo number_format($p->radius / 1000); ?></td>
<td class="num"><?php echo $p->temperature === null ? '' : number_format($p->temperature); ?></td>
<td class="num<?php echo $p->moons ? '' : ' zero'; ?>"><?php echo $p->moons; ?></td>
<td class="num<?php echo $p->belts ? '' : ' zero'; ?>"><?php echo $p->belts; ?></td>
<td class="num<?php echo $p->stations ? '' : ' zero'; ?>"><?php echo $p->stations; ?></td>
<td class="materials"><?php
if (isset($ptypes[(int)$p->typeID])) {
    $mats = array();
    foreach ($ptypes[(int)$p->typeID]['materials'] as $mid => $name) {
        $mats[] = isset($needs[$mid]) ? '<strong>'.h($name).'</strong>' : h($name);
    }
    echo implode(', ', $mats);
} else {
    echo '<span class="zero">None (no PI)</span>';
}
?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<?php } ?>
<?php page_foot(); ?>
