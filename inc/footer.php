<footer class="main-footer text-sm">
        <strong>Copyright © <?php echo date('Y') ?>. A property of <a href="https://nuvistechnologies.com.fj" target="_blank">Nuvis Technologies</a>.</strong>
        All rights reserved.
        <div class="float-right d-none d-sm-inline-block">
          <b><?php echo $_settings->info('short_name') ?></b> <?php echo class_exists('AppVersion') ? AppVersion::get('full') : 'v1.0' ?>
        </div>
      </footer>
