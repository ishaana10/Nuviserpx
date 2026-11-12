<footer class="main-footer text-sm">
        <strong>Copyright © <?php echo date('Y') ?>. A property of <a href="https://nuvistechnologies.com.fj/ERPX" target="_blank">Nuvis Technologies</a>.</strong>
        All rights reserved.
        <div class="float-right d-none d-sm-inline-block">
          <b><?php echo $_settings->info('short_name') ?></b> <?php echo class_exists('AppVersion') ? AppVersion::get('full') : 'v1.0' ?>
        </div>
      </footer>
    </div>
    <!-- ./wrapper -->
<div id="libraries">
    <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
    <script>
      $.widget.bridge('uibutton', $.ui.button)
    </script>
    <!-- Bootstrap 4 -->
    <script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- ChartJS -->
    <script src="<?php echo base_url ?>plugins/chart.js/Chart.min.js"></script>
    <!-- Sparkline -->
    <script src="<?php echo base_url ?>plugins/sparklines/sparkline.js"></script>
    <!-- Select2 -->
    <script src="<?php echo base_url ?>plugins/select2/js/select2.full.min.js"></script>
    <!-- JQVMap -->
    <script src="<?php echo base_url ?>plugins/jqvmap/jquery.vmap.min.js"></script>
    <script src="<?php echo base_url ?>plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
    <!-- jQuery Knob Chart -->
    <script src="<?php echo base_url ?>plugins/jquery-knob/jquery.knob.min.js"></script>
    <!-- daterangepicker -->
    <script src="<?php echo base_url ?>plugins/moment/moment.min.js"></script>
    <script src="<?php echo base_url ?>plugins/daterangepicker/daterangepicker.js"></script>
    <!-- Tempusdominus Bootstrap 4 -->
    <script src="<?php echo base_url ?>plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
    <!-- Summernote -->
    <script src="<?php echo base_url ?>plugins/summernote/summernote-bs4.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
    <!-- DataTables Buttons: column picker, CSV/Excel export, print -->
    <script src="<?php echo base_url ?>plugins/jszip/jszip.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-buttons/js/buttons.html5.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-buttons/js/buttons.print.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-buttons/js/buttons.colVis.min.js"></script>
    <script>
    // Browser errors are reported to the server once each and shown under
    // Developer > Error Logs.
    (function(){
        var sent = 0, seen = {};
        function ship(p){
            if (sent >= 10 || seen[p.message]) return;
            seen[p.message] = true; sent++;
            try { $.post(_base_url_ + 'classes/Master.php?f=log_js_error', p); } catch(e){}
        }
        window.addEventListener('error', function(ev){
            var t = ev && ev.target;
            if (t && t !== window && t.tagName && (t.tagName === 'SCRIPT' || t.tagName === 'LINK')) {
                ship({ message: 'Failed to load ' + (t.src || t.href || 'resource'), source: location.pathname + location.search, line: 0, stack: '', page: location.href });
                return;
            }
            if (!ev || ev.message === undefined) return;
            ship({ message: String(ev.message || 'Script error'), source: String(ev.filename || ''), line: ev.lineno || 0,
                   stack: ev.error && ev.error.stack ? String(ev.error.stack).slice(0, 3000) : '', page: location.href });
        }, true);
        window.addEventListener('unhandledrejection', function(ev){
            var r = ev && ev.reason;
            ship({ message: 'Unhandled promise rejection: ' + ((r && r.message) ? r.message : String(r)).slice(0, 500),
                   source: location.pathname + location.search, line: 0,
                   stack: (r && r.stack) ? String(r.stack).slice(0, 3000) : '', page: location.href });
        });
    })();
    </script>
    <!-- Nuvis UI layer: table toolbar, form validation, modal behaviour -->
    <script src="<?php echo base_url ?>dist/js/nuvis-ui.js?v=<?php echo class_exists('AppVersion') ? urlencode(AppVersion::get('commit')) : '1.0' ?>"></script>
    <script src="<?php echo base_url ?>plugins/moment/moment.min.js"></script>
    <script src="<?php echo base_url ?>plugins/fullcalendar/main.js"></script>
    <!-- overlayScrollbars -->
    <!-- <script src="<?php echo base_url ?>plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script> -->
    <!-- AdminLTE App -->
    <script src="<?php echo base_url ?>dist/js/adminlte.js?v=<?php echo class_exists('AppVersion') ? urlencode(AppVersion::get('commit')) : '1.0' ?>"></script>
    <?php if(isset($__ui)): ?>
    <!-- Personal theme: side menu search/pins, Personalise panel -->
    <script src="<?php echo base_url ?>dist/js/nuvis-theme.js?v=<?php echo class_exists('AppVersion') ? urlencode(AppVersion::get('commit')) : '1.0' ?>"></script>
    <?php endif; ?>
  </div>   
    <?php require_once(__DIR__ . '/personalise.php'); ?>
    <div class="daterangepicker ltr show-ranges opensright">
      <div class="ranges">
        <ul>
          <li data-range-key="Today">Today</li>
          <li data-range-key="Yesterday">Yesterday</li>
          <li data-range-key="Last 7 Days">Last 7 Days</li>
          <li data-range-key="Last 30 Days">Last 30 Days</li>
          <li data-range-key="This Month">This Month</li>
          <li data-range-key="Last Month">Last Month</li>
          <li data-range-key="Custom Range">Custom Range</li>
        </ul>
      </div>
      <div class="drp-calendar left">
        <div class="calendar-table"></div>
        <div class="calendar-time" style="display: none;"></div>
      </div>
      <div class="drp-calendar right">
        <div class="calendar-table"></div>
        <div class="calendar-time" style="display: none;"></div>
      </div>
      <div class="drp-buttons"><span class="drp-selected"></span><button class="cancelBtn btn btn-sm btn-default" type="button">Cancel</button><button class="applyBtn btn btn-sm btn-primary" disabled="disabled" type="button">Apply</button> </div>
    </div>
    <div class="jqvmap-label" style="display: none; left: 1093.83px; top: 394.361px;">Idaho</div>
