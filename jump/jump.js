// Jump drive route planner: form, URL state, camera, picking, labels and
// the jump table. Route maths lives in jump-route.js, drawing in
// jump-render.js, data in jump-data.php.
//
// Precision model: system positions stay float64 SDE metres (z mirrored
// for display, see buildModel). The camera eye is float64 metres too, and
// every frame each position is made eye-relative in float64 and converted
// to light-years before the float32 upload.

import { Renderer, STAR_FLOATS, LINE_FLOATS, SPHERE_FLOATS } from './jump-render.js';
import * as R from './jump-route.js';

const FOV_Y = 50 * Math.PI / 180;
const NEAR_LY = 1e-6;
const MIN_DIST_LY = 0.05;
const MAX_DIST_LY = 3000;
const PITCH_LIMIT = 89.5 * Math.PI / 180;
const CLICK_SLOP_PX = 4;
const PICK_RADIUS_PX = 10;
const DEFAULT_SHIP = 23757;   // Archon
const MAX_LEVEL = 5;

// EVE's security colours, index = round(sec * 10) clamped to 0..10
const SEC_COLORS = [
    '#f00000', '#d73000', '#f04800', '#f06000', '#d77700', '#efef00',
    '#8fef2f', '#00f000', '#00ef47', '#48f0c0', '#2fefef'
];
const COL = {
    origin: [ 0.48, 0.89, 0.54 ],
    dest: [ 0.42, 0.77, 1.0 ],
    cyno: [ 1.0, 0.86, 0.45 ],
    nocyno: [ 1.0, 0.48, 0.35 ],
    leg: [ 0.55, 0.85, 1.0 ],
    sphere: [ 0.45, 0.6, 0.85 ],
    sphereSel: [ 0.42, 0.77, 1.0 ],
    avoided: [ 0.55, 0.3, 0.3 ]
};

var $ = ( id ) => document.getElementById( id );

var state = {
    data: null,
    m: null,
    ship: null,
    origin: -1,
    dest: -1,
    avoid: { systems: new Set(), regions: new Set() },
    route: null,        // { path, rows, totals, rangeLy }
    selected: -1,       // selected jump (row index)
    hover: -1,          // hovered system
    reach: null         // Set of systems in range of the selected jump origin
};

var renderer = null;
var dpr = Math.min( window.devicePixelRatio || 1, 2 );
var cam = { target: [ 0, 0, 0 ], dist: 1, yaw: 0, pitch: 65 * Math.PI / 180 };
var view = { eye: [ 0, 0, 0 ], rot: new Float64Array( 9 ), w: 1, h: 1, focal: 1 };
var anim = null;
var dirty = true;

var starColor = null;   // Float32Array rgba per system
var starSize = null;    // Float32Array px per system (CSS px)
var starData = null;
var lineData = new Float32Array( 0 );
var sphereData = new Float32Array( 0 );

function hexRgb( h ) {
    return [ parseInt( h.slice( 1, 3 ), 16 ) / 255, parseInt( h.slice( 3, 5 ), 16 ) / 255, parseInt( h.slice( 5, 7 ), 16 ) / 255 ];
}
const SEC_RGB = SEC_COLORS.map( hexRgb );

function secColor( sec ) {
    var d = R.displaySec( sec );
    return SEC_RGB[ Math.max( 0, Math.min( 10, Math.round( d * 10 ) ) ) ];
}
function secCss( sec ) {
    var d = R.displaySec( sec );
    return SEC_COLORS[ Math.max( 0, Math.min( 10, Math.round( d * 10 ) ) ) ];
}
function fmtSec( sec ) {
    return R.displaySec( sec ).toFixed( 1 );
}
function fmtInt( v ) {
    return v.toLocaleString( 'en-US' );
}
function esc( s ) {
    return String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );
}

// ---------------------------------------------------------------- camera

function computeView() {
    var cp = Math.cos( cam.pitch ), sp = Math.sin( cam.pitch );
    var off = [ cp * Math.sin( cam.yaw ), sp, cp * Math.cos( cam.yaw ) ];
    var e = view.eye;
    for ( var k = 0; k < 3; k++ ) e[ k ] = cam.target[ k ] + off[ k ] * cam.dist;
    var f = [ -off[ 0 ], -off[ 1 ], -off[ 2 ] ];
    // right = f x worldUp
    var r = [ -f[ 2 ], 0, f[ 0 ] ];
    var rl = Math.hypot( r[ 0 ], r[ 2 ] );
    r[ 0 ] /= rl; r[ 2 ] /= rl;
    var u = [ r[ 1 ] * f[ 2 ] - r[ 2 ] * f[ 1 ], r[ 2 ] * f[ 0 ] - r[ 0 ] * f[ 2 ], r[ 0 ] * f[ 1 ] - r[ 1 ] * f[ 0 ] ];
    var m = view.rot;
    for ( var c = 0; c < 3; c++ ) {
        m[ c * 3 + 0 ] = r[ c ];
        m[ c * 3 + 1 ] = u[ c ];
        m[ c * 3 + 2 ] = -f[ c ];
    }
    view.right = r;
    view.up = u;
    view.focal = 1 / Math.tan( FOV_Y / 2 );
}

