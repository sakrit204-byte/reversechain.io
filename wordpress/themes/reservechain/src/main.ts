/**
 * ReserveChain theme entry point.
 *
 * Order of business, and why:
 *
 *   1. Everything the page needs to be readable is already in the HTML. This
 *      file adds nothing structural, so a failure here degrades to a fully
 *      functional site rather than a blank one.
 *   2. Reduced motion, reduced data and missing WebGL all short-circuit
 *      before any of it runs.
 *   3. Three.js is imported dynamically, and only once a scene container is
 *      near the viewport on a device that can handle it. It is never on the
 *      critical path — the brief forbids decorative animation that degrades
 *      loading or mobile performance, and a 3D bundle blocking first paint
 *      would be exactly that.
 */

import { capabilityTier, prefersReducedMotion, whenVisible } from './lib/capability';
import { initHeaderState, initPointerLight } from './ui/pointer';

type Disposer = () => void;

const disposers: Disposer[] = [];

function track(disposer: Disposer): void {
	disposers.push(disposer);
}

/**
 * Mark the document so CSS can distinguish "enhancements are running" from
 * "no JavaScript". Reveal animations start hidden only when this is present,
 * so a visitor without JS never sees a permanently invisible section.
 */
function markEnhanced(tier: string): void {
	const root = document.documentElement;
	root.classList.add('rc-enhanced');
	root.dataset.rcTier = tier;
}

async function boot(): Promise<void> {
	const tier = capabilityTier();

	markEnhanced(tier);

	// Pointer affordances and the header state are cheap, do not move layout
	// and carry no motion of their own, so they run at every tier.
	track(initPointerLight());
	track(initHeaderState());

	if (prefersReducedMotion()) {
		// Scroll reveals are motion. Respect the preference and leave
		// everything in its resting, visible state.
		return;
	}

	const { indexRevealTargets, initReveals, initChain, initCounters } = await import('./ui/reveal');

	indexRevealTargets();
	track(initReveals());
	track(initChain());
	track(initCounters());

	if (tier === 'none') {
		return;
	}

	const heroContainer = document.querySelector<HTMLElement>('[data-rc-scene="hero"]');

	if (!heroContainer) {
		return;
	}

	track(
		whenVisible(heroContainer, () => {
			void mountHero(heroContainer, tier);
		})
	);
}

async function mountHero(container: HTMLElement, tier: 'low' | 'full'): Promise<void> {
	try {
		const { createHeroScene } = await import('./scenes/hero');
		const handle = createHeroScene(container, { tier });

		track(() => handle.destroy());
	} catch (error) {
		// A scene that fails to load is not an error worth showing anyone: the
		// static gradient behind it is a complete fallback. Logged so it is
		// visible in monitoring rather than silently swallowed.
		console.warn('[ReserveChain] Hero scene unavailable:', error);
		container.setAttribute('data-rc-scene-state', 'unavailable');
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', () => void boot(), { once: true });
} else {
	void boot();
}

// Release GPU and observer resources on navigation away, which matters for
// back/forward cache eligibility.
window.addEventListener('pagehide', () => {
	while (disposers.length) {
		try {
			disposers.pop()?.();
		} catch {
			/* teardown must never throw during unload */
		}
	}
});
