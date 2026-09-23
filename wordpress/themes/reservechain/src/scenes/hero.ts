/**
 * Hero scene — copper powder and nickel wire.
 *
 * The design direction rules out "generic cryptocurrency coins" and
 * "meme-token imagery", and rules out "unrealistic vault or warehouse renders
 * presented as actual facilities". So this scene shows neither a vault nor a
 * token. It shows the two materials the platform is actually about:
 *
 *   • a drifting field of fine copper particles, the powder
 *   • a nickel helix, the 0.025 mm wire on its bobbin
 *   • a faint containment shell, the packaging that makes a unit identifiable
 *
 * It is abstract on purpose. Rendering a photorealistic ampoule would be a
 * picture of an asset ReserveChain does not yet hold, which is exactly the
 * kind of implied claim the brief prohibits. An abstract material study
 * carries the right associations — precision, metallurgy, containment —
 * without asserting anything.
 *
 * Everything is disposed on teardown, the loop pauses when the hero scrolls
 * away or the tab is hidden, and the whole module is only imported after the
 * capability check passes.
 */

import {
	AdditiveBlending,
	BufferAttribute,
	BufferGeometry,
	Color,
	Fog,
	Group,
	Mesh,
	MeshStandardMaterial,
	PerspectiveCamera,
	Points,
	PointsMaterial,
	PointLight,
	Scene,
	TubeGeometry,
	Vector3,
	WebGLRenderer,
	AmbientLight,
	CatmullRomCurve3,
	TorusGeometry,
	MeshBasicMaterial,
} from 'three';

import { onVisibilityChange, renderScale } from '../lib/capability';

export interface SceneHandle {
	destroy(): void;
}

interface Options {
	tier: 'low' | 'full';
}

const COPPER = 0xb4663a;
const COPPER_BRIGHT = 0xd98e5a;
const NICKEL = 0x9ba4ae;
const GOLD = 0xc9a227;
const BASE = 0x070a12;

