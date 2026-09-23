/**
 * Pointer affordances.
 *
 * Two small things that make dense institutional pages feel responsive
 * without decorating them: a soft light that follows the cursor across a
 * card, and a lift on the card under the pointer.
 *
 * Both are driven by CSS custom properties, so the CSS owns every visual
 * decision and this module only reports where the pointer is. Both are
 * skipped entirely on touch, where a hover state is meaningless and would
 * stick after a tap.
 */

const CARD_SELECTOR = [
	'.rc-framework',
	'.rc-stage',
	'.rc-program-card',
	'.rc-metric',
	'.rc-trustbar__item',
	'.rc-certificate',
	'.rc-lot',
].join(',');

export function initPointerLight(): () => void {
	if (window.matchMedia('(pointer: coarse)').matches) {
		return () => undefined;
	}

	const cards = Array.from(document.querySelectorAll<HTMLElement>(CARD_SELECTOR));

	if (!cards.length) {
		return () => undefined;
	}

	let frame = 0;
	let pending: { el: HTMLElement; x: number; y: number } | null = null;

	// Writes are batched into one frame. Setting a custom property on every
	// pointermove forces style recalculation per event, which on a dense page
	// is a measurable cost for an effect nobody would miss.
	function flush(): void {
		frame = 0;

		if (!pending) return;

		const { el, x, y } = pending;
		el.style.setProperty('--rc-pointer-x', `${x}%`);
		el.style.setProperty('--rc-pointer-y', `${y}%`);
		pending = null;
	}

	function handleMove(event: PointerEvent): void {
		const el = event.currentTarget as HTMLElement;
		const rect = el.getBoundingClientRect();

		pending = {
			el,
			x: ((event.clientX - rect.left) / rect.width) * 100,
			y: ((event.clientY - rect.top) / rect.height) * 100,
		};

		if (!frame) {
			frame = requestAnimationFrame(flush);
		}
	}

	function handleEnter(event: PointerEvent): void {
		(event.currentTarget as HTMLElement).classList.add('is-lit');
	}

	function handleLeave(event: PointerEvent): void {
		const el = event.currentTarget as HTMLElement;
		el.classList.remove('is-lit');
		el.style.removeProperty('--rc-pointer-x');
		el.style.removeProperty('--rc-pointer-y');
	}

	cards.forEach((card) => {
		card.addEventListener('pointermove', handleMove, { passive: true });
		card.addEventListener('pointerenter', handleEnter, { passive: true });
		card.addEventListener('pointerleave', handleLeave, { passive: true });
	});

	return () => {
		if (frame) cancelAnimationFrame(frame);

		cards.forEach((card) => {
			card.removeEventListener('pointermove', handleMove);
			card.removeEventListener('pointerenter', handleEnter);
			card.removeEventListener('pointerleave', handleLeave);
			card.classList.remove('is-lit');
		});
	};
}

/**
 * Add a compact, sticky appearance to the header once the page scrolls.
 *
 * Purely a class toggle; the CSS decides what that means. Throttled to one
 * evaluation per frame so scrolling stays cheap.
 */
export function initHeaderState(): () => void {
	const header = document.querySelector<HTMLElement>('.wp-site-blocks > .wp-block-template-part:first-child header');

	if (!header) {
		return () => undefined;
	}

	header.classList.add('rc-header');

	let frame = 0;
	let condensed = false;

	function evaluate(): void {
		frame = 0;

		const shouldCondense = window.scrollY > 48;

		if (shouldCondense !== condensed) {
			condensed = shouldCondense;
			header!.classList.toggle('is-condensed', condensed);
		}
	}

	function handleScroll(): void {
		if (!frame) {
			frame = requestAnimationFrame(evaluate);
		}
	}

	window.addEventListener('scroll', handleScroll, { passive: true });
	evaluate();

	return () => {
		if (frame) cancelAnimationFrame(frame);
		window.removeEventListener('scroll', handleScroll);
	};
}