// system i -> CSS pixels, or null when behind the eye
function project( i ) {
    var m = state.m, ly = m.metresPerLy, rot = view.rot;
    var rx = ( m.x[ i ] - view.eye[ 0 ] ) / ly;
    var ry = ( m.y[ i ] - view.eye[ 1 ] ) / ly;
    var rz = ( m.z[ i ] - view.eye[ 2 ] ) / ly;
    var vx = rot[ 0 ] * rx + rot[ 3 ] * ry + rot[ 6 ] * rz;
    var vy = rot[ 1 ] * rx + rot[ 4 ] * ry + rot[ 7 ] * rz;
    var vz = rot[ 2 ] * rx + rot[ 5 ] * ry + rot[ 8 ] * rz;
    if ( vz > -NEAR_LY ) return null;
    var aspect = view.w / view.h;
    var nx = vx * view.focal / aspect / -vz;
    var ny = vy * view.focal / -vz;
    return { x: ( nx * 0.5 + 0.5 ) * view.w, y: ( 0.5 - ny * 0.5 ) * view.h, depth: -vz };
}

function fitTo( points, padLy, animate ) {
    var m = state.m;
    var lo = [ Infinity, Infinity, Infinity ], hi = [ -Infinity, -Infinity, -Infinity ];
    for ( var i of points ) {
        var p = [ m.x[ i ], m.y[ i ], m.z[ i ] ];
        for ( var k = 0; k < 3; k++ ) { lo[ k ] = Math.min( lo[ k ], p[ k ] ); hi[ k ] = Math.max( hi[ k ], p[ k ] ); }
    }
    var c = [ ( lo[ 0 ] + hi[ 0 ] ) / 2, ( lo[ 1 ] + hi[ 1 ] ) / 2, ( lo[ 2 ] + hi[ 2 ] ) / 2 ];
    var rad = 0;
    for ( var j of points ) {
        rad = Math.max( rad, Math.hypot( m.x[ j ] - c[ 0 ], m.y[ j ] - c[ 1 ], m.z[ j ] - c[ 2 ] ) );
    }
    rad += ( padLy || 0 ) * m.metresPerLy;
    rad = Math.max( rad, 0.5 * m.metresPerLy );
    var aspect = view.w / view.h;
    var half = Math.min( FOV_Y / 2, Math.atan( Math.tan( FOV_Y / 2 ) * aspect ) );
    var dist = rad / Math.sin( half ) * 1.05;
    moveCamera( c, dist, animate );
}

function moveCamera( target, dist, animate ) {
    if ( !animate ) {
        cam.target = target.slice();
        cam.dist = dist;
        anim = null;
        dirty = true;
        return;
    }
    anim = { t0: performance.now(), dur: 650, fromT: cam.target.slice(), toT: target.slice(), fromD: cam.dist, toD: dist };
    dirty = true;
}

function stepAnim( now ) {
    if ( !anim ) return;
    var t = Math.min( 1, ( now - anim.t0 ) / anim.dur );
    var e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow( -2 * t + 2, 3 ) / 2;
    for ( var k = 0; k < 3; k++ ) cam.target[ k ] = anim.fromT[ k ] + ( anim.toT[ k ] - anim.fromT[ k ] ) * e;
    // distance in log space, so long zooms don't rush the end
    cam.dist = Math.exp( Math.log( anim.fromD ) + ( Math.log( anim.toD ) - Math.log( anim.fromD ) ) * e );
    if ( t >= 1 ) anim = null;
    dirty = true;
}

// ---------------------------------------------------------------- styling

function styleStars() {
    var m = state.m;
    var reach = $( 'reach' ).checked ? state.reach : null;
    for ( var i = 0; i < m.n; i++ ) {
        var c = secColor( m.sec[ i ] );
        var a = 0.8, size = 2;
        var blocked = R.landingBlock( m, i, null );
        if ( blocked ) a = 0.5;
        if ( state.avoid.systems.has( i ) || state.avoid.regions.has( m.region[ i ] ) ) {
            c = COL.avoided;
            a = 0.5;
        }
        if ( reach ) {
            if ( reach.has( i ) ) { a = 1; size = 3; } else { a *= 0.6; }
        }
        starColor[ i * 4 ] = c[ 0 ];
        starColor[ i * 4 + 1 ] = c[ 1 ];
        starColor[ i * 4 + 2 ] = c[ 2 ];
        starColor[ i * 4 + 3 ] = a;
        starSize[ i ] = size;
    }
    dirty = true;
}

// highlighted systems drawn again on top of the field
function overlayStars() {
    var out = [];
    var r = state.route;
    if ( r ) {
        r.path.forEach( ( s, j ) => {
            var c = COL.cyno;
            if ( j === 0 ) c = COL.origin;
            else if ( j === r.path.length - 1 ) c = COL.dest;
            if ( j > 0 && !r.rows[ j - 1 ].hasCyno ) c = COL.nocyno;
            out.push( { i: s, c: c, size: 5 } );
        } );
    } else {
        if ( state.origin >= 0 ) out.push( { i: state.origin, c: COL.origin, size: 5 } );
        if ( state.dest >= 0 ) out.push( { i: state.dest, c: COL.dest, size: 5 } );
    }
    if ( state.hover >= 0 ) out.push( { i: state.hover, c: [ 1, 1, 1 ], size: 4.5 } );
    return out;
}

// ---------------------------------------------------------------- frame