export function createHeroScene(container: HTMLElement, { tier }: Options): SceneHandle {
	const particleCount = tier === 'full' ? 7000 : 2600;
	const tubularSegments = tier === 'full' ? 260 : 120;

	const renderer = new WebGLRenderer({
		antialias: tier === 'full',
		alpha: true,
		powerPreference: 'low-power',
	});

	renderer.setPixelRatio(renderScale(tier));
	renderer.setClearColor(new Color(BASE), 0);

	const canvas = renderer.domElement;
	canvas.setAttribute('aria-hidden', 'true');
	canvas.style.display = 'block';
	canvas.style.width = '100%';
	canvas.style.height = '100%';
	container.appendChild(canvas);

	const scene = new Scene();
	scene.fog = new Fog(BASE, 6, 22);

	const camera = new PerspectiveCamera(42, 1, 0.1, 100);
	camera.position.set(0, 0.2, 9);

	const world = new Group();
	scene.add(world);

	// ---------------------------------------------------------------------
	// Copper powder
	//
	// Distributed through a shell rather than a solid ball: a solid cloud
	// reads as fog, while a shell keeps the silhouette of a contained volume
	// and leaves the wire visible through the middle.
	// ---------------------------------------------------------------------
	const positions = new Float32Array(particleCount * 3);
	const scales = new Float32Array(particleCount);
	const drift = new Float32Array(particleCount);

	for (let i = 0; i < particleCount; i++) {
		const radius = 2.6 + Math.random() * 1.5;
		const theta = Math.random() * Math.PI * 2;
		// Bias toward the equator so the cloud reads as a disc-ish volume
		// rather than a perfect sphere, which looks synthetic.
		const phi = Math.acos(1 - 2 * Math.random()) * (0.55 + Math.random() * 0.45);

		positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
		positions[i * 3 + 1] = radius * Math.cos(phi) * 0.55;
		positions[i * 3 + 2] = radius * Math.sin(phi) * Math.sin(theta);

		scales[i] = 0.4 + Math.random() * 0.6;
		drift[i] = Math.random() * Math.PI * 2;
	}

	const powderGeometry = new BufferGeometry();
	powderGeometry.setAttribute('position', new BufferAttribute(positions, 3));

	const powderMaterial = new PointsMaterial({
		color: new Color(COPPER_BRIGHT),
		size: tier === 'full' ? 0.055 : 0.075,
		sizeAttenuation: true,
		transparent: true,
		opacity: 1,
		depthWrite: false,
		blending: AdditiveBlending,
	});

	const powder = new Points(powderGeometry, powderMaterial);
	world.add(powder);

	// ---------------------------------------------------------------------
	// Nickel wire — a helix, the shape wire takes on a bobbin
	// ---------------------------------------------------------------------
	const helixPoints: Vector3[] = [];
	const turns = 7;
	const helixSamples = 240;

	for (let i = 0; i <= helixSamples; i++) {
		const t = i / helixSamples;
		const angle = t * Math.PI * 2 * turns;
		const radius = 1.55 + Math.sin(t * Math.PI) * 0.22;

		helixPoints.push(
			new Vector3(
				Math.cos(angle) * radius,
				(t - 0.5) * 3.1,
				Math.sin(angle) * radius
			)
		);
	}

	const helixCurve = new CatmullRomCurve3(helixPoints);

	// 0.025 mm wire is invisible at any honest scale, so the tube is a
	// legible stand-in, not a measurement. Nothing on screen is labelled with
	// a dimension for exactly that reason.
	const wireGeometry = new TubeGeometry(helixCurve, tubularSegments, 0.032, 8, false);

	const wireMaterial = new MeshStandardMaterial({
		color: new Color(NICKEL),
		metalness: 0.9,
		roughness: 0.22,
		emissive: new Color(NICKEL),
		emissiveIntensity: 0.16,
	});

	const wire = new Mesh(wireGeometry, wireMaterial);
	world.add(wire);

	// ---------------------------------------------------------------------
	// Containment ring — the unit boundary that makes a passport possible
	// ---------------------------------------------------------------------
	const ringGeometry = new TorusGeometry(3.05, 0.012, 6, 160);
	const ringMaterial = new MeshBasicMaterial({
		color: new Color(GOLD),
		transparent: true,
		opacity: 0.6,
	});

	const ring = new Mesh(ringGeometry, ringMaterial);
	ring.rotation.x = Math.PI / 2;
	world.add(ring);

	const ringOuter = new Mesh(
		new TorusGeometry(3.42, 0.008, 6, 160),
		new MeshBasicMaterial({ color: new Color(GOLD), transparent: true, opacity: 0.32 })
	);
	ringOuter.rotation.x = Math.PI / 2;
	ringOuter.rotation.z = 0.4;
	world.add(ringOuter);

	// ---------------------------------------------------------------------
	// Light — warm key, cool fill, so copper and nickel read as themselves
	// ---------------------------------------------------------------------
	scene.add(new AmbientLight(0xffffff, 0.5));

	const keyLight = new PointLight(GOLD, 120, 34);
	keyLight.position.set(4.5, 3.5, 5);
	scene.add(keyLight);

	const fillLight = new PointLight(COPPER, 70, 30);
	fillLight.position.set(-5, -2.5, 3);
	scene.add(fillLight);

	const rimLight = new PointLight(0xffffff, 45, 28);
	rimLight.position.set(-2, 4, -6);
	scene.add(rimLight);

	// ---------------------------------------------------------------------
	// Interaction: damped pointer parallax
	// ---------------------------------------------------------------------
	const pointer = { x: 0, y: 0 };
	const target = { x: 0, y: 0 };

	function handlePointer(event: PointerEvent): void {
		const rect = container.getBoundingClientRect();
		target.x = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
		target.y = ((event.clientY - rect.top) / rect.height - 0.5) * 2;
	}

	// Pointer parallax is a desktop affordance. On touch it would fight the
	// scroll gesture, so it is simply not attached.
	const coarse = window.matchMedia('(pointer: coarse)').matches;

	if (!coarse) {
		window.addEventListener('pointermove', handlePointer, { passive: true });
	}

	// ---------------------------------------------------------------------
	// Sizing
	// ---------------------------------------------------------------------
	function resize(): void {
		const { clientWidth, clientHeight } = container;
		const width = Math.max(clientWidth, 1);
		const height = Math.max(clientHeight, 1);

		renderer.setSize(width, height, false);
		camera.aspect = width / height;

		// Pull the camera back on narrow viewports so the composition is not
		// cropped into an unreadable slab on a phone.
		camera.position.z = width < 720 ? 12 : 9;
		camera.updateProjectionMatrix();
	}

	const resizeObserver = new ResizeObserver(resize);
	resizeObserver.observe(container);
	resize();

	// ---------------------------------------------------------------------
	// Loop
	// ---------------------------------------------------------------------
	let frame = 0;
	let running = true;
	let inView = true;
	let lastTime = performance.now();

	const stopVisibility = onVisibilityChange(container, (visible) => {
		inView = visible;

		if (visible && running && !frame) {
			lastTime = performance.now();
			frame = requestAnimationFrame(tick);
		}
	});

	function handleDocumentVisibility(): void {
		if (document.hidden) {
			cancel();
		} else if (running && inView) {
			lastTime = performance.now();
			frame = requestAnimationFrame(tick);
		}
	}

	document.addEventListener('visibilitychange', handleDocumentVisibility);

	function cancel(): void {
		if (frame) {
			cancelAnimationFrame(frame);
			frame = 0;
		}
	}

	function tick(now: number): void {
		frame = 0;

		if (!running || !inView || document.hidden) {
			return;
		}

		// Delta-timed, and clamped so a backgrounded tab returning does not
		// jump the animation forward by seconds.
		const delta = Math.min((now - lastTime) / 1000, 0.05);
		lastTime = now;

		const elapsed = now / 1000;

		world.rotation.y += delta * 0.12;
		wire.rotation.y -= delta * 0.05;
		powder.rotation.y += delta * 0.04;
		powder.rotation.x = Math.sin(elapsed * 0.15) * 0.06;

		ring.rotation.z += delta * 0.08;
		ringOuter.rotation.z -= delta * 0.05;

		// Damped parallax: the camera eases toward the pointer instead of
		// tracking it exactly, which stops the scene feeling twitchy.
		pointer.x += (target.x - pointer.x) * Math.min(delta * 2.4, 1);
		pointer.y += (target.y - pointer.y) * Math.min(delta * 2.4, 1);

		camera.position.x = pointer.x * 0.55;
		camera.position.y = 0.2 - pointer.y * 0.35;
		camera.lookAt(0, 0, 0);

		keyLight.intensity = 118 + Math.sin(elapsed * 0.6) * 14;

		renderer.render(scene, camera);

		frame = requestAnimationFrame(tick);
	}

	// Draw one frame synchronously before the loop starts.
	//
	// The loop is paused whenever the document is hidden, and browsers do not
	// run requestAnimationFrame in a background tab at all. Without this, a
	// scene created in a hidden tab — or one screenshotted before the first
	// frame — would present an empty canvas. Rendering once up front means the
	// canvas always holds a composed image, and the loop only ever animates it.
	renderer.render(scene, camera);

	frame = requestAnimationFrame(tick);

	// Let CSS fade the canvas in rather than popping it on.
	container.setAttribute('data-rc-scene-state', 'ready');

	return {
		destroy(): void {
			running = false;
			cancel();
			stopVisibility();
			resizeObserver.disconnect();
			document.removeEventListener('visibilitychange', handleDocumentVisibility);

			if (!coarse) {
				window.removeEventListener('pointermove', handlePointer);
			}

			powderGeometry.dispose();
			powderMaterial.dispose();
			wireGeometry.dispose();
			wireMaterial.dispose();
			ringGeometry.dispose();
			ringMaterial.dispose();
			(ringOuter.geometry as TorusGeometry).dispose();
			(ringOuter.material as MeshBasicMaterial).dispose();

			renderer.dispose();
			canvas.remove();

			container.removeAttribute('data-rc-scene-state');
		},
	};
}
