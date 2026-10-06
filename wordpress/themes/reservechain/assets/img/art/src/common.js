// Shared rendering pipeline for ReserveChain concept renders (three.js).
// HDR scene (MSAA) -> optional gather depth-of-field -> bloom -> ACES filmic + vignette + dither.
// Concept art only: no text, labels, purity marks or brand names inside scenes (emblem excepted).
import * as THREE from 'three';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { FullScreenQuad } from 'three/addons/postprocessing/Pass.js';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';
import { mergeGeometries, mergeVertices } from 'three/addons/utils/BufferGeometryUtils.js';
export { THREE, RoundedBoxGeometry, mergeGeometries, mergeVertices };

const qs = new URLSearchParams(location.search);
export const W = +qs.get('w') || 1200;
export const H = +qs.get('h') || 800;

export const BRAND = {
  bg0: '#050C14', bg1: '#0A141F', gold: '#D5B167', goldLight: '#EED69C', goldDeep: '#B48430',
  copper: '#C9773F', copperLight: '#E8A06A', copperDeep: '#8E4A20', nickel: '#A9BBC8', nickelLight: '#D2DDE5',
};

export function rng(seed = 1) {
  let s = seed >>> 0;
  return () => { s = (s + 0x6D2B79F5) >>> 0; let t = s; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}
// value noise / fbm (deterministic)
export function makeNoise(seed = 7) {
  const r = rng(seed); const P = new Float32Array(4096); for (let i = 0; i < 4096; i++) P[i] = r();
  const h = (x, y, z) => P[((x * 73856093) ^ (y * 19349663) ^ (z * 83492791)) & 4095];
  const sm = t => t * t * (3 - 2 * t);
  const l = (a, b, t) => a + (b - a) * t;
  const n3 = (x, y, z) => {
    const xi = Math.floor(x), yi = Math.floor(y), zi = Math.floor(z); const xf = sm(x - xi), yf = sm(y - yi), zf = sm(z - zi);
    return l(l(l(h(xi, yi, zi), h(xi + 1, yi, zi), xf), l(h(xi, yi + 1, zi), h(xi + 1, yi + 1, zi), xf), yf),
             l(l(h(xi, yi, zi + 1), h(xi + 1, yi, zi + 1), xf), l(h(xi, yi + 1, zi + 1), h(xi + 1, yi + 1, zi + 1), xf), yf), zf) * 2 - 1;
  };
  const fbm = (x, y, z, o = 4) => { let a = 0, f = 1, amp = .5; for (let i = 0; i < o; i++) { a += amp * n3(x * f, y * f, z * f); f *= 2.03; amp *= .5; } return a; };
  return { n3, fbm };
}

// ---- ACES (identical to three.js ACESFilmicToneMapping) and its numeric inverse ----
function acesJS(c, exposure) {
  const [r, g, b] = c.map(v => v * exposure / 0.6);
  const i = [0.59719 * r + 0.35458 * g + 0.04823 * b, 0.07600 * r + 0.90834 * g + 0.01566 * b, 0.02840 * r + 0.13383 * g + 0.83777 * b];
  const f = i.map(v => (v * (v + 0.0245786) - 0.000090537) / (v * (0.983729 * v + 0.4329510) + 0.238081));
  return [1.60475 * f[0] - 0.53108 * f[1] - 0.07367 * f[2], -0.10208 * f[0] + 1.10813 * f[1] - 0.00605 * f[2], -0.00327 * f[0] - 0.07276 * f[1] + 1.07602 * f[2]];
}
// Linear HDR colour that, after the final pass (ACES + sRGB), displays as the given sRGB hex.
export function displayColor(hex, exposure = 1) {
  const c = new THREE.Color(hex); const t = [c.r, c.g, c.b];
  const x = t.map(v => v * 0.6 + 0.004);
  for (let it = 0; it < 300; it++) {
    const y = acesJS(x, exposure);
    for (let k = 0; k < 3; k++) {
      const e = 1e-5; const xp = x.slice(); xp[k] += e; const d = (acesJS(xp, exposure)[k] - y[k]) / e;
      x[k] = Math.max(0, x[k] + (t[k] - y[k]) / Math.max(d, 1e-3) * 0.6);
    }
  }
  return new THREE.Color(x[0], x[1], x[2]);
}

// ---- Procedural studio environment (PMREM) ----
export function studioEnv(renderer, panels = [], { base = 0.01, baseColor = '#0A141F', floor = '#020509' } = {}) {
  const s = new THREE.Scene();
  const room = new THREE.Mesh(new THREE.BoxGeometry(30, 16, 30), new THREE.MeshBasicMaterial({ color: new THREE.Color(baseColor).multiplyScalar(base / 0.006), side: THREE.BackSide }));
  room.position.y = 5; s.add(room);
  const fl = new THREE.Mesh(new THREE.PlaneGeometry(30, 30), new THREE.MeshBasicMaterial({ color: new THREE.Color(floor) }));
  fl.rotation.x = -Math.PI / 2; fl.position.y = -2.9; s.add(fl);
  for (const p of panels) {
    const geo = p.round ? new THREE.CircleGeometry(p.w / 2, 48) : new THREE.PlaneGeometry(p.w, p.h);
    const m = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ color: new THREE.Color(p.color || '#ffffff').multiplyScalar(p.i ?? 4), side: THREE.DoubleSide }));
    m.position.set(...p.pos); m.lookAt(...(p.look || [0, 0, 0])); s.add(m);
  }
  const pm = new THREE.PMREMGenerator(renderer);
  const tex = pm.fromScene(s, 0.02).texture; pm.dispose();
  return tex;
}