function writeFrame( now ) {
    var m = state.m, ly = m.metresPerLy, e = view.eye;
    var over = overlayStars();
    var count = m.n + over.length;
    if ( !starData || starData.length < count * STAR_FLOATS ) starData = new Float32Array( ( count + 64 ) * STAR_FLOATS );
    var o = 0;
    var put = ( i, size, r, g, b, a ) => {
        starData[ o ] = ( m.x[ i ] - e[ 0 ] ) / ly;
        starData[ o + 1 ] = ( m.y[ i ] - e[ 1 ] ) / ly;
        starData[ o + 2 ] = ( m.z[ i ] - e[ 2 ] ) / ly;
        starData[ o + 3 ] = size * dpr;
        starData[ o + 4 ] = r;
        starData[ o + 5 ] = g;
        starData[ o + 6 ] = b;
        starData[ o + 7 ] = a;
        o += STAR_FLOATS;
    };
    for ( var i = 0; i < m.n; i++ ) {
        put( i, starSize[ i ], starColor[ i * 4 ], starColor[ i * 4 + 1 ], starColor[ i * 4 + 2 ], starColor[ i * 4 + 3 ] );
    }
    for ( var s of over ) put( s.i, s.size, s.c[ 0 ], s.c[ 1 ], s.c[ 2 ], 1 );
    renderer.upload( 'stars', starData, count );

    var r = state.route;
    var legs = r ? r.rows.length : 0;
    if ( lineData.length < legs * LINE_FLOATS ) lineData = new Float32Array( ( legs + 16 ) * LINE_FLOATS );
    for ( var j = 0; j < legs; j++ ) {
        var row = r.rows[ j ];
        var b = j * LINE_FLOATS;
        var sel = j === state.selected;
        var col = row.hasCyno ? COL.leg : COL.nocyno;
        lineData[ b ] = ( m.x[ row.from ] - e[ 0 ] ) / ly;
        lineData[ b + 1 ] = ( m.y[ row.from ] - e[ 1 ] ) / ly;
        lineData[ b + 2 ] = ( m.z[ row.from ] - e[ 2 ] ) / ly;
        lineData[ b + 3 ] = ( sel ? 4 : 2.5 ) * dpr;
        lineData[ b + 4 ] = ( m.x[ row.to ] - e[ 0 ] ) / ly;
        lineData[ b + 5 ] = ( m.y[ row.to ] - e[ 1 ] ) / ly;
        lineData[ b + 6 ] = ( m.z[ row.to ] - e[ 2 ] ) / ly;
        lineData[ b + 7 ] = row.hasCyno ? 0 : 1;
        lineData[ b + 8 ] = col[ 0 ];
        lineData[ b + 9 ] = col[ 1 ];
        lineData[ b + 10 ] = col[ 2 ];
        lineData[ b + 11 ] = sel || state.selected < 0 ? 0.95 : 0.6;
    }
    renderer.upload( 'lines', lineData, legs );

    // one shell per jump origin, back to front
    var shells = [];
    if ( r ) {
        var all = $( 'all-spheres' ).checked;
        var selJ = state.selected >= 0 ? state.selected : 0;
        for ( var k = 0; k < legs; k++ ) {
            if ( !all && k !== selJ ) continue;
            var c = r.rows[ k ].from;
            var rel = [ ( m.x[ c ] - e[ 0 ] ) / ly, ( m.y[ c ] - e[ 1 ] ) / ly, ( m.z[ c ] - e[ 2 ] ) / ly ];
            shells.push( { rel: rel, d: Math.hypot( rel[ 0 ], rel[ 1 ], rel[ 2 ] ), sel: k === state.selected } );
        }
        shells.sort( ( p, q ) => q.d - p.d );
    }
    if ( sphereData.length < shells.length * SPHERE_FLOATS ) sphereData = new Float32Array( ( shells.length + 16 ) * SPHERE_FLOATS );
    shells.forEach( ( sh, n ) => {
        var b = n * SPHERE_FLOATS;
        var col = sh.sel ? COL.sphereSel : COL.sphere;
        sphereData[ b ] = sh.rel[ 0 ];
        sphereData[ b + 1 ] = sh.rel[ 1 ];
        sphereData[ b + 2 ] = sh.rel[ 2 ];
        sphereData[ b + 3 ] = r.rangeLy;
        sphereData[ b + 4 ] = col[ 0 ];
        sphereData[ b + 5 ] = col[ 1 ];
        sphereData[ b + 6 ] = col[ 2 ];
        sphereData[ b + 7 ] = sh.sel ? 1 : ( state.selected >= 0 ? 0.45 : 0.75 );
        sphereData[ b + 8 ] = sh.d < r.rangeLy * 1.2 ? 1 : 0;
        sphereData[ b + 9 ] = sh.sel ? 1 : 0;
        sphereData[ b + 10 ] = 0;
        sphereData[ b + 11 ] = 0;
    } );
    renderer.upload( 'spheres', sphereData, shells.length );

    renderer.setFrame( view.rot, FOV_Y, NEAR_LY, now / 1000 );
    renderer.render();
}

