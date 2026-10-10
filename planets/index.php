<?php
require_once(__DIR__.'/common.php');

$ptypes = pi_planet_types($dbh);
$commodities = pi_commodities($dbh);
$tiernames = array('Raw materials (P0)', 'Basic commodities (P1)', 'Refined commodities (P2)', 'Specialized commodities (P3)', 'Advanced commodities (P4)');

// Raw material typeID => planet typeIDs it can be extracted from.
$sources = array();
foreach ($ptypes as $id => $t) {
    foreach ($t['materials'] as $mid => $name) {
        $sources[$mid][] = $id;
    }
}

$origin = isset($_GET['origin']) ? trim($_GET['origin']) : 'Jita';
$wanted = array();
if (isset($_GET['types']) && is_array($_GET['types'])) {
    foreach ($_GET['types'] as $t) {
        if (isset($ptypes[(int)$t])) {
            $wanted[] = (int)$t;
        }
    }
}
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
uksort($needs, function($a, $b) use ($commodities) { return strcmp($commodities[$a]['name'], $commodities[$b]['name']); });
$maxjumps = isset($_GET['maxjumps']) && is_numeric($_GET['maxjumps']) ? (int)$_GET['maxjumps'] : 10;
$maxjumps = max(0, min(50, $maxjumps));
$highroute = isset($_GET['route']) && $_GET['route'] == 1 ? 1 : 0;
$secfilter = isset($_GET['sec']) && in_array($_GET['sec'], array('high', 'low', 'null')) ? $_GET['sec'] : 'any';
$station = !empty($_GET['station']);
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 50;
$limit = max(10, min(200, $limit));
$searched = isset($_GET['origin']);

$names = array();
foreach ($dbh->query('select solarSystemName from mapSolarSystems order by solarSystemName', PDO::FETCH_NUM) as $row) {
    $names[] = $row[0];
}