// ---- Screen-space background gradient (part of the HDR scene, far depth) ----
export function backdrop(scene, { top = '#0A141F', bottom = '#050C14', glows = [], exposure = 1 } = {}) {
  const pad = (a, f) => a.concat(Array(4).fill(0).map(f)).slice(0, 4);
  const u = {
    cTop: { value: displayColor(top, exposure) }, cBot: { value: displayColor(bottom, exposure) },
    gPos: { value: pad(glows.map(g => new THREE.Vector3(g.x, g.y, g.r)), () => new THREE.Vector3(0, 0, 0.001)) },
    gCol: { value: pad(glows.map(g => new THREE.Color(g.color).multiplyScalar(g.i ?? 0.05)), () => new THREE.Color(0)) },
    aspect: { value: W / H },
  };
  const m = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), new THREE.ShaderMaterial({
    uniforms: u, depthWrite: false, depthTest: false,
    vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = vec4(position.xy, 0.99999, 1.0); }',
    fragmentShader: `varying vec2 vUv; uniform vec3 cTop, cBot; uniform vec3 gPos[4]; uniform vec3 gCol[4]; uniform float aspect;
      void main(){ vec3 c = mix(cBot, cTop, smoothstep(0.0, 1.0, vUv.y));
        for(int i=0;i<4;i++){ vec2 d = vUv - gPos[i].xy; d.x *= aspect; float r = max(gPos[i].z, 1e-3); c += gCol[i] * exp(-dot(d,d)/(r*r)); }
        gl_FragColor = vec4(c, 1.0); }`,
  }));
  m.frustumCulled = false; m.renderOrder = -1000; m.castShadow = false; m.receiveShadow = false;
  scene.add(m); return m;
}

// ---- Renderer + post pipeline ----
const DOF_FS = `
uniform sampler2D tColor; uniform sampler2D tDepth; uniform vec2 px; uniform float cameraNear, cameraFar;
uniform float focus, aperture, maxBlur, radScale;
varying vec2 vUv;
float viewZ(vec2 uv){ float d = texture2D(tDepth, uv).x; return (cameraNear * cameraFar) / ((cameraFar - cameraNear) * d - cameraFar); }
float coc(float z){ z = -z; return clamp(abs(z - focus) / z * aperture, 0.0, maxBlur); }
void main(){
  float cz = viewZ(vUv); float cs = coc(cz);
  vec3 col = texture2D(tColor, vUv).rgb; float tot = 1.0; float radius = radScale; float ang = 0.0;
  for (int i = 0; i < 2000; i++) {
    if (radius >= maxBlur) break;
    vec2 tc = vUv + vec2(cos(ang), sin(ang)) * px * radius;
    vec3 sc = texture2D(tColor, tc).rgb; float sz = viewZ(tc); float ss = coc(sz);
    if (sz < cz) ss = clamp(ss, 0.0, cs * 2.0);
    float m = smoothstep(radius - 0.5, radius + 0.5, ss);
    col += mix(col / tot, sc, m); tot += 1.0; radius += radScale / radius; ang += 2.39996323;
  }
  gl_FragColor = vec4(col / tot, 1.0);
}`;
const FINAL_FS = `
uniform sampler2D tColor; uniform float exposure, vignette, dither, aspect, ca; uniform vec2 vCenter; uniform vec2 px;
varying vec2 vUv;
vec3 RRTAndODTFit(vec3 v){ vec3 a = v*(v+0.0245786)-0.000090537; vec3 b = v*(0.983729*v+0.4329510)+0.238081; return a/b; }
vec3 aces(vec3 color){
  const mat3 I = mat3(vec3(0.59719,0.07600,0.02840), vec3(0.35458,0.90834,0.13383), vec3(0.04823,0.01566,0.83777));
  const mat3 O = mat3(vec3(1.60475,-0.10208,-0.00327), vec3(-0.53108,1.10813,-0.07276), vec3(-0.07367,-0.00605,1.07602));
  color *= exposure / 0.6; color = I * color; color = RRTAndODTFit(color); color = O * color; return clamp(color, 0.0, 1.0); }
vec3 toSRGB(vec3 c){ return mix(c * 12.92, 1.055 * pow(c, vec3(1.0/2.4)) - 0.055, step(0.0031308, c)); }
float hash(vec2 p){ return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
void main(){
  vec2 d = vUv - vCenter; d.x *= aspect;
  vec2 off = (vUv - 0.5) * ca * px;
  vec3 c = vec3(texture2D(tColor, vUv + off).r, texture2D(tColor, vUv).g, texture2D(tColor, vUv - off).b);
  c = aces(c);
  c *= 1.0 - vignette * smoothstep(0.25, 1.05, length(d));
  c = toSRGB(c);
  c += (hash(gl_FragCoord.xy) + hash(gl_FragCoord.yx + 3.1) - 1.0) / 255.0 * dither;
  gl_FragColor = vec4(c, 1.0);
}`;
const VS = 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }';

