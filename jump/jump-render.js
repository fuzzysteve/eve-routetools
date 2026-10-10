// WebGPU renderer for jump.js.
//
// Precision: the caller holds every position as float64 SDE metres and
// hands this renderer positions already made relative to the camera (and
// converted to light-years, still in float64, before the float32 write).
// The view matrix is rotation-only, so nothing absolute ever reaches the
// GPU and float32 is precise exactly where it matters - near the eye.
//
// Three passes into one 4x MSAA target, no depth buffer (everything is
// blended and drawn in a fixed order):
//   spheres - jump range shells: ray-traced sphere impostors, translucent
//             fresnel rim plus a faint lat/long grid, back-to-front sorted
//             by the caller, premultiplied blending
//   lines   - route legs, screen-space quads of constant pixel width,
//             clipped to the near plane in view space
//   stars   - one screen-space sprite per solar system

export const STAR_FLOATS = 8;
export const LINE_FLOATS = 12;
export const SPHERE_FLOATS = 12;

const SAMPLES = 4;

const COMMON_WGSL = /* wgsl */`
struct Frame {
    proj : mat4x4f,
    viewRot : mat4x4f,
    viewport : vec4f,   // width, height, 1/width, 1/height
    params : vec4f,     // tanHalfFovY, aspect, near, time
};
@group(0) @binding(0) var<uniform> frame : Frame;
`;

const STAR_WGSL = COMMON_WGSL + /* wgsl */`
struct Star {
    pos : vec3f,        // camera-relative, LY
    size : f32,         // radius in pixels
    color : vec4f,      // rgb, a = opacity
};
@group(1) @binding(0) var<storage, read> stars : array<Star>;

struct VOut {
    @builtin(position) pos : vec4f,
    @location(0) uv : vec2f,
    @location(1) color : vec4f,
};

@vertex
fn vs(@builtin(vertex_index) vi : u32, @builtin(instance_index) ii : u32) -> VOut {
    let s = stars[ii];
    var corners = array<vec2f, 6>(
        vec2f(-1.0, -1.0), vec2f(1.0, -1.0), vec2f(1.0, 1.0),
        vec2f(-1.0, -1.0), vec2f(1.0, 1.0), vec2f(-1.0, 1.0)
    );
    let corner = corners[vi];
    var out : VOut;
    out.uv = corner;
    out.color = s.color;
    let c = (frame.viewRot * vec4f(s.pos, 0.0)).xyz;
    if (c.z > -frame.params.z || s.size <= 0.0) {
        out.pos = vec4f(0.0, 0.0, -1.0, 1.0);   // behind the eye: clipped
        return out;
    }
    let clip = frame.proj * vec4f(c, 1.0);
    // divide here so the sprite is flat in screen space
    let ndc = clip.xy / clip.w;
    out.pos = vec4f(ndc + corner * s.size * 2.0 * frame.viewport.zw, 0.5, 1.0);
    return out;
}

@fragment
fn fs(in : VOut) -> @location(0) vec4f {
    let r = length(in.uv);
    if (r > 1.0) { discard; }
    let core = smoothstep(0.55, 0.35, r);
    let glow = (1.0 - r) * (1.0 - r) * 0.55;
    let a = clamp(core + glow, 0.0, 1.0) * in.color.a;
    let rgb = mix(in.color.rgb, vec3f(1.0), core * 0.35);
    return vec4f(rgb * a, a);
}
`;