// DOM labels for the route systems
var labelPool = [];
function updateLabels() {
    var host = $( 'labels' );
    var items = [];
    var r = state.route;
    if ( r ) {
        r.path.forEach( ( s, j ) => {
            var cls = 'cyno', sub = 'cyno ' + j;
            if ( j === 0 ) { cls = 'origin'; sub = 'origin'; }
            else if ( !r.rows[ j - 1 ].hasCyno ) { cls = 'nocyno'; sub = 'no cyno (jump ' + j + ')'; }
            if ( j === r.path.length - 1 ) { cls += ' dest'; sub = ( r.rows[ j - 1 ].hasCyno ? 'destination, cyno ' + j : 'destination, no cyno' ); }
            items.push( { i: s, cls: cls, sub: sub } );
        } );
    } else {
        if ( state.origin >= 0 ) items.push( { i: state.origin, cls: 'origin', sub: 'origin' } );
        if ( state.dest >= 0 ) items.push( { i: state.dest, cls: 'dest', sub: 'destination' } );
    }
    while ( labelPool.length < items.length ) {
        var el = document.createElement( 'div' );
        el.className = 'lbl';
        host.appendChild( el );
        labelPool.push( { el: el, key: '' } );
    }
    labelPool.forEach( ( l, n ) => {
        var it = items[ n ];
        var p = it ? project( it.i ) : null;
        if ( !p ) { l.el.style.display = 'none'; return; }
        var key = it.i + '|' + it.cls + '|' + it.sub;
        if ( l.key !== key ) {
            l.key = key;
            l.el.className = 'lbl ' + it.cls;
            l.el.innerHTML = esc( state.m.name[ it.i ] ) + '<span class="s">' + esc( it.sub ) + '</span>';
        }
        l.el.style.display = '';
        l.el.style.transform = 'translate(' + ( p.x + 7 ).toFixed( 1 ) + 'px,' + ( p.y - 7 ).toFixed( 1 ) + 'px)';
    } );
}

function loop( now ) {
    if ( !renderer ) return;
    stepAnim( now );
    var canvas = $( 'gpu' );
    var w = canvas.clientWidth, h = canvas.clientHeight;
    try {
        if ( w !== view.w || h !== view.h ) {
            view.w = w;
            view.h = h;
            renderer.resize( w * dpr, h * dpr );
            dirty = true;
        }
        if ( dirty ) {
            dirty = false;
            computeView();
            writeFrame( now );
            updateLabels();
        }
    } catch ( err ) {
        // an exception here would otherwise end the loop with a frozen map
        console.error( err );
        disableMap( 'The 3D map stopped working (' + err.message + ').' );
        return;
    }
    requestAnimationFrame( loop );
}

// Without WebGPU the planner, table and URL all still work: hide the map,
// its labels and tooltip, and say why. Safe to call more than once.
function disableMap( reason ) {
    renderer = null;
    anim = null;
    document.body.classList.add( 'no-gpu' );
    showTooltip( -1 );
    var msg = $( 'gpu-msg' );
    msg.innerHTML = '<b>3D map unavailable</b><p>' + esc( reason ) + '</p>' +
        '<p>Route planning, the jump table and shareable links still work. ' +
        'The map needs a browser with WebGPU enabled, such as a current Chrome, Edge, Safari or Firefox.</p>';
}

// ---------------------------------------------------------------- picking

function pick( px, py ) {
    var m = state.m, best = -1, bestD = PICK_RADIUS_PX * PICK_RADIUS_PX;
    for ( var i = 0; i < m.n; i++ ) {
        var p = project( i );
        if ( !p ) continue;
        var dx = p.x - px, dy = p.y - py, d = dx * dx + dy * dy;
        if ( d < bestD ) { bestD = d; best = i; }
    }
    return best;
}

function showTooltip( i, x, y ) {
    var tip = $( 'tooltip' );
    if ( i < 0 ) { tip.style.display = 'none'; return; }
    var m = state.m;
    var html = '<b>' + esc( m.name[ i ] ) + '</b> <span class="sec" style="color:' + secCss( m.sec[ i ] ) + '">' + fmtSec( m.sec[ i ] ) + '</span>' +
        '<div class="s">' + esc( m.regionNames[ m.region[ i ] ] ) + '</div>';
    var from = state.selected >= 0 && state.route ? state.route.rows[ state.selected ].from : state.origin;
    if ( from >= 0 && from !== i ) {
        var ly = R.distanceM( m, from, i ) / m.metresPerLy;
        var range = state.ship ? currentRange() : 0;
        var block = R.landingBlock( m, i, state.avoid );
        var note = block ? ' · can’t land: ' + block : ( ly <= range ? ' · in range' : ' · out of range' );
        html += '<div class="s">' + ly.toFixed( 3 ) + ' LY from ' + esc( m.name[ from ] ) + esc( note ) + '</div>';
    }
    tip.innerHTML = html;
    tip.style.display = 'block';
    tip.style.left = ( x + 14 ) + 'px';
    tip.style.top = ( y + 14 ) + 'px';
}