export function createStage({ fov = 30, near = 0.05, far = 200, shadows = true } = {}) {
  const renderer = new THREE.WebGLRenderer({ antialias: false, preserveDrawingBuffer: true, powerPreference: 'high-performance' });
  renderer.setPixelRatio(1); renderer.setSize(W, H);
  renderer.toneMapping = THREE.NoToneMapping;
  renderer.shadowMap.enabled = shadows; renderer.shadowMap.type = THREE.PCFShadowMap;
  document.body.appendChild(renderer.domElement);
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(fov, W / H, near, far);
  return { renderer, scene, camera };
}

export function finish({ renderer, scene, camera }, { exposure = 1, dof = null, bloom = { strength: 0.35, radius: 0.6, threshold: 1.2 }, vignette = 0.35, vCenter = [0.5, 0.5], ca = 0.6, samples = 4 } = {}) {
  const t0 = performance.now();
  const rt = new THREE.WebGLRenderTarget(W, H, { type: THREE.HalfFloatType, samples, depthTexture: new THREE.DepthTexture(W, H, THREE.FloatType) });
  const rtB = new THREE.WebGLRenderTarget(W, H, { type: THREE.HalfFloatType });
  camera.updateMatrixWorld();
  renderer.setRenderTarget(rt); renderer.render(scene, camera);
  let cur = rt;
  if (dof) {
    const mat = new THREE.ShaderMaterial({ vertexShader: VS, fragmentShader: DOF_FS, uniforms: {
      tColor: { value: rt.texture }, tDepth: { value: rt.depthTexture }, px: { value: new THREE.Vector2(1 / W, 1 / H) },
      cameraNear: { value: camera.near }, cameraFar: { value: camera.far },
      focus: { value: dof.focus }, aperture: { value: dof.aperture * W / 2400 }, maxBlur: { value: (dof.maxBlur ?? 28) * W / 2400 }, radScale: { value: (dof.radScale ?? 0.9) * Math.sqrt(W / 2400) },
    } });
    renderer.setRenderTarget(rtB); new FullScreenQuad(mat).render(renderer); cur = rtB;
  }
  if (bloom) {
    const bp = new UnrealBloomPass(new THREE.Vector2(W, H), bloom.strength, bloom.radius, bloom.threshold);
    bp.render(renderer, null, cur, 0, false);
  }
  const fin = new THREE.ShaderMaterial({ vertexShader: VS, fragmentShader: FINAL_FS, uniforms: {
    tColor: { value: cur.texture }, exposure: { value: exposure }, vignette: { value: vignette }, dither: { value: 1.0 },
    aspect: { value: W / H }, vCenter: { value: new THREE.Vector2(...vCenter) }, ca: { value: ca * W / 2400 }, px: { value: new THREE.Vector2(1 / W, 1 / H) },
  } });
  renderer.setRenderTarget(null); new FullScreenQuad(fin).render(renderer);
  const gl = renderer.getContext(); const b = new Uint8Array(4); gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, b);
  console.log('render ms', Math.round(performance.now() - t0), 'tris', renderer.info.render.triangles, 'gpu', gl.getParameter(gl.RENDERER));
  window.__done = true;
}

export function metal(color, rough = 0.3, extra = {}) { return new THREE.MeshPhysicalMaterial({ color, metalness: 1, roughness: rough, ...extra }); }

// Irregular grain geometries (jittered icosahedra), returns array of geometries
export function grainGeos(n = 4, seed = 3, detail = 1, jitter = 0.28) {
  const r = rng(seed); const out = [];
  for (let k = 0; k < n; k++) {
    let g = new THREE.IcosahedronGeometry(1, detail); g.deleteAttribute('normal'); g.deleteAttribute('uv'); g = mergeVertices(g);
    const p = g.attributes.position; const sx = 0.8 + r() * 0.4, sy = 0.75 + r() * 0.35, sz = 0.8 + r() * 0.4;
    for (let i = 0; i < p.count; i++) { const f = 1 + (r() - 0.5) * jitter; p.setXYZ(i, p.getX(i) * f * sx, p.getY(i) * f * sy, p.getZ(i) * f * sz); }
    g.computeVertexNormals(); out.push(g);
  }
  return out;
}
