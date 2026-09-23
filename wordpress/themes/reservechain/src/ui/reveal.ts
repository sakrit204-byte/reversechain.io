/**
 * Scroll-driven reveals, chain progression and figure counters.
 *
 * All three share one idea: the page should feel composed as you move through
 * it, without anything moving that the visitor did not cause. Nothing loops,
 * nothing autoplays, nothing shifts layout. Each effect is a one-way
 * transition from a resting state to a resting state, and every element is
 * fully readable before any of it runs — the markup is complete in the HTML,
 * and these only add a class.
 *
 * With reduced motion requested, the module is never imported at all and
 * `.rc-reveal` elements are visible by default in CSS.
 */

/** Whether an element currently overlaps the viewport. */
function isInViewport(el: Element): boolean {
	const rect = el.getBoundingClientRect();

	return rect.top < window.innerHeight && rect.bottom > 0 && rect.width > 0;
}

/**
 * Reveal anything already on screen, without waiting for an observer.
 *
 * IntersectionObserver callbacks are not delivered while a document is
 * hidden, so a page opened in a background tab would sit with its first
 * screenful invisible until the visitor scrolled. A direct geometry check on
 * init, repeated when the tab becomes visible, closes that gap.
 *
 * This is the safety property that matters most here: content must never stay
 * hidden because an enhancement did not run.
 */
function revealInViewport(): void {
	document.querySelectorAll<HTMLElement>('.rc-reveal:not(.is-revealed)').forEach((el) => {
		if (isInViewport(el)) {
			el.classList.add('is-revealed');
		}
	});
}

/** Reveal elements as they enter the viewport. */
export function initReveals(): () => void {
	const targets = document.querySelectorAll<HTMLElement>('.rc-reveal:not(.is-revealed)');

	if (!targets.length || !('IntersectionObserver' in window)) {
		targets.forEach((el) => el.classList.add('is-revealed'));
		return () => undefined;
	}

	// Anything already on screen is revealed immediately rather than waiting
	// for a callback that a hidden tab will never deliver.
	revealInViewport();

	const onVisible = (): void => {
		if (document.visibilityState === 'visible') {
			revealInViewport();
		}
	};

	document.addEventListener('visibilitychange', onVisible);

	const observer = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				if (!entry.isIntersecting) continue;

				const el = entry.target as HTMLElement;

				// Stagger siblings so a grid resolves as a sequence rather
				// than everything snapping at once. Capped, because a long
				// list should not take three seconds to finish appearing.
				const index = Number(el.dataset.rcRevealIndex ?? '0');
				el.style.setProperty('--rc-reveal-delay', `${Math.min(index * 70, 420)}ms`);

				el.classList.add('is-revealed');
				observer.unobserve(el);
			}
		},
		{ rootMargin: '0px 0px -12% 0px', threshold: 0.08 }
	);

	targets.forEach((el) => observer.observe(el));

	return () => {
		observer.disconnect();
		document.removeEventListener('visibilitychange', onVisible);
	};
}

/**
 * Light the chain-of-trust links in sequence as the section is read.
 *
 * The chain is the site's central claim — physical asset through verification,
 * custody, passport, tokenization, reconciliation, redemption — so it earns a
 * progression rather than appearing all at once. It remains a plain ordered
 * list for a screen reader, and every link is legible before the effect runs.
 */
export function initChain(): () => void {
	const chains = document.querySelectorAll<HTMLElement>('[data-rc-scene="chain"]');

	if (!chains.length || !('IntersectionObserver' in window)) {
		chains.forEach((chain) =>
			chain.querySelectorAll('.rc-chain__link').forEach((link) => link.classList.add('is-active'))
		);
		return () => undefined;
	}

	const observers: IntersectionObserver[] = [];

	chains.forEach((chain) => {
		const links = Array.from(chain.querySelectorAll<HTMLElement>('.rc-chain__link'));

		const observer = new IntersectionObserver(
			(entries) => {
				for (const entry of entries) {
					if (!entry.isIntersecting) continue;

					links.forEach((link, index) => {
						window.setTimeout(() => link.classList.add('is-active'), index * 110);
					});

					observer.disconnect();
				}
			},
			{ threshold: 0.35 }
		);

		observer.observe(chain);
		observers.push(observer);
	});

	return () => observers.forEach((o) => o.disconnect());
}

/**
 * Count registry figures up from zero when they scroll into view.
 *
 * Applied only to the registry counts, which are whole numbers describing how
 * many records exist. It is deliberately not applied to weights, purities or
 * reserve figures: animating a measurement makes it feel like a live ticker,
 * and the brief is emphatic that nothing may imply a status or a movement
 * that has not been established.
 */
export function initCounters(): () => void {
	const values = document.querySelectorAll<HTMLElement>('.rc-metric__value');

	if (!values.length || !('IntersectionObserver' in window)) {
		return () => undefined;
	}

	const observer = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				if (!entry.isIntersecting) continue;

				const el = entry.target as HTMLElement;
				const final = el.textContent?.trim() ?? '';
				const target = Number(final.replace(/[^0-9]/g, ''));

				observer.unobserve(el);

				// Only animate small whole numbers. A non-numeric value, or a
				// large one, is left exactly as rendered.
				if (!Number.isFinite(target) || target <= 0 || target > 10000) {
					continue;
				}

				// Reserve the final width so the layout cannot shift while
				// the digits change.
				el.style.minWidth = `${el.getBoundingClientRect().width}px`;
				el.style.display = 'inline-block';

				const duration = 700;
				const start = performance.now();

				const step = (now: number): void => {
					const progress = Math.min((now - start) / duration, 1);
					// easeOutCubic — fast start, settled finish
					const eased = 1 - Math.pow(1 - progress, 3);

					el.textContent = String(Math.round(target * eased));

					if (progress < 1) {
						requestAnimationFrame(step);
					} else {
						el.textContent = final;
					}
				};

				requestAnimationFrame(step);
			}
		},
		{ threshold: 0.5 }
	);

	values.forEach((el) => observer.observe(el));

	return () => observer.disconnect();
}

/**
 * Tag reveal targets with their index among siblings.
 *
 * Done in JS rather than PHP so patterns stay free of presentation indices
 * and an editor reordering blocks cannot break the stagger.
 */
export function indexRevealTargets(): void {
	const groups = new Map<Element, number>();

	document.querySelectorAll<HTMLElement>('.rc-reveal').forEach((el) => {
		const parent = el.parentElement;

		if (!parent) return;

		const next = (groups.get(parent) ?? 0) + 1;
		groups.set(parent, next);
		el.dataset.rcRevealIndex = String(next - 1);
	});
}