function bindPointer() {
    var canvas = $( 'gpu' );
    var pointers = new Map();
    var drag = null;

    canvas.addEventListener( 'contextmenu', ( ev ) => ev.preventDefault() );
    canvas.addEventListener( 'pointerdown', ( ev ) => {
        canvas.setPointerCapture( ev.pointerId );
        pointers.set( ev.pointerId, { x: ev.clientX, y: ev.clientY } );
        if ( pointers.size === 1 ) {
            drag = {
                x: ev.clientX, y: ev.clientY, sx: ev.clientX, sy: ev.clientY,
                pan: ev.button === 2 || ev.button === 1 || ev.ctrlKey, moved: false, shift: ev.shiftKey
            };
        } else {
            drag = null;
        }
        anim = null;
    } );
    canvas.addEventListener( 'pointermove', ( ev ) => {
        var rect = canvas.getBoundingClientRect();
        if ( pointers.has( ev.pointerId ) ) {
            var prev = pointers.get( ev.pointerId );
            if ( pointers.size === 2 ) {
                var pts = [ ...pointers.values() ];
                var before = Math.hypot( pts[ 0 ].x - pts[ 1 ].x, pts[ 0 ].y - pts[ 1 ].y );
                pointers.set( ev.pointerId, { x: ev.clientX, y: ev.clientY } );
                pts = [ ...pointers.values() ];
                var after = Math.hypot( pts[ 0 ].x - pts[ 1 ].x, pts[ 0 ].y - pts[ 1 ].y );
                if ( before > 0 && after > 0 ) zoom( before / after );
                return;
            }
            pointers.set( ev.pointerId, { x: ev.clientX, y: ev.clientY } );
            if ( !drag ) return;
            var dx = ev.clientX - prev.x, dy = ev.clientY - prev.y;
            if ( Math.hypot( ev.clientX - drag.sx, ev.clientY - drag.sy ) > CLICK_SLOP_PX ) {
                drag.moved = true;
                canvas.classList.add( 'dragging' );
            }
            if ( !drag.moved ) return;
            if ( drag.pan ) {
                // world units per pixel at the target's distance
                var k = 2 * Math.tan( FOV_Y / 2 ) * cam.dist / view.h;
                for ( var a = 0; a < 3; a++ ) cam.target[ a ] += ( -dx * view.right[ a ] + dy * view.up[ a ] ) * k;
            } else {
                cam.yaw -= dx * 0.005;
                cam.pitch = Math.max( -PITCH_LIMIT, Math.min( PITCH_LIMIT, cam.pitch + dy * 0.005 ) );
            }
            showTooltip( -1 );
            dirty = true;
            return;
        }
        var h = pick( ev.clientX - rect.left, ev.clientY - rect.top );
        if ( h !== state.hover ) { state.hover = h; dirty = true; }
        showTooltip( h, ev.clientX, ev.clientY );
    } );
    var end = ( ev ) => {
        pointers.delete( ev.pointerId );
        canvas.classList.remove( 'dragging' );
        if ( drag && !drag.moved && ev.type === 'pointerup' && ev.button === 0 ) {
            var rect = canvas.getBoundingClientRect();
            var i = pick( ev.clientX - rect.left, ev.clientY - rect.top );
            if ( i >= 0 ) {
                if ( ev.shiftKey ) $( 'dest' ).value = state.m.name[ i ];
                else $( 'origin' ).value = state.m.name[ i ];
                update( true );
            }
        }
        drag = null;
    };
    canvas.addEventListener( 'pointerup', end );
    canvas.addEventListener( 'pointercancel', end );
    canvas.addEventListener( 'pointerleave', () => {
        if ( state.hover >= 0 ) { state.hover = -1; dirty = true; }
        showTooltip( -1 );
    } );
    canvas.addEventListener( 'dblclick', ( ev ) => {
        var rect = canvas.getBoundingClientRect();
        var i = pick( ev.clientX - rect.left, ev.clientY - rect.top );
        if ( i < 0 ) return;
        var m = state.m;
        moveCamera( [ m.x[ i ], m.y[ i ], m.z[ i ] ], Math.min( cam.dist, 20 * m.metresPerLy ), true );
    } );
    canvas.addEventListener( 'wheel', ( ev ) => {
        ev.preventDefault();
        anim = null;
        zoom( Math.exp( ev.deltaY * ( ev.deltaMode === 1 ? 0.05 : 0.0015 ) ) );
    }, { passive: false } );
}

function zoom( factor ) {
    var ly = state.m.metresPerLy;
    cam.dist = Math.max( MIN_DIST_LY * ly, Math.min( MAX_DIST_LY * ly, cam.dist * factor ) );
    dirty = true;
}

// ---------------------------------------------------------------- form

function levelOptions( sel ) {
    for ( var l = 0; l <= MAX_LEVEL; l++ ) sel.add( new Option( String( l ), String( l ) ) );
    sel.value = String( MAX_LEVEL );
}

function currentRange() {
    return R.rangeLy( state.ship, state.data.skills, Number( $( 'jdc' ).value ) );
}

function buildForm() {
    var d = state.data, m = state.m;

    var frag = document.createDocumentFragment();
    for ( var i = 0; i < m.n; i++ ) frag.appendChild( new Option( m.name[ i ] ) );
    $( 'system-list' ).appendChild( frag );

    var af = document.createDocumentFragment();
    Object.values( d.regions ).forEach( ( name ) => af.appendChild( new Option( name + ' (region)' ) ) );
    for ( var j = 0; j < m.n; j++ ) af.appendChild( new Option( m.name[ j ] ) );
    $( 'avoid-list' ).appendChild( af );

    var shipSel = $( 'ship' );
    var groups = {};
    d.ships.forEach( ( s ) => {
        if ( !groups[ s.group ] ) {
            groups[ s.group ] = document.createElement( 'optgroup' );
            groups[ s.group ].label = s.group;
            shipSel.appendChild( groups[ s.group ] );
        }
        groups[ s.group ].appendChild( new Option( s.name, String( s.typeID ) ) );
    } );

    levelOptions( $( 'jdc' ) );
    levelOptions( $( 'jfc' ) );
    levelOptions( $( 'hull' ) );
    $( 'jdc' ).previousElementSibling.textContent = d.skills.rangeBonus.name;
    $( 'jfc' ).previousElementSibling.textContent = d.skills.fuelBonus.name;
}

