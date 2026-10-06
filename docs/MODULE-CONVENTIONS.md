# ReserveChain Core — module conventions (for contributors)

Plugin: `wordpress/plugins/reservechain-core/`. PHP 8.1+, WordPress 7.x, MySQL 8. Local stack: `docker compose up -d`
(site http://localhost:8088, admin `rcadmin` / `RC-Admin-Dev-2026!`). Reset + seed: `bash scripts/dev-reset.sh`.
PHP lint: `bash scripts/lint-php.sh`. WP-CLI: `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli wp …` (Git Bash).
Crawl check: see `scripts/` (screenshot.cjs, motion-test.cjs use playwright-core + system Chrome).

## Module skeleton
* One class per file: `includes/class-<name>.php` → `namespace RC; final class <Name>` (autoloaded; underscores in
  class names map to hyphens). `Plugin::boot()` already calls `Por`, `Redemption`, `Web3`, `Portal`, `Intake`,
  `Monitoring` if the class exists: implement `public static function init(): void` and optionally
  `public static function install(): void` (dbDelta your own tables; runs once per version).
* Register your own REST routes inside your class on `rest_api_init` using namespace `Rest::NS` (`rc/v1`) and the
  gates `array( Rest::class, 'public_gate' )` / `array( Rest::class, 'bearer_gate' )` (bearer sets the current user).
* Register your own admin pages under the ReserveChain menu: `add_submenu_page( 'reservechain', … )` on `admin_menu`.
* Shortcodes: `add_shortcode( 'rc_…', … )` in your `init()`. Front-end JS/CSS: put files in `assets/` of the plugin
  and enqueue only when your shortcode renders. Theme styles live in `wordpress/themes/reservechain/assets/css/main.css`
  — reuse existing classes (`rc-section`, `rc-wrap`, `rc-form`, `rc-field`, `rc-btn`, `rc-btn--primary`, `rc-table`,
  `rc-kv`, `rc-ftable`, `rc-alert`, `rc-locked`, `rc-pill` via `Shortcodes::pill( $status, $label )`, …). If you need
  new CSS, add a clearly delimited block at the END of main.css with your module name in a comment.

## Platform services to reuse (never re-implement)
* `Audit_Log::record( $action, $object_type, $object_id, $summary, array $data )` — every state change, approval,
  export, upload and failure. Never store secrets/PII in plain text in audit data (hash emails, addresses).
* `Settings::module_on( 'redemption' )` etc. — gated modules MUST refuse to operate (API 403 with a clear reason,
  UI shows `[rc_module]`-style locked panel) unless their flag is on. Module keys are in `Settings::GATED_MODULES`.
  `Settings::get( 'network' )` → chain_id, explorer, token_address, anchor_address.
* `Workflow` four-eyes pattern: approvals must be by a different user than the requester/submitter; bind approvals
  to a fingerprint of what was approved.
* `Compliance::eligibility( $user_id )` / `Compliance::jurisdiction( $cc )`; `Notifications::push( $uid, $title, $body, $cat )`.
* `Security::rate_limit( $bucket, $max, $window )`, upload checks via WordPress media (`wp_handle_upload` triggers
  `Security::upload_check`); SHA-256 every uploaded evidence file (`hash_file`).
* `Registry`, `Schema`, `Passport` for registry records (record numbers, relations, field values).
* Capabilities: `rc_manage_registry`, `rc_approve`, `rc_publish`, `rc_manage_compliance`, `rc_view_audit`,
  `rc_manage_settings`, `rc_authorize_modules`, `rc_manage_waitlist`. Add new caps to roles in your `install()`.
* i18n: wrap every UI string in `__( '…', 'reservechain' )`; list new strings in your final report so ES/IT can be added.

## Non-negotiable rules
No invented data (prices, supply, addresses, partners). Mainnet never. Testnet chain IDs only (Sepolia 11155111,
Amoy 80002, local 31337). No funds collected pre-authorization. Every module defaults OFF. Do not run git.
Do not edit files owned by other modules; shared files (class-plugin.php, class-schema.php, class-rest.php,
class-settings.php) are edited only by the lead — request changes in your report instead.
