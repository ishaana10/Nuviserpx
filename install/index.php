<?php
session_start();

$lock_file = __DIR__ . '/installed.lock';
$is_installed = file_exists($lock_file);

// Requirements Check
$php_version = phpversion();
$php_ok = version_compare($php_version, '8.2.0', '>=');
$mysqli_ok = extension_loaded('mysqli');
$json_ok = extension_loaded('json');
$mbstring_ok = extension_loaded('mbstring');
$env_writable = is_writable(__DIR__ . '/..') || (file_exists(__DIR__ . '/../.env') && is_writable(__DIR__ . '/../.env'));

$all_requirements_met = $php_ok && $mysqli_ok && $json_ok && $mbstring_ok && $env_writable;

// Auto detect app URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$app_url = $protocol . $host . preg_replace('/\/install\/?.*$/', '/', $uri);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NuvisERPX Setup & Guided Installation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .installer-card {
            background: #ffffff;
            color: #334155;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            max-width: 780px;
            width: 100%;
            overflow: hidden;
        }
        .installer-header {
            background: #0284c7;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            padding: 2rem;
            text-align: center;
        }
        .installer-header h2 {
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        .installer-body {
            padding: 2.5rem;
        }
        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0284c7;
            font-weight: bold;
            font-size: 0.875rem;
            margin-right: 0.5rem;
        }
        .section-title {
            font-size: 1.15rem;
            font-weight: 600;
            color: #0f172a;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 0.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
        }
        .req-table td {
            padding: 0.5rem 0.75rem;
            vertical-align: middle;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.875rem;
            color: #475569;
        }
        .btn-install {
            background: #0284c7;
            border: none;
            padding: 0.8rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-install:hover {
            background: #0369a1;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<div class="installer-card">
    <div class="installer-header">
        <i class="fa-solid fa-server fa-3x mb-3"></i>
        <h2>NuvisERPX Installation Wizard</h2>
        <p class="mb-0 text-white-50">Enterprise Resource Planning & Multi-Tenant Accounting Platform</p>
    </div>

    <div class="installer-body">
        <?php if ($is_installed): ?>
            <div class="alert alert-warning border-0 shadow-sm p-4 text-center">
                <i class="fa-solid fa-triangle-exclamation fa-3x text-warning mb-3"></i>
                <h4 class="alert-heading font-weight-bold">System Already Installed</h4>
                <p class="mb-3">NuvisERPX has already been successfully installed and locked.</p>
                <p class="text-muted small mb-4">If you wish to re-run this setup wizard, please delete the file <code>install/installed.lock</code> on your server.</p>
                <a href="../admin/login.php" class="btn btn-primary px-4 py-2 font-weight-bold">Proceed to Admin Login <i class="fa-solid fa-arrow-right ml-1"></i></a>
            </div>
        <?php else: ?>

            <form id="install-form">
                <!-- Step 1: System Requirements -->
                <div class="mb-4">
                    <div class="section-title">
                        <span class="step-badge">1</span> Server Prerequisites Check
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered text-sm req-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Prerequisite Requirement</th>
                                    <th>Required</th>
                                    <th>Current Environment</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>PHP Version</td>
                                    <td>>= 8.2.0</td>
                                    <td><?php echo $php_version; ?></td>
                                    <td class="text-center">
                                        <?php if ($php_ok): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Pass</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Fail</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>MySQLi Extension</td>
                                    <td>Enabled</td>
                                    <td><?php echo $mysqli_ok ? 'Installed' : 'Missing'; ?></td>
                                    <td class="text-center">
                                        <?php if ($mysqli_ok): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Pass</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Fail</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>JSON Extension</td>
                                    <td>Enabled</td>
                                    <td><?php echo $json_ok ? 'Installed' : 'Missing'; ?></td>
                                    <td class="text-center">
                                        <?php if ($json_ok): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Pass</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Fail</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Environment Writable (.env)</td>
                                    <td>Writable</td>
                                    <td><?php echo $env_writable ? 'Writable' : 'Protected'; ?></td>
                                    <td class="text-center">
                                        <?php if ($env_writable): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Pass</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Fail</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!$all_requirements_met): ?>
                    <div class="alert alert-danger border-0 mb-4">
                        <i class="fa-solid fa-circle-exclamation me-2"></i> Your server does not meet all the minimum system requirements. Please resolve the failing checks before continuing.
                    </div>
                <?php endif; ?>

                <!-- Step 2: Database Settings -->
                <div class="mb-4">
                    <div class="section-title">
                        <span class="step-badge">2</span> MySQL Database Configuration
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Database Host</label>
                            <input type="text" name="db_server" class="form-control" value="localhost" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Database Name</label>
                            <input type="text" name="db_name" class="form-control" value="nuviserpx" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Database Username</label>
                            <input type="text" name="db_username" class="form-control" value="root" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Database Password</label>
                            <input type="password" name="db_password" class="form-control" placeholder="Leave empty if none">
                        </div>
                    </div>
                </div>

                <!-- Step 3: Application Settings -->
                <div class="mb-4">
                    <div class="section-title">
                        <span class="step-badge">3</span> Application & System Information
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">System Title</label>
                            <input type="text" name="app_name" class="form-control" value="NuvisERPX Enterprise ERP" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Short Name / Code</label>
                            <input type="text" name="short_name" class="form-control" value="NuvisERPX" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Application Base URL</label>
                            <input type="url" name="app_url" class="form-control" value="<?php echo htmlspecialchars($app_url); ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Super Admin Credentials -->
                <div class="mb-4">
                    <div class="section-title">
                        <span class="step-badge">4</span> Developer & Super Administrator Setup
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="admin_firstname" class="form-control" value="Super" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="admin_lastname" class="form-control" value="Admin" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administrator Username</label>
                            <input type="text" name="admin_username" class="form-control" placeholder="e.g. admin" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administrator Password</label>
                            <input type="password" name="admin_password" class="form-control" placeholder="Enter secure password" minlength="6" required>
                        </div>
                    </div>
                </div>

                <div id="install-alert" class="d-none alert mb-4"></div>

                <div class="d-grid mt-4">
                    <button type="submit" id="btn-submit" class="btn btn-primary btn-install text-white" <?php echo !$all_requirements_met ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-bolt me-2"></i> Run Automated Installation & Build Schema
                    </button>
                </div>
            </form>

        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script>
$(document).ready(function(){
    $('#install-form').on('submit', function(e){
        e.preventDefault();
        var alertBox = $('#install-alert');
        var btn = $('#btn-submit');

        alertBox.removeClass('d-none alert-danger alert-success').addClass('alert-info').html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Installing database schema and initializing super admin account... Please wait.');
        btn.prop('disabled', true);

        $.ajax({
            url: 'process.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            error: function(xhr, status, err){
                btn.prop('disabled', false);
                alertBox.removeClass('alert-info').addClass('alert-danger').html('<i class="fa-solid fa-triangle-exclamation me-2"></i> Installation error: ' + (xhr.responseText || err));
            },
            success: function(resp){
                if(resp.status === 'success'){
                    alertBox.removeClass('alert-info').addClass('alert-success').html('<i class="fa-solid fa-circle-check me-2"></i> ' + resp.message + ' Redirecting to admin login...');
                    setTimeout(function(){
                        window.location.href = resp.redirect || '../admin/login.php';
                    }, 2000);
                } else {
                    btn.prop('disabled', false);
                    alertBox.removeClass('alert-info').addClass('alert-danger').html('<i class="fa-solid fa-circle-xmark me-2"></i> ' + (resp.message || 'Installation failed.'));
                }
            }
        });
    });
});
</script>
</body>
</html>