const LINE_WGSL = COMMON_WGSL + /* wgsl */`
struct Seg {
    a : vec3f,          // camera-relative, LY
    width : f32,        // pixels
    b : vec3f,
    dash : f32,         // > 0: dashed (no cyno for this jump)
    color : vec4f,
};
@group(1) @binding(0) var<storage, read> segs : array<Seg>;

struct VOut {
    @builtin(position) pos : vec4f,
    @location(0) side : f32,
    @location(1) along : f32,
    @location(2) color : vec4f,
    @location(3) @interpolate(flat) dash : f32,
    @location(4) @interpolate(flat) halfWidth : f32,
};

fn toPx(c : vec3f) -> vec2f {
    let clip = frame.proj * vec4f(c, 1.0);
    return (clip.xy / clip.w * 0.5 + 0.5) * frame.viewport.xy;
}

@vertex
fn vs(@builtin(vertex_index) vi : u32, @builtin(instance_index) ii : u32) -> VOut {
    let s = segs[ii];
    var out : VOut;
    out.color = s.color;
    out.dash = s.dash;
    out.halfWidth = s.width * 0.5;
    var a = (frame.viewRot * vec4f(s.a, 0.0)).xyz;
    var b = (frame.viewRot * vec4f(s.b, 0.0)).xyz;
    let zn = -frame.params.z;
    if (a.z > zn && b.z > zn) {
        out.pos = vec4f(0.0, 0.0, -1.0, 1.0);
        return out;
    }
    // clip the leg to the near plane
    if (a.z > zn) { a = mix(a, b, (a.z - zn) / (a.z - b.z)); }
    if (b.z > zn) { b = mix(b, a, (b.z - zn) / (b.z - a.z)); }
    let pa = toPx(a);
    let pb = toPx(b);
    var dir = pb - pa;
    let len = length(dir);
    if (len < 1e-4) { dir = vec2f(1.0, 0.0); } else { dir = dir / len; }
    let nrm = vec2f(-dir.y, dir.x);
    var ends = array<f32, 6>(0.0, 1.0, 1.0, 0.0, 1.0, 0.0);
    var sides = array<f32, 6>(-1.0, -1.0, 1.0, -1.0, 1.0, 1.0);
    let t = ends[vi];
    let sd = sides[vi];
    let hw = s.width * 0.5 + 1.0;   // +1px for the antialiased edge
    let p = mix(pa, pb, t) + nrm * sd * hw;
    out.pos = vec4f(p * frame.viewport.zw * 2.0 - 1.0, 0.5, 1.0);
    out.side = sd * hw;
    out.along = t * len;
    return out;
}

@fragment
fn fs(in : VOut) -> @location(0) vec4f {
    var a = in.color.a;
    if (in.dash > 0.0 && fract(in.along / 14.0) > 0.55) { a = a * 0.15; }
    // in.side is pixels from the centre line; fade over the last pixel
    let edge = clamp(in.halfWidth + 0.5 - abs(in.side), 0.0, 1.0);
    let alpha = a * edge;
    return vec4f(in.color.rgb * alpha, alpha);
}
`;

const SPHERE_WGSL = COMMON_WGSL + /* wgsl */`
struct Sphere {
    center : vec3f,     // camera-relative, LY
    radius : f32,       // LY
    color : vec4f,      // rgb, a = strength
    params : vec4f,     // x: fullscreen, y: selected
};
@group(1) @binding(0) var<storage, read> spheres : array<Sphere>;

struct VOut {
    @builtin(position) pos : vec4f,
    @location(0) ray : vec3f,
    @location(1) @interpolate(flat) idx : u32,
};

@vertex
fn vs(@builtin(vertex_index) vi : u32, @builtin(instance_index) ii : u32) -> VOut {
    let s = spheres[ii];
    var corners = array<vec2f, 6>(
        vec2f(-1.0, -1.0), vec2f(1.0, -1.0), vec2f(1.0, 1.0),
        vec2f(-1.0, -1.0), vec2f(1.0, 1.0), vec2f(-1.0, 1.0)
    );
    let corner = corners[vi];
    var out : VOut;
    out.idx = ii;

    if (s.params.x > 0.5) {
        // eye inside or close to the shell: cover the screen and let the
        // ray test decide
        out.pos = vec4f(corner, 0.5, 1.0);
        out.ray = vec3f(corner.x * frame.params.x * frame.params.y, corner.y * frame.params.x, -1.0);
        return out;
    }

    let c = (frame.viewRot * vec4f(s.center, 0.0)).xyz;
    let d = length(c);
    let dir = c / d;
    // half-size of the tangent cone's cross-section at the centre's
    // distance - bounds the silhouette exactly, however far off-axis
    let half = s.radius * d / sqrt(max(d * d - s.radius * s.radius, 1e-12)) * 1.05;
    var up = vec3f(0.0, 1.0, 0.0);
    if (abs(dir.y) > 0.99) { up = vec3f(1.0, 0.0, 0.0); }
    let right = normalize(cross(dir, up));
    let up2 = cross(right, dir);
    let p = c + (right * corner.x + up2 * corner.y) * half;
    out.pos = frame.proj * vec4f(p, 1.0) / d;
    out.ray = p / d;
    return out;
}

const PI = 3.14159265;

// world-space lat/long grid lines every 15 degrees, antialiased by the
// screen-space derivative of the angle
fn grid(nw : vec3f) -> f32 {
    let lat = asin(clamp(nw.y, -1.0, 1.0));
    let lon = atan2(nw.z, nw.x);
    let step = PI / 12.0;
    let fl = abs(fract(lat / step + 0.5) - 0.5) * step;
    let fo = abs(fract(lon / step + 0.5) - 0.5) * step * cos(lat);
    let wl = max(fwidth(lat), 1e-5);
    let wo = max(fwidth(lon) * cos(lat), 1e-5);
    let gl = 1.0 - clamp(fl / wl - 0.5, 0.0, 1.0);
    let go = 1.0 - clamp(fo / wo - 0.5, 0.0, 1.0);
    return max(gl, go);
}

fn shell(n : vec3f, dir : vec3f, nw : vec3f, sel : f32) -> f32 {
    let fres = 1.0 - abs(dot(n, dir));
    let rim = pow(fres, 3.0);
    return 0.035 + rim * (0.55 + 0.35 * sel) + grid(nw) * (0.06 + 0.1 * sel);
}

@fragment
fn fs(in : VOut) -> @location(0) vec4f {
    let s = spheres[in.idx];
    let dir = normalize(in.ray);
    let oc = (frame.viewRot * vec4f(s.center, 0.0)).xyz;
    // the transpose of the rotation is its inverse
    let inv = transpose(mat3x3f(frame.viewRot[0].xyz, frame.viewRot[1].xyz, frame.viewRot[2].xyz));

    // numerically stable ray/sphere: measure the miss distance directly
    // instead of the textbook b*b - c
    let tca = dot(oc, dir);
    let h = oc - tca * dir;
    let h2 = dot(h, h);
    let r2 = s.radius * s.radius;
    if (h2 > r2) { discard; }
    let th = sqrt(r2 - h2);
    let t0 = tca - th;
    let t1 = tca + th;
    if (t1 < 0.0) { discard; }

    let sel = s.params.y;
    // both faces are shaded unconditionally: the grid uses derivatives,
    // which WGSL only allows in uniform control flow
    // back face: seen through the front, or alone from inside
    let n1 = (dir * t1 - oc) / s.radius;
    let back = shell(n1, dir, inv * n1, sel) * 0.45;
    let n0 = (dir * t0 - oc) / s.radius;
    let front = shell(n0, dir, inv * n0, sel);
    var a = back;
    if (t0 > 0.0) {
        // antialiased silhouette
        let pixelSize = max(t0, 1e-6) * 2.0 * frame.params.x * frame.viewport.w;
        a = (back + front) * clamp((s.radius - sqrt(h2)) / pixelSize + 0.5, 0.0, 1.0);
    }
    a = clamp(a * s.color.a, 0.0, 1.0);
    return vec4f(s.color.rgb * a, a);
}
`;

