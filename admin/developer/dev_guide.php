<?php
// Developer Guide: how this codebase is put together and how to extend it
// safely. Super-admin only (the whole developer/ segment is SUPER_ONLY).
$dg = [
 'architecture' => ['Architecture Overview', 'fa-sitemap', [
   ['Entry points', [
     'admin/index.php is the router: ?page=x/y includes admin/x/y.php (or x/index.php). It draws breadcrumbs, enforces page permissions centrally and buffers output (ob_start) so exports can clear the layout.',
     'classes/Master.php is the AJAX action hub: a whitelist dispatch ($allowed_actions) that echoes $Master->{$action}(). Every action is one-line delegation into a service.',
     'Standalone AJAX endpoints live in classes/ (Users.php, Login.php ...) and require ../config.php themselves.',
     'config.php -> initialize.php defines base_url, loads the autoloader, DB connection and $_settings (SystemSettings).',
   ]],
   ['Source layout', [
     'src/Services/* - business logic. Constructor convention: (mysqli $conn, SystemSettings $settings); resolved via Container::getInstance() or constructed directly by pages.',
     'src/Support/* - cross-cutting: Permissions (the registry), MenuFilter, ErrorLog, AuditDiff, AppVersion, HelpContent.',
     'src/Database/* - MigrationRunner, LegacySchemaCatchup (44 runtime check_*_migrations), SqlMode.',
     'dist/js/nuvis-ui.js + dist/css/nuvis-ui.css|nuvis-theme.css - the shared UI layer (see UI section).',
   ]],
 ]],
 'security' => ['Request Flow & Security', 'fa-shield-alt', [
   ['Every request', [
     'Auth: $_settings->require_login(); session carries userdata (id, type, tenant).',
     'CSRF: a meta tag in admin/inc/header.php feeds a global $.ajaxSetup X-CSRF-TOKEN header; server side calls require_csrf(). New endpoints must call it.',
     'Permissions: Permissions::forPage($page) gates pages centrally in admin/index.php; ACTION_MAP gates Master actions; can_access() checks role perms AND the tenant module (module off = blocked for everyone, super admin included).',
     'SQL: user input goes through prepared statements or real_escape_string + casts. Backtick reserved words (last_value burned us once).',
   ]],
   ['Conventions that keep it safe', [
     'Never widen $allowed_actions without an ACTION_MAP entry.',
     'Super-only surface: map the page/action to Permissions::SUPER_ONLY (the whole developer/ segment already is).',
     'Writes are audited: $_settings->log_audit(action, module, details); AuditDiff builds before/after strings.',
     'Secrets: .env is git-ignored; never echo SMTP or DB credentials into pages.',
   ]],
 ]],
 'tenancy' => ['Multi-Tenancy & Modules', 'fa-building', [
   ['Tenancy', [
     'Every business table carries tenant_id; services must scope every query by active_tenant_id().',
     'Super admins switch company via the topbar picker; everyone else is pinned to their tenant.',
   ]],
   ['Tenant modules', [
     'Permissions::TENANT_MODULES is the single source of truth: key, label, perms it governs, and requires (dependency graph).',
     'sanitizeTenantModules() stores assignments dependency-closed; expandTenantModules()/moduleDependents() compute closures.',
     'To add a module: add the TENANT_MODULES entry, govern its perms, write a migration appending the new key to tenants with a recorded assignment (see 037), and add a HelpContent topic. ModuleManager mirrors the registry automatically.',
   ]],
 ]],
 'database' => ['Database & Migrations', 'fa-database', [
   ['Changing schema', [
     'Add database/migrations/NNN_name.sql (next number). MigrationRunner executes new files once, handles ADD COLUMN IF NOT EXISTS on MariaDB via parseConditionalAddColumn.',
     'Installs that skip migrations are caught by src/Database/LegacySchemaCatchup.php - mirror critical columns there (cheap SHOW COLUMNS guard).',
     'MariaDB notes: LAST_VALUE is reserved - backtick it; utf8mb4; session time zone is set from PHP date("P") on connect (Pacific/Fiji).',
   ]],
   ['Posting & sequences', [
     'SequenceService issues document numbers from document_sequences using UPDATE ... last_value = LAST_INSERT_ID(last_value + 1); it self-heals the table and falls back to plain queries if prepare fails.',
     'LedgerService/JournalService post double-entry journals; ChartProvisioner seeds/repairs the chart (fill() inserts missing standard accounts, reactivates, and retypes legacy rows); AccountDefaultsService resolves the receivable/revenue/cash roles.',
   ]],
 ]],
 'feature' => ['Adding a Feature - Checklist', 'fa-plus-circle', [
   ['The 8 steps', [
     '1. Page: admin/<area>/<page>.php (list page? follow the list-UI conventions - breadcrumb, summary cards, filter pills come from nuvis-ui.js).',
     '2. Permission: add the page to Permissions::PAGE_MAP (and a perm module in MODULE_GROUPS if it is a new area).',
     '3. Menu: link it in the right admin/inc/menus/NN_*.php file.',
     '4. Actions: Master method delegating to a service + ACTION_MAP entry + $allowed_actions.',
     '5. Service: src/Services/<Name>Service.php (mysqli + SystemSettings constructor).',
     '6. Schema: migration + LegacySchemaCatchup mirror if critical.',
     '7. Help: add/extend the topic in src/Support/HelpContent.php - tests fail if a nav page has no help topic.',
     '8. Test: tests/test_<feature>.php with the check() pattern; run the whole suite (see Testing).',
   ]],
 ]],
 'ui' => ['UI Layer', 'fa-palette', [
   ['nuvis-ui.js', [
     'Wraps DataTables globally at load: toolbar + kebab menus, summary cards (buildSummary), filter pills (buildFilters), header/data alignment (alignColumns), and a guard that strips colspan empty-state rows (prevents _DT_CellIndex crashes).',
     'Dark mode and sidebar styling live in nuvis-theme.css; list-page look in nuvis-ui.css.',
     'JS errors on admin pages are reported to logs/js_errors.log by the footer reporter and surface in Developer > Error Logs.',
   ]],
 ]],
 'email' => ['Email', 'fa-envelope', [
   ['How mail goes out', [
     'classes/Emailer.php - socket SMTP using System Info settings (host/port/encryption/user/pass), falling back to PHP mail(). Headers include Date, Message-ID, Reply-To.',
     'Templates are static methods (send_email_verification, send_invoice_email, send_payslip_email ...) wrapped by wrap_template(). Emailer is required lazily - require_once it at the call site.',
     'User email verification: token random_bytes(32), stored sha256-hashed with 48h expiry; public endpoint verify_email.php.',
     'Deliverability: SPF + DKIM + DMARC on the sending domain (cPanel > Email Deliverability); check cPanel Track Delivery when a recipient claims non-receipt.',
   ]],
 ]],
 'diagnostics' => ['Diagnostics & Logs', 'fa-stethoscope', [
   ['Where to look', [
     'Developer > Error Logs: grouped PHP (logs/php_errors.log) and JS (logs/js_errors.log) errors with counts; logs are web-blocked and rotate at 8 MB.',
     'Settings > Audit Logs: who did what, per tenant, with before/after diffs.',
     'Dashboard (Developer) summarises environment health; VMS Inspector and Portal Preview help debug customer-facing surfaces.',
   ]],
 ]],
 'testing' => ['Testing & Deployment', 'fa-rocket', [
   ['Tests', [
     'tests/ holds standalone PHP scripts (no framework): each defines check($name, $cond) and exits non-zero on failure.',
     'Run everything: for t in tests/test_*.php; do php $t || echo "FAIL $t"; done',
     'Many tests pin structure (file contents, counts) on purpose - when you move code, repoint the tests in the same PR.',
   ]],
   ['Deployment', [
     'Flow: branch off main -> PR -> merge -> git pull on the server. No composer step on deploy; vendor/ is committed.',
     'Migrations run automatically; LegacySchemaCatchup covers stragglers at runtime.',
     'Production: PHP 8.2 + MariaDB. App URL comes from .env (APP_URL); version string from src/Support/AppVersion.php shows on the login page.',
   ]],
 ]],
];
?>
<div class="row" id="dev-guide">
  <div class="col-md-3 d-print-none">
    <div class="card card-outline rounded-0 shadow-sm position-sticky" style="top:70px;">
      <div class="card-body p-2">
        <input type="text" id="dev-guide-search" class="form-control form-control-sm rounded-0 mb-2" placeholder="Search the dev guide...">
        <nav class="nav flex-column small">
          <?php foreach($dg as $slug => [$title, $icon, $secs]): ?>
            <a class="nav-link py-1 px-2" href="#dev-<?php echo $slug ?>"><i class="fas <?php echo $icon ?> fa-fw mr-1 text-muted"></i><?php echo htmlspecialchars($title) ?></a>
          <?php endforeach; ?>
        </nav>
        <button class="btn btn-sm btn-outline-secondary btn-block rounded-0 mt-2" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button>
      </div>
    </div>
  </div>
  <div class="col-md-9">
    <div class="alert alert-warning rounded-0 shadow-sm"><i class="fas fa-code mr-1"></i><b>Developer tier only.</b> This guide describes the internals of Nuvis ERPX for whoever maintains it. End-user help lives in the <a href="<?php echo base_url ?>admin/?page=help">User Guide</a>.</div>
    <?php foreach($dg as $slug => [$title, $icon, $secs]): ?>
    <div class="card card-outline rounded-0 shadow-sm dev-topic" id="dev-<?php echo $slug ?>">
      <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas <?php echo $icon ?> mr-2 text-warning"></i><?php echo htmlspecialchars($title) ?></h3></div>
      <div class="card-body">
        <?php foreach($secs as [$h, $items]): ?>
          <h5 class="mt-2 mb-2"><?php echo htmlspecialchars($h) ?></h5>
          <ul class="pl-3 mb-3">
            <?php foreach($items as $it): ?><li class="mb-1"><?php echo htmlspecialchars($it) ?></li><?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
  $(function(){
    $('#dev-guide-search').on('input', function(){
      var q = $(this).val().toLowerCase().trim();
      $('.dev-topic').each(function(){ $(this).toggle(q === '' || $(this).text().toLowerCase().indexOf(q) !== -1); });
    });
    if(location.hash){ var el = $(location.hash); if(el.length){ $('html,body').animate({scrollTop: el.offset().top - 70}, 200); } }
  });
</script>
