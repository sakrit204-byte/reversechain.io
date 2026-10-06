# Store pack

| File / folder | Contents |
|---|---|
| `listing-en.md` / `listing-es.md` / `listing-it.md` | App Store and Google Play copy (name, subtitle / short description, keywords, promo text, what's new, full description with the mandatory disclosure). Lengths checked against the store limits |
| `privacy-data-safety.md` | Apple App Privacy and Google Play Data safety answers, derived from the app source |
| `review-and-compliance.md` | Blockers, export compliance, age rating / IARC notes, financial-features declaration, country availability, reviewer notes template |
| `screenshots/ios/` | 6 × 1290×2796 (iPhone 6.7"/6.9" slot) framed screenshots with EN captions |
| `screenshots/android/` | 6 × 1080×1920 phone screenshots with EN captions |

The screenshots come from the mock web build with `EXPO_PUBLIC_API_MOCK=1` and `EXPO_PUBLIC_HIDE_MOCK_BANNER=1`. The
second flag is for store screenshots only: it is off by default, has no effect on a live build, and must never go into
`eas.json` or `.env`. All values shown are placeholders or "Pending", as in mock mode. To regenerate: put both flags in a
temporary `.env.local`, run `npx expo export --platform web --output-dir dist-store --clear` (`--clear` is required, because
Metro caches the inlined env), serve on :8098, and capture with Playwright at 430×932 @3x (iOS) and 412×780 @3x
(Android). Then compose on the brand background so the whole phone screen, including the tab bar, is visible. Restore
`.env.local` afterwards.

App icons and splash: `../assets/images/` (rendered from the SVG sources in `../assets/brand/`).