export class Renderer {

    static async create( canvas ) {
        if ( !navigator.gpu ) throw new Error( 'This browser does not support WebGPU.' );
        var adapter = await navigator.gpu.requestAdapter( { powerPreference: 'high-performance' } );
        if ( !adapter ) throw new Error( 'No suitable GPU adapter was found.' );
        var device = await adapter.requestDevice();
        return new Renderer( canvas, device );
    }

    constructor( canvas, device ) {
        this.canvas = canvas;
        this.device = device;
        this.context = canvas.getContext( 'webgpu' );
        this.format = navigator.gpu.getPreferredCanvasFormat();
        this.context.configure( { device: device, format: this.format, alphaMode: 'opaque' } );
        this.lost = false;
        device.lost.then( ( info ) => {
            this.lost = true;
            console.error( 'WebGPU device lost:', info.message );
        } );

        this.frameData = new Float32Array( 40 );
        this.frameBuffer = device.createBuffer( {
            size: this.frameData.byteLength,
            usage: GPUBufferUsage.UNIFORM | GPUBufferUsage.COPY_DST
        } );

        this.frameLayout = device.createBindGroupLayout( { entries: [
            { binding: 0, visibility: GPUShaderStage.VERTEX | GPUShaderStage.FRAGMENT, buffer: { type: 'uniform' } }
        ] } );
        this.instanceLayout = device.createBindGroupLayout( { entries: [
            { binding: 0, visibility: GPUShaderStage.VERTEX | GPUShaderStage.FRAGMENT, buffer: { type: 'read-only-storage' } }
        ] } );
        this.frameBindGroup = device.createBindGroup( {
            layout: this.frameLayout,
            entries: [ { binding: 0, resource: { buffer: this.frameBuffer } } ]
        } );
        var layout = device.createPipelineLayout( { bindGroupLayouts: [ this.frameLayout, this.instanceLayout ] } );

        // premultiplied alpha over
        var blend = {
            color: { srcFactor: 'one', dstFactor: 'one-minus-src-alpha', operation: 'add' },
            alpha: { srcFactor: 'one', dstFactor: 'one-minus-src-alpha', operation: 'add' }
        };
        var pipeline = ( label, code ) => {
            var module = device.createShaderModule( { label: label, code: code } );
            return device.createRenderPipeline( {
                label: label,
                layout: layout,
                vertex: { module: module, entryPoint: 'vs' },
                fragment: { module: module, entryPoint: 'fs', targets: [ { format: this.format, blend: blend } ] },
                primitive: { topology: 'triangle-list' },
                multisample: { count: SAMPLES }
            } );
        };
        this.starPipeline = pipeline( 'stars', STAR_WGSL );
        this.linePipeline = pipeline( 'lines', LINE_WGSL );
        this.spherePipeline = pipeline( 'spheres', SPHERE_WGSL );

        this.sets = {
            stars: { floats: STAR_FLOATS, count: 0, buffer: null, bindGroup: null, capacity: 0 },
            lines: { floats: LINE_FLOATS, count: 0, buffer: null, bindGroup: null, capacity: 0 },
            spheres: { floats: SPHERE_FLOATS, count: 0, buffer: null, bindGroup: null, capacity: 0 }
        };
        this.width = 0;
        this.height = 0;
    }

