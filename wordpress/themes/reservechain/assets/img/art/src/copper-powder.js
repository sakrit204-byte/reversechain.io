// Concept render: macro heap of fine copper powder. Not a product photograph.
import { THREE, W, H, createStage, finish, backdrop, studioEnv, rng, makeNoise, grainGeos, displayColor } from './common.js';

const EXPOSURE = 1.0;
const stage = createStage({ fov: 26, near: 0.1, far: 60 });
const { renderer, scene, camera } = stage;
scene.environment = studioEnv(renderer, [
  { w: 9, h: 4, pos: [-2, 9, 3], i: 2.2, color: '#FFF3E2' },
  { w: 2.2, h: 9, pos: [8, 2.5, -7], i: 7, color: '#FFC274' },
  { w: 2, h: 7, pos: [-9, 1.5, -2], i: 1.2, color: '#9FB6C8' },
  { w: 6, h: 1.2, pos: [0, 0.5, 10], i: 0.8, color: '#FFE2B8' },
]);
scene.environmentIntensity = 0.55;
backdrop(scene, { top: '#0C1926', bottom: '#09141E', exposure: EXPOSURE, glows: [
  { x: 0.6, y: 0.72, r: 0.3, color: '#D5B167', i: 0.07 },
  { x: 0.25, y: 0.85, r: 0.5, color: '#2A4258', i: 0.02 },
] });
scene.fog = new THREE.Fog(displayColor('#0A1520', EXPOSURE), 8, 20);

const N = makeNoise(11);
const R = rng(2024);
const H0 = 1.0, RAD = 1.75;
function heightAt(x, z) {
  const r = Math.hypot(x, z * 1.08);
  const a = 0.45;
  let h = H0 * (1 - (Math.sqrt(r * r + a * a) - a) / (RAD - a * 0.3));
  h += 0.06 * N.fbm(x * 2.2 + 3, 0.5, z * 2.2, 4) * Math.min(1, Math.max(0, h) * 3);
  h += 0.018 * N.fbm(x * 9, 1.7, z * 9, 3);
  // soft foot
  const foot = Math.max(0, h);
  return foot < 0.12 ? 0.12 * Math.pow(foot / 0.12, 1.6) : foot;
}

// --- floor ---
const floor = new THREE.Mesh(new THREE.PlaneGeometry(80, 80), new THREE.MeshPhysicalMaterial({ color: '#0B1118', roughness: 0.42, metalness: 0.0, clearcoat: 0.6, clearcoatRoughness: 0.22 }));
floor.rotation.x = -Math.PI / 2; floor.receiveShadow = true; scene.add(floor);

// --- solid core under the grain skin (prevents see-through gaps) ---
{
  const seg = 260; const g = new THREE.PlaneGeometry(RAD * 2.3, RAD * 2.3, seg, seg); g.rotateX(-Math.PI / 2);
  const p = g.attributes.position;
  for (let i = 0; i < p.count; i++) { const x = p.getX(i), z = p.getZ(i); p.setY(i, Math.max(-0.02, heightAt(x, z) - 0.03)); }
  g.computeVertexNormals();
  const core = new THREE.Mesh(g, new THREE.MeshPhysicalMaterial({ color: '#5A2A12', metalness: 0.6, roughness: 0.75 }));
  core.receiveShadow = true; core.castShadow = true; scene.add(core);
}

