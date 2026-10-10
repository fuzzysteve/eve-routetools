// Jump drive route maths for jump.php - no DOM, no GPU, so it can be
// exercised from node as well as the page.
//
// Precision: coordinates are raw SDE metres held as float64 for the whole
// computation. Ranges are compared as squared metres against the squared
// range in metres, so a jump right at the edge of range is decided on the
// exact distance rather than a rounded light-year figure. Light-years only
// appear when a distance is shown, used for fuel or used for fatigue.

// round(security, 1) >= 0.5 is highsec, so anything from 0.45 up
export const HIGHSEC_MIN = 0.45;

// EVE University wiki, "Jump fatigue" (checked 2026-10-10):
//   fatigue: min 10 x (1 + LY), otherwise fatigue x (1 + LY), capped at 5 hours
//   activation cooldown: min (1 + LY) minutes, otherwise fatigue / 10, capped at 30 minutes
//   ship fatigue reductions apply to the effective distance (LY above)
//   fatigue decays in real time
export const FATIGUE_MAX_MIN = 300;
export const FATIGUE_MIN_FACTOR = 10;
export const COOLDOWN_MAX_MIN = 30;
export const COOLDOWN_FATIGUE_DIVISOR = 10;

export function buildModel( data ) {
    var s = data.systems;
    var n = s.id.length;
    var m = {
        n: n,
        metresPerLy: data.metresPerLy,
        id: s.id,
        name: s.name,
        sec: Float64Array.from( s.sec ),
        region: Int32Array.from( s.region ),
        // display frame: EVE's universe is left-handed, so z is mirrored to
        // draw it the way the in-game map does (north up, Delve to the
        // west). Negation is exact, and distances are unaffected
        x: Float64Array.from( s.x ),
        y: Float64Array.from( s.y ),
        z: Float64Array.from( s.z, ( v ) => -v ),
        regionNames: data.regions,
        noCyno: new Set( data.noCyno ),
        byName: new Map(),
        regionByName: new Map(),
        idIndex: new Map()
    };
    for ( var i = 0; i < n; i++ ) {
        m.byName.set( s.name[ i ].toLowerCase(), i );
        m.idIndex.set( s.id[ i ], i );
    }
    for ( var rid in data.regions ) m.regionByName.set( data.regions[ rid ].toLowerCase(), Number( rid ) );
    return m;
}

export function isHighsec( m, i ) {
    return m.sec[ i ] >= HIGHSEC_MIN;
}

// why a jump can't land in system i, or null if it can
export function landingBlock( m, i, avoid ) {
    if ( isHighsec( m, i ) ) return 'highsec';
    if ( m.noCyno.has( m.region[ i ] ) ) return 'no cynos in ' + m.regionNames[ m.region[ i ] ];
    if ( avoid ) {
        if ( avoid.systems.has( i ) ) return 'avoided';
        if ( avoid.regions.has( m.region[ i ] ) ) return 'avoided region';
    }
    return null;
}

// security as the game shows it: one decimal, except that anything just
// above zero shows as 0.1 rather than rounding down into nullsec
export function displaySec( sec ) {
    if ( sec > 0 && sec < 0.05 ) return 0.1;
    var r = Math.round( sec * 10 ) / 10;
    return r === 0 ? 0 : r;
}

export function rangeLy( ship, skills, jdcLevel ) {
    return ship.range * ( 1 + skills.rangeBonus.perLevel / 100 * jdcLevel );
}

export function distanceM( m, a, b ) {
    var dx = m.x[ a ] - m.x[ b ], dy = m.y[ a ] - m.y[ b ], dz = m.z[ a ] - m.z[ b ];
    return Math.sqrt( dx * dx + dy * dy + dz * dz );
}

