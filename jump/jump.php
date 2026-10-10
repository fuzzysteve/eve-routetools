<!DOCTYPE HTML>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jump Planner</title>
<!--
    WebGPU jump drive route planner. Data comes from jump-data.php; route
    maths is in jump-route.js, the app in jump.js, rendering in
    jump-render.js. Parameters (all optional, kept in sync with the form):
    ?from=<name>&to=<name>&ship=<typeID>&jdc=&jfc=&hull=&cynos=&fatigue=&avoid=<name>,r:<region>
-->
<style>
:root {
    --bg: #010103;
    --panel: rgba(8, 12, 20, 0.86);
    --panel-border: rgba(120, 160, 200, 0.18);
    --text: #d6e2ee;
    --muted: #7d8fa3;
    --accent: #6cc4ff;
    --warn: #ff7a59;
    --ok: #7be38a;
    --hover: rgba(108, 196, 255, 0.12);
    --sel: rgba(108, 196, 255, 0.24);
    --font: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
* { box-sizing: border-box; }
html, body {
    margin: 0;
    height: 100%;
    overflow: hidden;
    background: var(--bg);
    color: var(--text);
    font: 13px/1.35 var(--font);
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
button, select, input {
    font: inherit;
    color: var(--text);
    background: rgba(0, 0, 0, 0.5);
    border: 1px solid var(--panel-border);
    border-radius: 4px;
    padding: 4px 8px;
    outline: none;
}
select option, select optgroup { background: #0b111b; color: var(--text); }
input:focus, select:focus { border-color: var(--accent); }
button { background: rgba(108, 196, 255, 0.12); cursor: pointer; }
button:hover { background: rgba(108, 196, 255, 0.25); }

#viewport { position: fixed; inset: 0; touch-action: none; }
#gpu { width: 100%; height: 100%; display: block; cursor: grab; }
#gpu.dragging { cursor: grabbing; }
#labels { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 1; }
.lbl {
    position: absolute;
    left: 0;
    top: 0;
    white-space: nowrap;
    font-size: 11px;
    line-height: 13px;
    text-shadow: 0 0 3px #000, 0 0 2px #000;
    will-change: transform;
}
.lbl .s { display: block; color: var(--muted); font-size: 10px; }
.lbl.origin { color: var(--ok); font-weight: 600; }
.lbl.dest { color: var(--accent); font-weight: 600; }
.lbl.nocyno { color: var(--warn); }
.lbl.hover { color: #fff; }

#gpu-msg {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    max-width: 420px;
    padding: 14px 18px;
    background: var(--panel);
    border: 1px solid var(--panel-border);
    border-radius: 6px;
    color: var(--muted);
    text-align: center;
    display: none;
}

.panel {
    position: fixed;
    background: var(--panel);
    border: 1px solid var(--panel-border);
    border-radius: 6px;
    backdrop-filter: blur(6px);
    z-index: 2;
}
#controls {
    top: 10px;
    left: 10px;
    width: 300px;
    max-height: calc(100% - 20px);
    overflow-y: auto;
    padding: 10px 12px 12px;
}
#controls h1 { font-size: 15px; margin: 0 0 8px; font-weight: 600; }
#controls h1 a { font-weight: 400; font-size: 11px; margin-left: 6px; }
.field { margin-bottom: 8px; }
.field label { display: block; color: var(--muted); font-size: 11px; margin-bottom: 2px; }
.field input[type=text], .field select { width: 100%; }
.row { display: flex; gap: 6px; align-items: flex-end; }
.row > .field { flex: 1; min-width: 0; }
.row > .field select, .row > .field input { width: 100%; }
.skills { display: grid; grid-template-columns: 1fr auto; gap: 4px 8px; align-items: center; margin-bottom: 8px; }
.skills label { color: var(--muted); font-size: 11px; }
.skills select { width: 56px; }
.stat { color: var(--muted); font-size: 12px; margin: 2px 0 8px; }
.stat b { color: var(--text); font-weight: 600; }
#chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 4px 1px 8px;
    border: 1px solid var(--panel-border);
    border-radius: 10px;
    background: rgba(255, 122, 89, 0.12);
    font-size: 11px;
}
.chip button { padding: 0 5px; border: 0; background: none; color: var(--muted); }
.chip button:hover { color: var(--text); }
.toggles { display: flex; flex-direction: column; gap: 3px; margin: 6px 0 8px; color: var(--muted); font-size: 12px; }
.toggles input { margin: 0 6px 0 0; vertical-align: -2px; }
.buttons { display: flex; gap: 6px; flex-wrap: wrap; }
#msg { margin-top: 8px; font-size: 12px; min-height: 16px; }
#msg.err { color: var(--warn); }
.help { color: var(--muted); font-size: 11px; margin-top: 8px; border-top: 1px solid var(--panel-border); padding-top: 6px; }

#results {
    left: 320px;
    right: 10px;
    bottom: 10px;
    max-height: 42%;
    display: flex;
    flex-direction: column;
}
#results.hidden { display: none; }
#summary { padding: 8px 12px; border-bottom: 1px solid var(--panel-border); font-size: 12px; color: var(--muted); }
#summary b { color: var(--text); font-weight: 600; }
#summary .warn { color: var(--warn); }
#table-wrap { overflow: auto; }
table { border-collapse: collapse; width: 100%; font-size: 12px; }
th, td { padding: 4px 8px; text-align: left; white-space: nowrap; }
/* cell-level backgrounds so a row state paints every cell */
th { position: sticky; top: 0; background: #0b111b; color: var(--muted); font-weight: 500; z-index: 1; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
tbody tr { cursor: pointer; }
tbody tr:hover td { background: var(--hover); }
tbody tr.sel td { background: var(--sel); }
tr.nocyno td.cyno { color: var(--warn); }
td.cyno b { color: var(--accent); font-weight: 600; }
.sec { font-variant-numeric: tabular-nums; }

#tooltip {
    position: fixed;
    pointer-events: none;
    padding: 4px 8px;
    background: var(--panel);
    border: 1px solid var(--panel-border);
    border-radius: 4px;
    font-size: 12px;
    display: none;
    z-index: 5;
}
#tooltip .s { color: var(--muted); font-size: 11px; }

