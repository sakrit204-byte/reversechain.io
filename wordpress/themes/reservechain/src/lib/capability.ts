/**
 * Capability and preference detection.
 *
 * Every enhancement on this site asks here before it runs. The brief calls for
 * "controlled animation" and forbids decorative motion that degrades loading
 * or mobile performance, so the default answer is no: a scene runs only when
 * the visitor has not asked for less motion, the device can plausibly handle
 * it, and the element is actually on screen.
 */

/** Whether the visitor has asked for reduced motion. */
export function prefersReducedMotion(): boolean {
	return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Whether the visitor has asked the browser to save data. */
export function prefersReducedData(): boolean {
	const connection = (navigator as Navigator & { connection?: { saveData?: boolean; effectiveType?: string } }).connection;

	if (!connection) return false;
	if (connection.saveData) return true;

	return /(^|-)2g$/.test(connection.effectiveType ?? '');
}

/**
 * Whether WebGL2 is available.
 *
 * Probed on a throwaway canvas that is disposed immediately: creating a
 * context and abandoning it can otherwise hold GPU memory on some drivers.
 */
export function hasWebGL(): boolean {
	try {
		const canvas = document.createElement('canvas');
		const gl = canvas.getContext('webgl2');

		if (!gl) return false;

		gl.getExtension('WEBGL_lose_context')?.loseContext();

		return true;
	} catch {
		return false;
	}
}

/**
 * A rough capability tier.
 *
 * Deliberately conservative. Hardware concurrency and device memory are
 * coarse, but they are enough to avoid running a particle field on a low-end
 * phone, and being wrong in the cautious direction costs only an animation.
 */
export function capabilityTier(): 'none' | 'low' | 'full' {
	if (prefersReducedMotion() || prefersReducedData() || !hasWebGL()) {
		return 'none';
	}

	const memory = (navigator as Navigator & { deviceMemory?: number }).deviceMemory ?? 4;
	const cores = navigator.hardwareConcurrency ?? 4;
	const coarse = window.matchMedia('(pointer: coarse)').matches;

	if (memory <= 4 || cores <= 4) {
		return 'low';
	}

	return coarse ? 'low' : 'full';
}

/** Device pixel ratio, capped so a 3× phone screen does not render 9× the pixels. */
export function renderScale(tier: 'low' | 'full'): number {
	return Math.min(window.devicePixelRatio || 1, tier === 'full' ? 2 : 1.5);
}

/**
 * Run a callback the first time an element comes near the viewport.
 *
 * Returns a disposer so callers can cancel while a scene is still loading.
 */
export function whenVisible(
	element: Element,
	callback: () => void,
	rootMargin = '200px'
): () => void {
	if (!('IntersectionObserver' in window)) {
		callback();
		return () => undefined;
	}

	// Observer callbacks are not delivered while the document is hidden, so an
	// element that is already on screen would otherwise wait for a scroll that
	// may never come. Check the geometry directly first.
	const rect = element.getBoundingClientRect();
	const margin = parseInt(rootMargin, 10) || 0;

	if (rect.top < window.innerHeight + margin && rect.bottom > -margin && rect.width > 0) {
		callback();
		return () => undefined;
	}

	const observer = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				if (entry.isIntersecting) {
					observer.disconnect();
					callback();
					return;
				}
			}
		},
		{ rootMargin }
	);

	observer.observe(element);

	return () => observer.disconnect();
}

/**
 * Call back whenever an element enters or leaves the viewport.
 *
 * Used to pause render loops that are scrolled past — an animation nobody can
 * see is pure battery cost.
 */
export function onVisibilityChange(
	element: Element,
	callback: (visible: boolean) => void
): () => void {
	if (!('IntersectionObserver' in window)) {
		callback(true);
		return () => undefined;
	}

	const observer = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				callback(entry.isIntersecting);
			}
		},
		{ threshold: 0 }
	);

	observer.observe(element);

	return () => observer.disconnect();
}