function readUrl() {
    var q = new URLSearchParams( location.search );
    var m = state.m;
    var shipId = Number( q.get( 'ship' ) ) || DEFAULT_SHIP;
    if ( !state.data.ships.some( ( s ) => s.typeID === shipId ) ) shipId = state.data.ships[ 0 ].typeID;
    $( 'ship' ).value = String( shipId );
    for ( var k of [ 'jdc', 'jfc', 'hull' ] ) {
        if ( q.has( k ) ) {
            var v = Math.max( 0, Math.min( MAX_LEVEL, Math.round( Number( q.get( k ) ) ) || 0 ) );
            $( k ).value = String( v );
        }
    }
    if ( q.has( 'cynos' ) ) $( 'cynos' ).value = String( Math.max( 1, Math.round( Number( q.get( 'cynos' ) ) ) || 1 ) );
    if ( q.has( 'fatigue' ) ) $( 'fatigue' ).value = String( Math.max( 0, Number( q.get( 'fatigue' ) ) || 0 ) );
    if ( q.get( 'from' ) ) $( 'origin' ).value = q.get( 'from' );
    if ( q.get( 'to' ) ) $( 'dest' ).value = q.get( 'to' );
    ( q.get( 'avoid' ) || '' ).split( ',' ).forEach( ( t ) => {
        t = t.trim();
        if ( !t ) return;
        if ( t.startsWith( 'r:' ) ) {
            var rid = m.regionByName.get( t.slice( 2 ).toLowerCase() );
            if ( rid !== undefined ) state.avoid.regions.add( rid );
        } else {
            var i = m.byName.get( t.toLowerCase() );
            if ( i !== undefined ) state.avoid.systems.add( i );
        }
    } );
}

function writeUrl() {
    var m = state.m;
    var q = new URLSearchParams();
    if ( state.origin >= 0 ) q.set( 'from', m.name[ state.origin ] );
    if ( state.dest >= 0 ) q.set( 'to', m.name[ state.dest ] );
    q.set( 'ship', $( 'ship' ).value );
    q.set( 'jdc', $( 'jdc' ).value );
    q.set( 'jfc', $( 'jfc' ).value );
    if ( state.ship && state.ship.hullFuelBonus ) q.set( 'hull', $( 'hull' ).value );
    q.set( 'cynos', $( 'cynos' ).value );
    if ( Number( $( 'fatigue' ).value ) > 0 ) q.set( 'fatigue', $( 'fatigue' ).value );
    var avoid = [];
    state.avoid.regions.forEach( ( r ) => avoid.push( 'r:' + m.regionNames[ r ] ) );
    state.avoid.systems.forEach( ( i ) => avoid.push( m.name[ i ] ) );
    if ( avoid.length ) q.set( 'avoid', avoid.join( ',' ) );
    history.replaceState( null, '', location.pathname + '?' + q.toString() );
}

function renderChips() {
    var m = state.m, host = $( 'chips' );
    host.innerHTML = '';
    var add = ( label, onRemove ) => {
        var chip = document.createElement( 'span' );
        chip.className = 'chip';
        chip.textContent = label;
        var x = document.createElement( 'button' );
        x.type = 'button';
        x.textContent = '×';
        x.title = 'Remove';
        x.addEventListener( 'click', () => { onRemove(); renderChips(); update( false ); } );
        chip.appendChild( x );
        host.appendChild( chip );
    };
    state.avoid.regions.forEach( ( r ) => add( m.regionNames[ r ] + ' (region)', () => state.avoid.regions.delete( r ) ) );
    state.avoid.systems.forEach( ( i ) => add( m.name[ i ], () => state.avoid.systems.delete( i ) ) );
}

function addAvoid() {
    var m = state.m;
    var v = $( 'avoid' ).value.trim();
    if ( !v ) return;
    var lower = v.toLowerCase();
    var rname = lower.endsWith( ' (region)' ) ? lower.slice( 0, -9 ) : null;
    if ( rname !== null && m.regionByName.has( rname ) ) {
        state.avoid.regions.add( m.regionByName.get( rname ) );
    } else if ( m.byName.has( lower ) ) {
        state.avoid.systems.add( m.byName.get( lower ) );
    } else if ( m.regionByName.has( lower ) ) {
        state.avoid.regions.add( m.regionByName.get( lower ) );
    } else {
        setMsg( 'Unknown system or region: ' + v, true );
        return;
    }
    $( 'avoid' ).value = '';
    renderChips();
    update( false );
}

function setMsg( text, err ) {
    var el = $( 'msg' );
    el.textContent = text || '';
    el.className = err ? 'err' : '';
}