$error = '';
$results = array();
$originid = null;
if ($searched) {
    $stmt = $dbh->prepare('select solarSystemID, solarSystemName from mapSolarSystems where solarSystemName=?');
    $stmt->execute(array($origin));
    $row = $stmt->fetchObject();
    if (!$row) {
        $error = 'Unknown starting system "'.$origin.'".';
    } else {
        $originid = (int)$row->solarSystemID;
        $origin = $row->solarSystemName;

        $counts = array();
        foreach ($ptypes as $id => $t) {
            $counts[] = "sum(d.typeID=$id) t$id";
        }
        $having = array();
        foreach ($wanted as $id) {
            $having[] = "t$id>0";
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
        $sql = "select ms.solarSystemID, ms.solarSystemName, ms.security, r.regionName,
                       mr.pathLength-1 jumps, count(*) planets, ".implode(', ', $counts).",
                       (select count(*) from staStations s where s.solarSystemID=ms.solarSystemID) stations
                from evesupport.mapRoutes mr
                join mapSolarSystems ms on (ms.solarSystemID=mr.toSolarSystemID)
                join mapRegions r on (r.regionID=ms.regionID)
                join mapDenormalize d on (d.solarSystemID=ms.solarSystemID and d.groupID=7)
                where mr.fromSolarSystemID=:origin and mr.highSecOnly=:high and mr.pathLength<=:maxpath $where
                group by ms.solarSystemID, ms.solarSystemName, ms.security, r.regionName, mr.pathLength
                ".($having ? 'having '.implode(' and ', $having) : '')."
                order by jumps, ms.solarSystemName
                limit $limit";
        $stmt = $dbh->prepare($sql);
        $stmt->bindValue(':origin', $originid, PDO::PARAM_INT);
        $stmt->bindValue(':high', $highroute, PDO::PARAM_INT);
        $stmt->bindValue(':maxpath', $maxjumps + 1, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_OBJ);

        if (!$results) {
            $check = $dbh->prepare('select 1 from evesupport.mapRoutes where fromSolarSystemID=? and highSecOnly=? limit 1');
            $check->execute(array($originid, $highroute));
            if (!$check->fetch()) {
                $error = $highroute
                    ? "There's no high-sec-only route data from $origin. It may not be in high-sec; try \"Through any security\"."
                    : "There's no route data for $origin.";
            }
        }
    }
}

page_head('Planet Finder');
?>
<h1>Planet Finder</h1>
<p>Find the nearest systems containing the planet types you need for planetary interaction. A system must contain at least one of every planet type you tick, and planets that can extract every raw material the chosen commodities are made from.</p>

<form method="get" class="form-horizontal" role="form">
<div class="form-group">
    <label for="origin" class="col-sm-2 control-label">Starting system</label>
    <div class="col-sm-4"><input type="text" class="form-control" name="origin" id="origin" value="<?php echo h($origin); ?>"></div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">Planet types</label>
    <div class="col-sm-10 type-picker">
<?php foreach ($ptypes as $id => $t) { ?>
        <label class="checkbox-inline" title="<?php echo h(implode(', ', $t['materials'])); ?>"><input type="checkbox" name="types[]" value="<?php echo $id; ?>"<?php echo in_array($id, $wanted) ? ' checked' : ''; ?>> <?php echo h($t['name']); ?></label>
<?php } ?>
    </div>
</div>
<div class="form-group">
    <label for="commodity" class="col-sm-2 control-label">Commodities</label>
    <div class="col-sm-6">
        <select multiple size="8" class="form-control" name="commodity[]" id="commodity">
<?php foreach ($tiernames as $tier => $tiername) { ?>
            <optgroup label="<?php echo h($tiername); ?>">
<?php foreach ($commodities as $id => $c) { if ($c['tier'] != $tier) continue; ?>
                <option value="<?php echo $id; ?>"<?php echo in_array($id, $picked) ? ' selected' : ''; ?>><?php echo h($c['name']); ?></option>
<?php } ?>
            </optgroup>
<?php } ?>
        </select>
        <p class="help-block">Ctrl/Cmd-click to pick several. Processed commodities are traced back to their raw materials.</p>
    </div>
</div>
<div class="form-group">
    <label for="maxjumps" class="col-sm-2 control-label">Max jumps</label>
    <div class="col-sm-2"><input type="number" min="0" max="50" class="form-control" name="maxjumps" id="maxjumps" value="<?php echo $maxjumps; ?>"></div>
    <label for="route" class="col-sm-2 control-label">Route</label>
    <div class="col-sm-4">
        <select name="route" id="route" class="form-control">
            <option value="0">Through any security</option>
            <option value="1"<?php echo $highroute ? ' selected' : ''; ?>>Through high security only</option>
        </select>
    </div>
</div>
<div class="form-group">
    <label for="sec" class="col-sm-2 control-label">System security</label>
    <div class="col-sm-2">
        <select name="sec" id="sec" class="form-control">
<?php foreach (array('any' => 'Any', 'high' => 'High sec', 'low' => 'Low sec', 'null' => 'Null sec') as $k => $v) { ?>
            <option value="<?php echo $k; ?>"<?php echo $secfilter == $k ? ' selected' : ''; ?>><?php echo $v; ?></option>
<?php } ?>
        </select>
    </div>
    <label for="limit" class="col-sm-2 control-label">Show</label>
    <div class="col-sm-2">
        <select name="limit" id="limit" class="form-control">
<?php foreach (array(25, 50, 100, 200) as $l) { ?>
            <option<?php echo $limit == $l ? ' selected' : ''; ?>><?php echo $l; ?></option>
<?php } ?>
        </select>
    </div>
    <div class="col-sm-4"><label class="checkbox-inline"><input type="checkbox" name="station" value="1"<?php echo $station ? ' checked' : ''; ?>> Must have an NPC station</label></div>
</div>
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-10"><button type="submit" class="btn btn-primary">Find systems</button></div>
</div>
</form>

<?php if ($error) { ?>
<div class="alert alert-warning"><?php echo h($error); ?></div>
<?php } elseif ($searched && !$results) { ?>
<div class="alert alert-info">No systems within <?php echo $maxjumps; ?> jumps of <?php echo h($origin); ?> match.</div>
<?php } ?>
<?php if ($picked) { ?>
<ul class="list-unstyled">
<?php foreach ($picked as $id) { ?>
<li><strong><?php echo h($commodities[$id]['name']); ?></strong> needs <?php
    $rawnames = array();
    foreach (array_keys(pi_raw_needs($commodities, $id)) as $mid) {
        $rawnames[] = h($commodities[$mid]['name']);
    }
    sort($rawnames);
    echo implode(', ', $rawnames);
?></li>
<?php } ?>
</ul>
<?php } ?>
<?php if ($results) {
    $linkextra = '';
    foreach ($picked as $id) {
        $linkextra .= '&amp;commodity[]='.$id;
    }
?>
<p><?php echo count($results); ?> system<?php echo count($results) == 1 ? '' : 's'; ?> within <?php echo $maxjumps; ?> jumps of <?php echo h($origin); ?><?php echo count($results) == $limit ? ' (limit reached)' : ''; ?>. Click a system to see its planets.</p>
<div class="table-responsive">
<table class="table table-striped table-condensed" id="results">
<thead>
<tr><th>System</th><th>Region</th><th>Security</th><th class="num">Jumps</th>
<?php foreach ($needs as $mid => $x) {
    $from = array();
    foreach ($sources[$mid] as $pid) {
        $from[] = $ptypes[$pid]['name'];
    }
?>
<th class="num wanted" title="Planets yielding it: <?php echo h(implode(', ', $from)); ?>"><?php echo h($commodities[$mid]['name']); ?></th>
<?php } ?>
<?php foreach ($ptypes as $id => $t) { ?>
<th class="num<?php echo in_array($id, $wanted) ? ' wanted' : ''; ?>"><?php echo h($t['name']); ?></th>
<?php } ?>
<th class="num">Planets</th><th class="num">Stations</th></tr>
</thead>
<tbody>
<?php foreach ($results as $r) { ?>
<tr>
<td><a href="system.php?system=<?php echo (int)$r->solarSystemID; ?>&amp;origin=<?php echo urlencode($origin).$linkextra; ?>"><?php echo h($r->solarSystemName); ?></a></td>
<td><?php echo h($r->regionName); ?></td>
<td class="<?php echo sec_class($r->security); ?>"><?php echo sec_display($r->security); ?></td>
<td class="num"><?php echo (int)$r->jumps; ?></td>
<?php foreach ($needs as $mid => $x) {
    $c = 0;
    foreach ($sources[$mid] as $pid) {
        $c += (int)$r->{"t$pid"};
    }
?>
<td class="num wanted"><?php echo $c; ?></td>
<?php } ?>
<?php foreach ($ptypes as $id => $t) { $c = (int)$r->{"t$id"}; ?>
<td class="num<?php echo $c ? '' : ' zero'; ?><?php echo in_array($id, $wanted) ? ' wanted' : ''; ?>"><?php echo $c; ?></td>
<?php } ?>
<td class="num"><?php echo (int)$r->planets; ?></td>
<td class="num<?php echo $r->stations ? '' : ' zero'; ?>"><?php echo (int)$r->stations; ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<?php } ?>

<script>
var systemNames = <?php echo json_encode($names); ?>;
$(function() {
    $('#origin').autocomplete({ source: function(req, resp) {
        var term = req.term.toLowerCase(), out = [];
        for (var i = 0; i < systemNames.length && out.length < 20; i++) {
            if (systemNames[i].toLowerCase().indexOf(term) === 0) out.push(systemNames[i]);
        }
        resp(out);
    }, minLength: 2 });
});
</script>
<?php page_foot(); ?>