// binary heap keyed on (jumps, metres) so ties in jump count go to the
// shorter total distance (less fuel, less fatigue)
class Heap {
    constructor() { this.a = []; }
    get size() { return this.a.length; }
    less( p, q ) { return p[ 0 ] < q[ 0 ] || ( p[ 0 ] === q[ 0 ] && p[ 1 ] < q[ 1 ] ); }
    push( e ) {
        var a = this.a;
        a.push( e );
        var i = a.length - 1;
        while ( i > 0 ) {
            var p = ( i - 1 ) >> 1;
            if ( !this.less( a[ i ], a[ p ] ) ) break;
            [ a[ i ], a[ p ] ] = [ a[ p ], a[ i ] ];
            i = p;
        }
    }
    pop() {
        var a = this.a;
        var top = a[ 0 ];
        var last = a.pop();
        if ( a.length ) {
            a[ 0 ] = last;
            var i = 0;
            for ( ;; ) {
                var l = i * 2 + 1, r = l + 1, s = i;
                if ( l < a.length && this.less( a[ l ], a[ s ] ) ) s = l;
                if ( r < a.length && this.less( a[ r ], a[ s ] ) ) s = r;
                if ( s === i ) break;
                [ a[ i ], a[ s ] ] = [ a[ s ], a[ i ] ];
                i = s;
            }
        }
        return top;
    }
}

// Fewest jumps, then least total distance. Neighbours are found through a
// uniform grid with one-range cells, so only the 27 surrounding cells are
// distance-checked. Returns { path: [systemIndex...] } or { error }.
export function planRoute( m, origin, dest, rangeLyValue, avoid ) {
    if ( origin === dest ) return { error: 'Origin and destination are the same system.' };
    var block = landingBlock( m, dest, avoid );
    if ( block ) return { error: m.name[ dest ] + " can't be jumped to (" + block + ').' };

    var rangeM = rangeLyValue * m.metresPerLy;
    var range2 = rangeM * rangeM;

    var cells = new Map();
    var cx = new Int32Array( m.n ), cy = new Int32Array( m.n ), cz = new Int32Array( m.n );
    var key = ( i, j, k ) => i + ',' + j + ',' + k;
    for ( var i = 0; i < m.n; i++ ) {
        cx[ i ] = Math.floor( m.x[ i ] / rangeM );
        cy[ i ] = Math.floor( m.y[ i ] / rangeM );
        cz[ i ] = Math.floor( m.z[ i ] / rangeM );
        // only systems a cyno can be lit in are ever neighbours
        if ( landingBlock( m, i, avoid ) ) continue;
        var k = key( cx[ i ], cy[ i ], cz[ i ] );
        var list = cells.get( k );
        if ( !list ) cells.set( k, list = [] );
        list.push( i );
    }

    var jumps = new Int32Array( m.n ).fill( 0x7fffffff );
    var dist = new Float64Array( m.n ).fill( Infinity );
    var prev = new Int32Array( m.n ).fill( -1 );
    var done = new Uint8Array( m.n );
    var heap = new Heap();
    jumps[ origin ] = 0;
    dist[ origin ] = 0;
    heap.push( [ 0, 0, origin ] );

    while ( heap.size ) {
        var e = heap.pop();
        var u = e[ 2 ];
        if ( done[ u ] ) continue;
        done[ u ] = 1;
        if ( u === dest ) break;
        var nj = jumps[ u ] + 1;
        for ( var ox = -1; ox <= 1; ox++ ) for ( var oy = -1; oy <= 1; oy++ ) for ( var oz = -1; oz <= 1; oz++ ) {
            var c = cells.get( key( cx[ u ] + ox, cy[ u ] + oy, cz[ u ] + oz ) );
            if ( !c ) continue;
            for ( var t = 0; t < c.length; t++ ) {
                var v = c[ t ];
                if ( done[ v ] ) continue;
                var dx = m.x[ u ] - m.x[ v ], dy = m.y[ u ] - m.y[ v ], dz = m.z[ u ] - m.z[ v ];
                var d2 = dx * dx + dy * dy + dz * dz;
                if ( d2 > range2 ) continue;
                var nd = dist[ u ] + Math.sqrt( d2 );
                if ( nj < jumps[ v ] || ( nj === jumps[ v ] && nd < dist[ v ] ) ) {
                    jumps[ v ] = nj;
                    dist[ v ] = nd;
                    prev[ v ] = u;
                    heap.push( [ nj, nd, v ] );
                }
            }
        }
    }

    if ( prev[ dest ] < 0 ) {
        return { error: 'No jump route from ' + m.name[ origin ] + ' to ' + m.name[ dest ] +
            ' with ' + rangeLyValue.toFixed( 2 ) + ' LY range.' };
    }
    var path = [];
    for ( var p = dest; p !== -1; p = prev[ p ] ) path.push( p );
    path.reverse();
    return { path: path };
}