function shipStat() {
    var s = state.ship, d = state.data;
    var hullRow = !!s.hullFuelBonus;
    $( 'hull' ).style.display = hullRow ? '' : 'none';
    $( 'hull-label' ).style.display = hullRow ? '' : 'none';
    if ( hullRow ) $( 'hull-label' ).textContent = s.hullFuelBonus.skillName;
    var fuelFactor = 1 + d.skills.fuelBonus.perLevel / 100 * Number( $( 'jfc' ).value );
    if ( hullRow ) fuelFactor *= 1 - s.hullFuelBonus.perLevel / 100 * Number( $( 'hull' ).value );
    var perLy = s.fuelAmount * fuelFactor;
    $( 'ship-stat' ).innerHTML = 'Range <b>' + currentRange().toFixed( 2 ) + ' LY</b> · ' +
        '<b>' + fmtInt( Math.round( perLy * 100 ) / 100 ) + '</b> ' + esc( d.fuelNames[ s.fuelTypeID ] ) + ' / LY' +
        ( s.fatigueMultiplier !== 1 ? ' · fatigue distance ×' + s.fatigueMultiplier : '' );
}

// recompute everything from the form; refit the camera when asked
function update( refit ) {
    var m = state.m;
    state.ship = state.data.ships.find( ( s ) => String( s.typeID ) === $( 'ship' ).value );
    shipStat();
    var o = m.byName.get( $( 'origin' ).value.trim().toLowerCase() );
    var d = m.byName.get( $( 'dest' ).value.trim().toLowerCase() );
    var prevKey = state.route ? state.route.path.join( ',' ) : '';
    state.origin = o === undefined ? -1 : o;
    state.dest = d === undefined ? -1 : d;
    state.route = null;
    setMsg( '' );

    if ( state.origin >= 0 && state.dest >= 0 ) {
        var range = currentRange();
        var res = R.planRoute( m, state.origin, state.dest, range, state.avoid );
        if ( res.error ) {
            setMsg( res.error, true );
        } else {
            var cynos = Math.max( 1, Math.round( Number( $( 'cynos' ).value ) ) || 1 );
            var det = R.jumpDetails( m, res.path, state.ship, state.data.skills,
                { jfc: Number( $( 'jfc' ).value ), hull: Number( $( 'hull' ).value ) },
                cynos, Number( $( 'fatigue' ).value ) || 0 );
            state.route = { path: res.path, rows: det.rows, totals: det.totals, rangeLy: range, cynos: cynos };
        }
    } else if ( $( 'origin' ).value.trim() && state.origin < 0 ) {
        setMsg( 'Unknown origin system.', true );
    } else if ( $( 'dest' ).value.trim() && state.dest < 0 ) {
        setMsg( 'Unknown destination system.', true );
    }

    var newKey = state.route ? state.route.path.join( ',' ) : '';
    if ( newKey !== prevKey ) state.selected = -1;
    updateReach();
    renderTable();
    writeUrl();
    if ( refit && newKey && newKey !== prevKey ) fitTo( state.route.path, state.route.rangeLy, true );
    else if ( refit && !state.route && state.origin >= 0 && state.dest < 0 ) {
        var mm = state.m;
        moveCamera( [ mm.x[ state.origin ], mm.y[ state.origin ], mm.z[ state.origin ] ], Math.min( cam.dist, 25 * mm.metresPerLy ), true );
    }
    dirty = true;
}

// systems a cyno could go in around the selected jump origin (or the
// origin itself before there is a route)
function updateReach() {
    var from = -1;
    if ( state.route ) from = state.route.rows[ state.selected >= 0 ? state.selected : 0 ].from;
    else if ( state.origin >= 0 ) from = state.origin;
    state.reach = from >= 0 && state.ship ? new Set( R.inRange( state.m, from, currentRange(), state.avoid ) ) : null;
    styleStars();
}

function renderTable() {
    var r = state.route, m = state.m;
    var panel = $( 'results' );
    if ( !r ) {
        panel.classList.add( 'hidden' );
        return;
    }
    panel.classList.remove( 'hidden' );
    var t = r.totals;
    var fuelName = state.data.fuelNames[ state.ship.fuelTypeID ];
    var html = '<b>' + t.jumps + ' jump' + ( t.jumps === 1 ? '' : 's' ) + '</b> · <b>' + t.ly.toFixed( 2 ) + ' LY</b> · <b>' +
        fmtInt( t.fuel ) + '</b> ' + esc( fuelName ) + ' · <b>' + R.fmtMinutes( t.waitMin ) + '</b> waiting on reactivation' +
        ' · arrive with <b>' + R.fmtMinutes( t.finalFatigue ) + '</b> fatigue, <b>' + R.fmtMinutes( t.finalCooldown ) + '</b> reactivation';
    if ( t.cynosNeeded > r.cynos ) {
        var first = r.cynos + 1;
        html += '<br><span class="warn">Needs ' + t.cynosNeeded + ' cynos, ' + r.cynos + ' available: no cyno for jump' +
            ( first === t.jumps ? ' ' + first : 's ' + first + '–' + t.jumps ) + '.</span>';
    } else {
        html += ' · uses ' + t.cynosNeeded + ' of ' + r.cynos + ' cynos';
    }
    $( 'summary' ).innerHTML = html;

    var rows = r.rows.map( ( row, j ) => {
        var cyno = row.hasCyno ? 'cyno ' + row.n : 'no cyno';
        return '<tr data-j="' + j + '" class="' + ( row.hasCyno ? '' : 'nocyno' ) + ( j === state.selected ? ' sel' : '' ) + '">' +
            '<td class="num">' + row.n + '</td>' +
            '<td>' + esc( m.name[ row.from ] ) + '</td>' +
            '<td class="cyno"><b>' + esc( m.name[ row.to ] ) + '</b> <span>(' + cyno + ')</span></td>' +
            '<td>' + esc( m.regionNames[ m.region[ row.to ] ] ) + '</td>' +
            '<td class="num sec" style="color:' + secCss( m.sec[ row.to ] ) + '">' + fmtSec( m.sec[ row.to ] ) + '</td>' +
            '<td class="num">' + row.ly.toFixed( 3 ) + ' LY</td>' +
            '<td class="num">' + fmtInt( row.fuel ) + '</td>' +
            '<td class="num">' + R.fmtMinutes( row.cooldown ) + '</td>' +
            '<td class="num">' + R.fmtMinutes( row.fatigueAfter ) + '</td>' +
            '</tr>';
    } );
    $( 'jumps' ).innerHTML = rows.join( '' );
}

