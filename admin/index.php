<?php
require_once('../config.php');
// Buffer the layout: pages that stream a download (CSV exports) can then
// clear the buffer and set their own headers instead of warning
// "headers already sent" and corrupting the file.
ob_start();
?>
<?php require_once('inc/ui_prefs.php'); ?>
 <!DOCTYPE html>
<html lang="en" class="" style="height: auto;" <?php echo \App\Services\UiPrefsService::htmlAttrs($__ui) ?>>
<?php require_once('inc/header.php') ?>
  <body class="sidebar-mini layout-fixed control-sidebar-slide-open layout-navbar-fixed sidebar-mini-md sidebar-mini-xs<?php echo !empty($__ui['collapsed']) ? ' sidebar-collapse' : '' ?>" data-new-gr-c-s-check-loaded="14.991.0" data-gr-ext-installed="" style="height: auto;">
    <div class="wrapper">
     <?php require_once('inc/topBarNav.php') ?>
     <?php require_once('inc/navigation.php') ?>
     <?php if($_settings->chk_flashdata('success')): ?>
      <script>
        alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
      </script>
      <?php endif;?>    
     <?php $page = isset($_GET['page']) ? $_GET['page'] : 'home';  ?>
      <!-- Content Wrapper. Contains page content -->
      <div class="content-wrapper pt-3" style="min-height: 567.854px;">
     
        <!-- Main content -->
        <section class="content">
          <div class="container-fluid">
            <?php
              // Page heading: breadcrumb + title derived from the current page.
              if($page !== 'home'){
                $nvNames = ['crm' => 'CRM', 'hr' => 'HR', 'api_keys' => 'API Keys'];
                $nvSegs = array_values(array_filter(explode('/', $page), fn($s) => $s !== '' && $s !== 'index'));
                if($nvSegs){
                  $nvLast = count($nvSegs) - 1;
                  echo '<div class="nv-page-head"><nav class="nv-breadcrumb" aria-label="breadcrumb"><a href="./">Home</a>';
                  foreach($nvSegs as $nvIx => $nvSeg){
                    $nvLabel = $nvNames[$nvSeg] ?? ucwords(str_replace(['_', '-'], ' ', $nvSeg));
                    echo '<span class="nv-crumb-sep">/</span>';
                    if($nvIx < $nvLast){
                      echo '<a href="./?page=' . htmlspecialchars(implode('/', array_slice($nvSegs, 0, $nvIx + 1))) . '">' . htmlspecialchars($nvLabel) . '</a>';
                    } else {
                      echo '<span class="nv-crumb-current">' . htmlspecialchars($nvLabel) . '</span>';
                      echo '</nav><h1 class="nv-page-title">' . htmlspecialchars($nvLabel) . '</h1></div>';
                    }
                  }
                }
              }
              $view_file = $page . '.php';
              if(is_dir($page)){
                $view_file = $page . '/index.php';
              }

              // Central page-level authorisation. The required permission comes
              // from the registry; super administrators (developer tier) bypass
              // all checks. Unlisted pages remain open to any logged-in admin.
              $page_perm = \App\Support\Permissions::forPage($page);
              $page_allowed = true;
              if($page_perm === \App\Support\Permissions::SUPER_ONLY){
                $page_allowed = $_settings->is_super_admin();
              } elseif(is_string($page_perm)){
                $page_allowed = $_settings->can_access($page_perm);
              }

              if(!$page_allowed){
                echo '<div class="alert alert-danger m-3 shadow-sm"><i class="fas fa-ban mr-2"></i>'
                   . 'You don\'t have permission to access this page. Contact your administrator if you believe this is a mistake.'
                   . '</div>';
              } elseif(file_exists($view_file)){
                echo \App\Support\Permissions::hideControlsCss($page, $_settings);
                include $view_file;
              } else {
                include '404.html';
              }
            ?>
          </div>
        </section>
        <!-- /.content -->
  <div class="modal fade" id="confirm_modal" role='dialog'>
    <div class="modal-dialog modal-md modal-dialog-centered rounded-0" role="document">
      <div class="modal-content">
        <div class="modal-header">
        <h5 class="modal-title">Confirmation</h5>
      </div>
      <div class="modal-body">
        <div id="delete_content"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary btn-flat" id='confirm' onclick="">Continue</button>
        <button type="button" class="btn btn-secondary btn-flat" data-dismiss="modal">Close</button>
      </div>
      </div>
    </div>
  </div>
  <div class="modal fade rounded-0" id="uni_modal" role='dialog'>
    <div class="modal-dialog modal-md modal-dialog-centered rounded-0" role="document">
      <div class="modal-content rounded-0">
        <div class="modal-header rounded-0">
        <h5 class="modal-title"></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body rounded-0">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary btn-flat" id='submit' onclick="window.NuvisUI ? NuvisUI.submitModal('#uni_modal') : $('#uni_modal form').submit()"><i class="fas fa-check mr-1"></i> Save</button>
        <button type="button" class="btn btn-secondary btn-flat" data-dismiss="modal">Cancel</button>
      </div>
      </div>
    </div>
  </div>
  <div class="modal fade rounded-0" id="uni_modal_right" role='dialog'>
    <div class="modal-dialog modal-full-height  modal-md rounded-0" role="document">
      <div class="modal-content">
        <div class="modal-header">
        <h5 class="modal-title"></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span class="fa fa-arrow-right"></span>
        </button>
      </div>
      <div class="modal-body">
      </div>
      </div>
    </div>
  </div>
  <div class="modal fade rounded-0" id="viewer_modal" role='dialog'>
    <div class="modal-dialog modal-md rounded-0" role="document">
      <div class="modal-content">
              <button type="button" class="btn-close" data-dismiss="modal"><span class="fa fa-times"></span></button>
              <img src="" alt="">
      </div>
    </div>
  </div>
      </div>
      <!-- /.content-wrapper -->
      <?php require_once('inc/footer.php') ?>
  </body>
</html>