    resize( width, height ) {
        width = Math.max( 1, Math.floor( width ) );
        height = Math.max( 1, Math.floor( height ) );
        if ( width === this.width && height === this.height ) return;
        this.width = width;
        this.height = height;
        this.canvas.width = width;
        this.canvas.height = height;
        if ( this.msaaTexture ) this.msaaTexture.destroy();
        this.msaaTexture = this.device.createTexture( {
            size: [ width, height ], format: this.format, sampleCount: SAMPLES,
            usage: GPUTextureUsage.RENDER_ATTACHMENT
        } );
    }

    // viewRot is a column-major 3x3 (Float64Array(9)) world->view rotation
    setFrame( viewRot, fovY, near, time ) {
        var f = this.frameData;
        var aspect = this.width / this.height;
        var tanHalf = Math.tan( fovY / 2 );
        var focal = 1 / tanHalf;
        f.fill( 0 );
        // infinite far plane; there is no depth buffer, so z only has to
        // stay inside the clip volume in front of the eye
        f[ 0 ] = focal / aspect;
        f[ 5 ] = focal;
        f[ 11 ] = -1;
        f[ 14 ] = near;
        for ( var c = 0; c < 3; c++ ) {
            for ( var r = 0; r < 3; r++ ) f[ 16 + c * 4 + r ] = viewRot[ c * 3 + r ];
        }
        f[ 31 ] = 1;
        f[ 32 ] = this.width;
        f[ 33 ] = this.height;
        f[ 34 ] = 1 / this.width;
        f[ 35 ] = 1 / this.height;
        f[ 36 ] = tanHalf;
        f[ 37 ] = aspect;
        f[ 38 ] = near;
        f[ 39 ] = time;
        this.device.queue.writeBuffer( this.frameBuffer, 0, f );
    }

    // data: Float32Array of count * set.floats
    upload( name, data, count ) {
        var set = this.sets[ name ];
        set.count = count;
        if ( !count ) return;
        var bytes = count * set.floats * 4;
        if ( !set.buffer || set.capacity < bytes ) {
            if ( set.buffer ) set.buffer.destroy();
            set.capacity = Math.max( bytes, 256 ) * 2;
            set.buffer = this.device.createBuffer( {
                size: set.capacity,
                usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_DST
            } );
            set.bindGroup = this.device.createBindGroup( {
                layout: this.instanceLayout,
                entries: [ { binding: 0, resource: { buffer: set.buffer } } ]
            } );
        }
        this.device.queue.writeBuffer( set.buffer, 0, data, 0, count * set.floats );
    }

    render() {
        if ( this.lost || !this.msaaTexture ) return;
        var encoder = this.device.createCommandEncoder();
        var pass = encoder.beginRenderPass( {
            colorAttachments: [ {
                view: this.msaaTexture.createView(),
                resolveTarget: this.context.getCurrentTexture().createView(),
                clearValue: { r: 0.004, g: 0.006, b: 0.014, a: 1 },
                loadOp: 'clear',
                storeOp: 'discard'
            } ]
        } );
        pass.setBindGroup( 0, this.frameBindGroup );
        var draw = ( pipeline, set ) => {
            if ( !set.count ) return;
            pass.setPipeline( pipeline );
            pass.setBindGroup( 1, set.bindGroup );
            pass.draw( 6, set.count );
        };
        draw( this.spherePipeline, this.sets.spheres );
        draw( this.linePipeline, this.sets.lines );
        draw( this.starPipeline, this.sets.stars );
        pass.end();
        this.device.queue.submit( [ encoder.finish() ] );
    }
}