function selectJump( j ) {
    state.selected = state.selected === j ? -1 : j;
    document.querySelectorAll( '#jumps tr' ).forEach( ( tr ) => tr.classList.toggle( 'sel', Number( tr.dataset.j ) === state.selected ) );
    updateReach();
    if ( state.selected >= 0 ) {
        var row = state.route.rows[ state.selected ];
        fitTo( [ row.from, row.to ], state.route.rangeLy, true );
    }
    dirty = true;
}

function bindForm() {
    var planNow = () => update( true );
    // a datalist pick fires 'input' with the full name; only react to exact names
    for ( var id of [ 'origin', 'dest' ] ) {
        $( id ).addEventListener( 'input', ( ev ) => {
            if ( state.m.byName.has( ev.target.value.trim().toLowerCase() ) ) planNow();
        } );
        $( id ).addEventListener( 'change', planNow );
    }
    for ( var sid of [ 'ship', 'jdc', 'jfc', 'hull', 'cynos', 'fatigue' ] ) $( sid ).addEventListener( 'change', planNow );
    $( 'avoid-add' ).addEventListener( 'click', addAvoid );
    $( 'avoid' ).addEventListener( 'keydown', ( ev ) => { if ( ev.key === 'Enter' ) addAvoid(); } );
    $( 'all-spheres' ).addEventListener( 'change', () => { dirty = true; } );
    $( 'reach' ).addEventListener( 'change', () => styleStars() );
    $( 'swap' ).addEventListener( 'click', () => {
        var o = $( 'origin' ).value;
        $( 'origin' ).value = $( 'dest' ).value;
        $( 'dest' ).value = o;
        update( true );
    } );
    $( 'fit' ).addEventListener( 'click', () => {
        if ( state.route ) fitTo( state.route.path, state.route.rangeLy, true );
        else fitTo( allSystems(), 0, true );
    } );
    $( 'copy' ).addEventListener( 'click', () => {
        var done = () => setMsg( 'Link copied.' );
        if ( navigator.clipboard ) navigator.clipboard.writeText( location.href ).then( done, () => setMsg( location.href ) );
        else setMsg( location.href );
    } );
    $( 'jumps' ).addEventListener( 'click', ( ev ) => {
        var tr = ev.target.closest( 'tr' );
        if ( tr ) selectJump( Number( tr.dataset.j ) );
    } );
}

function allSystems() {
    var a = [];
    for ( var i = 0; i < state.m.n; i++ ) a.push( i );
    return a;
}

// ---------------------------------------------------------------- start

async function main() {
    var resp = await fetch( 'jump-data.php' );
    if ( !resp.ok ) throw new Error( 'jump-data.php returned ' + resp.status );
    state.data = await resp.json();
    state.m = R.buildModel( state.data );
    starColor = new Float32Array( state.m.n * 4 );
    starSize = new Float32Array( state.m.n );

    buildForm();
    readUrl();
    renderChips();
    bindForm();
    bindPointer();

    try {
        renderer = await Renderer.create( $( 'gpu' ) );
        renderer.device.addEventListener( 'uncapturederror', ( ev ) => console.error( 'WebGPU error:', ev.error.message ) );
        renderer.onLost = ( info ) => disableMap( 'The GPU device was lost' + ( info.message ? ' (' + info.message.replace( /\.$/, '' ) + ')' : '' ) + '. Reload the page to try again.' );
    } catch ( err ) {
        console.warn( 'WebGPU unavailable:', err );
        disableMap( err.message );
    }

    var canvas = $( 'gpu' );
    view.w = canvas.clientWidth;
    view.h = canvas.clientHeight;
    if ( renderer ) {
        try {
            renderer.resize( view.w * dpr, view.h * dpr );
        } catch ( err ) {
            disableMap( 'The 3D map could not be sized (' + err.message + ').' );
        }
    }
    fitTo( allSystems(), 0, false );
    update( false );
    if ( state.route ) fitTo( state.route.path, state.route.rangeLy, false );
    if ( renderer ) requestAnimationFrame( loop );
}

main().catch( ( err ) => {
    console.error( err );
    setMsg( 'Failed to load: ' + err.message, true );
} );