// --- grains ---
const palette = ['#C9773F', '#B9662F', '#D88A52', '#E8A06A', '#A9572A', '#C57C4E', '#9C4F25', '#DB9466'].map(c => new THREE.Color(c));
const geos = grainGeos(5, 77, 1, 0.34);
const mats = geos.map(() => new THREE.MeshPhysicalMaterial({ color: '#ffffff', metalness: 1, roughness: 0.38, envMapIntensity: 1.0 }));
const buckets = geos.map(() => []);
const dummy = new THREE.Object3D();
const tmpC = new THREE.Color();
function addGrain(x, y, z, s, shade) {
  dummy.position.set(x, y, z);
  dummy.rotation.set(R() * 6.3, R() * 6.3, R() * 6.3);
  dummy.scale.setScalar(s);
  dummy.updateMatrix();
  tmpC.copy(palette[(R() * palette.length) | 0]).multiplyScalar(shade * (0.8 + R() * 0.4));
  buckets[(R() * geos.length) | 0].push([dummy.matrix.clone(), tmpC.clone()]);
}
const grainR = () => 0.0078 * Math.exp((R() - 0.5) * 0.8);
// heap skin
let placed = 0;
const target = 125000;
while (placed < target) {
  const ang = R() * Math.PI * 2, rr = Math.sqrt(R()) * 2.1;
  const x = Math.cos(ang) * rr, z = Math.sin(ang) * rr / 1.08;
  const h = heightAt(x, z); if (h < 0.004) continue;
  const e = 0.01; const gx = (heightAt(x + e, z) - h) / e, gz = (heightAt(x, z + e) - h) / e;
  const w = Math.sqrt(1 + gx * gx + gz * gz) / 2.2; if (R() > w) continue;
  const d = Math.pow(R(), 1.8) * 0.032;
  const s = grainR();
  addGrain(x, h - d + s * 0.3, z, s, 1 - (d / 0.032) * 0.6);
  placed++;
}
// scattered grains on the floor around the foot + in the foreground (out of focus)
for (let i = 0; i < 3800; i++) {
  const ang = R() * Math.PI * 2, rr = 1.95 + Math.pow(R(), 2.2) * 1.3;
  const s = grainR() * (0.8 + R() * 0.4);
  addGrain(Math.cos(ang) * rr, s * 0.55, Math.sin(ang) * rr / 1.08, s, 0.95);
}
for (let i = 0; i < 160; i++) { // foreground scatter toward camera
  const x = -2.2 + R() * 4.4, z = 2.6 + Math.pow(R(), 0.8) * 3.2;
  const s = grainR() * (1.0 + R() * 0.8);
  addGrain(x, s * 0.55, z, s, 1);
}
for (let i = 0; i < 9; i++) { // small foreground clumps
  const cx = -2.0 + R() * 3.6, cz = 3.2 + R() * 2.4;
  for (let k = 0; k < 18; k++) { const s = grainR() * 1.2; addGrain(cx + (R() - 0.5) * 0.06, s * (0.55 + R() * 1.4), cz + (R() - 0.5) * 0.06, s, 0.9); }
}
let total = 0;
buckets.forEach((b, k) => {
  const im = new THREE.InstancedMesh(geos[k], mats[k], b.length);
  b.forEach(([m, c], i) => { im.setMatrixAt(i, m); im.setColorAt(i, c); });
  im.castShadow = true; im.receiveShadow = true; scene.add(im); total += b.length;
});
console.log('grains', total);

// --- lights ---
const key = new THREE.DirectionalLight('#FFF0DC', 1.9);
key.position.set(-3.2, 4.2, 2.6); key.castShadow = true;
key.shadow.mapSize.set(4096, 4096); key.shadow.camera.left = -3; key.shadow.camera.right = 3; key.shadow.camera.top = 3; key.shadow.camera.bottom = -3;
key.shadow.camera.near = 1; key.shadow.camera.far = 14; key.shadow.bias = -0.0004; key.shadow.normalBias = 0.004; key.shadow.radius = 2;
scene.add(key);
const rim = new THREE.DirectionalLight('#FFC56E', 16.0); rim.position.set(3.8, 1.9, -4.0); rim.castShadow = true;
rim.shadow.mapSize.set(4096, 4096); Object.assign(rim.shadow.camera, { left: -3, right: 3, top: 3, bottom: -3, near: 1, far: 14 }); rim.shadow.bias = -0.0004; rim.shadow.normalBias = 0.004;
scene.add(rim);
const rim2 = new THREE.DirectionalLight('#E8A06A', 2.5); rim2.position.set(-4, 1.2, -3.5); scene.add(rim2);
const fill = new THREE.DirectionalLight('#8FA9C0', 0.35); fill.position.set(2, 1, 5); scene.add(fill);

camera.position.set(0.7, 1.25, 7.2);
camera.lookAt(0.15, 0.48, 0);
camera.setViewOffset(W, H, -W * 0.03, H * 0.02, W, H);

finish(stage, { exposure: EXPOSURE, dof: { focus: 6.15, aperture: 80, maxBlur: 34, radScale: 0.9 }, bloom: { strength: 0.28, radius: 0.55, threshold: 1.0 }, vignette: 0.4, vCenter: [0.52, 0.5] });