@media (max-width: 800px) {
    html, body { overflow: auto; height: auto; }
    #viewport { position: relative; height: 55vh; }
    #labels { position: absolute; top: 0; height: 55vh; }
    .panel { position: static; margin: 8px; width: auto; max-height: none; }
    #controls { width: auto; }
    #results { max-height: none; }
}
</style>
</head>
<body>
<div id="controls" class="panel">
    <h1>Jump Planner <a href="../">Route planners</a></h1>
    <div class="field">
        <label for="origin">Origin</label>
        <input type="text" id="origin" list="system-list" autocomplete="off" spellcheck="false" placeholder="System name">
    </div>
    <div class="field">
        <label for="dest">Destination</label>
        <input type="text" id="dest" list="system-list" autocomplete="off" spellcheck="false" placeholder="System name">
    </div>
    <div class="field">
        <label for="ship">Ship</label>
        <select id="ship"></select>
    </div>
    <div class="skills">
        <label for="jdc">Jump Drive Calibration</label>
        <select id="jdc"></select>
        <label for="jfc">Jump Fuel Conservation</label>
        <select id="jfc"></select>
        <label for="hull" id="hull-label">Hull skill</label>
        <select id="hull"></select>
    </div>
    <div class="stat" id="ship-stat"></div>
    <div class="row">
        <div class="field">
            <label for="cynos">Cynos available</label>
            <input type="number" id="cynos" min="1" max="99" value="5">
        </div>
        <div class="field">
            <label for="fatigue">Current fatigue (min)</label>
            <input type="number" id="fatigue" min="0" max="300" step="1" value="0">
        </div>
    </div>
    <div class="field">
        <label for="avoid">Avoid as landing (system or region)</label>
        <div class="row">
            <input type="text" id="avoid" list="avoid-list" autocomplete="off" spellcheck="false" placeholder="Add…">
            <button id="avoid-add" type="button">Add</button>
        </div>
        <div id="chips"></div>
    </div>
    <div class="toggles">
        <label><input type="checkbox" id="all-spheres" checked>Show range sphere for every jump</label>
        <label><input type="checkbox" id="reach" checked>Highlight cyno options in selected range</label>
    </div>
    <div class="buttons">
        <button id="swap" type="button">Swap</button>
        <button id="fit" type="button">Fit view</button>
        <button id="copy" type="button">Copy link</button>
    </div>
    <div id="msg"></div>
    <div class="help">
        Drag to rotate, right-drag to pan, wheel to zoom. Click a star to set the origin, shift-click to set the destination.
        Routes take the fewest jumps, then the shortest total distance. Highsec, Pochven, Zarzakh and Jove space are never landing points.
    </div>
</div>

<div id="viewport"><canvas id="gpu"></canvas></div>
<div id="labels"></div>
<div id="gpu-msg"></div>
<div id="tooltip"></div>

<div id="results" class="panel hidden">
    <div id="summary"></div>
    <div id="table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>From</th>
                    <th>Light cyno in</th>
                    <th>Region</th>
                    <th class="num">Sec</th>
                    <th class="num">Distance</th>
                    <th class="num">Fuel</th>
                    <th class="num">Reactivation</th>
                    <th class="num">Fatigue after</th>
                </tr>
            </thead>
            <tbody id="jumps"></tbody>
        </table>
    </div>
</div>

<datalist id="system-list"></datalist>
<datalist id="avoid-list"></datalist>

<script type="module" src="jump.js"></script>
</body>
</html>