// every system within range of system i that a cyno could be lit in -
// alternative landing spots for one hop
export function inRange( m, i, rangeLyValue, avoid ) {
    var rangeM = rangeLyValue * m.metresPerLy;
    var range2 = rangeM * rangeM;
    var out = [];
    for ( var v = 0; v < m.n; v++ ) {
        if ( v === i ) continue;
        var dx = m.x[ i ] - m.x[ v ], dy = m.y[ i ] - m.y[ v ], dz = m.z[ i ] - m.z[ v ];
        if ( dx * dx + dy * dy + dz * dz <= range2 && !landingBlock( m, v, avoid ) ) out.push( v );
    }
    return out;
}

// per-jump table rows: distance, fuel, cyno, fatigue and cooldown.
// Assumes each jump is taken as soon as the previous cooldown ends, with
// fatigue decaying in real time while waiting.
export function jumpDetails( m, path, ship, skills, levels, cynos, startFatigueMin ) {
    var fuelFactor = 1 + skills.fuelBonus.perLevel / 100 * levels.jfc;
    if ( ship.hullFuelBonus ) fuelFactor *= 1 - ship.hullFuelBonus.perLevel / 100 * levels.hull;

    var rows = [];
    var fatigue = Math.max( 0, startFatigueMin || 0 );
    var totals = { ly: 0, fuel: 0, waitMin: 0, jumps: path.length - 1, cynosNeeded: path.length - 1, finalFatigue: 0, finalCooldown: 0 };
    for ( var j = 1; j < path.length; j++ ) {
        var a = path[ j - 1 ], b = path[ j ];
        var ly = distanceM( m, a, b ) / m.metresPerLy;
        // isotopes are whole units; the drive takes the next unit up
        var fuel = Math.ceil( ship.fuelAmount * ly * fuelFactor );
        var eff = ly * ship.fatigueMultiplier;
        var fatigueBefore = fatigue;
        var cooldown = Math.min( COOLDOWN_MAX_MIN, Math.max( 1 + eff, fatigue / COOLDOWN_FATIGUE_DIVISOR ) );
        fatigue = Math.min( FATIGUE_MAX_MIN, Math.max( FATIGUE_MIN_FACTOR * ( 1 + eff ), fatigue * ( 1 + eff ) ) );
        rows.push( {
            n: j, from: a, to: b, ly: ly, fuel: fuel, effLy: eff,
            fatigueBefore: fatigueBefore, fatigueAfter: fatigue, cooldown: cooldown,
            hasCyno: j <= cynos
        } );
        totals.ly += ly;
        totals.fuel += fuel;
        if ( j < path.length - 1 ) {
            totals.waitMin += cooldown;
            fatigue = Math.max( 0, fatigue - cooldown );
        } else {
            totals.finalFatigue = fatigue;
            totals.finalCooldown = cooldown;
        }
    }
    return { rows: rows, totals: totals };
}

export function fmtMinutes( min ) {
    var s = Math.round( min * 60 );
    var h = Math.floor( s / 3600 );
    var mm = Math.floor( ( s % 3600 ) / 60 );
    var ss = s % 60;
    var two = ( v ) => ( v < 10 ? '0' : '' ) + v;
    return h ? h + ':' + two( mm ) + ':' + two( ss ) : mm + ':' + two( ss );
}
