function start_loader() {
    $('body').append('<div id="preloader"><div class="loader-holder"><div></div><div></div><div></div><div></div>');
}

function end_loader() {
    $('#preloader').fadeOut('fast', function() {
        $('#preloader').remove();
    });
}

window.alert_toast = function($msg = 'TEST', $bg = 'success', $pos = '') {
    var Toast = Swal.mixin({
        toast: true,
        position: $pos || 'top',
        showConfirmButton: false,
        timer: 3500
    });
    Toast.fire({
        icon: $bg,
        title: $msg
    });
};

window.viewer_modal = function($src = ''){
    start_loader();
    var t = $src.split('.');
    t = t[1];
    if(t =='mp4'){
      var view = $("<video src='"+$src+"' controls autoplay></video>");
    }else{
      var view = $("<img src='"+$src+"' />");
    }
    $('#viewer_modal .modal-content video,#viewer_modal .modal-content img').remove();
    $('#viewer_modal .modal-content').append(view);
    $('#viewer_modal').modal({
            show:true,
            backdrop:'static',
            keyboard:false,
            focus:true
          });
    end_loader();
};

window.uni_modal = function($title = '' , $url='',$size=""){
    start_loader();
    var targetUrl = $url;
    if(typeof _base_url_ !== 'undefined' && !targetUrl.startsWith('http://') && !targetUrl.startsWith('https://') && !targetUrl.startsWith('/')){
        if(!targetUrl.startsWith('admin/')){
            targetUrl = _base_url_ + 'admin/' + targetUrl;
        } else {
            targetUrl = _base_url_ + targetUrl;
        }
    }
    $.ajax({
        url: targetUrl,
        error: err => {
            console.log(err);
            alert("An error occurred loading the modal form.");
            end_loader();
        },
        success: function(resp){
            if(resp){
                $('#uni_modal .modal-title').html($title);
                $('#uni_modal .modal-body').html(resp);
                var dialogClass = "modal-dialog modal-dialog-centered ";
                if($size === 'mid-large' || $size === 'large' || $size === 'modal-lg'){
                    dialogClass += "modal-lg";
                } else if($size === 'extra-large' || $size === 'modal-xl'){
                    dialogClass += "modal-xl";
                } else if($size === 'small' || $size === 'modal-sm'){
                    dialogClass += "modal-sm";
                } else if($size != ''){
                    dialogClass += $size;
                } else {
                    dialogClass += "modal-md";
                }
                $('#uni_modal .modal-dialog').removeAttr("class").addClass(dialogClass);
                $('#uni_modal').modal({
                  show: true,
                  backdrop: 'static',
                  keyboard: false,
                  focus: true
                });
                end_loader();
            }
        }
    });
};

window._conf = function($msg='',$func='',$params = []){
   $('#confirm_modal #confirm').attr('onclick',$func+"("+$params.join(',')+")");
   $('#confirm_modal .modal-body').html($msg);
   $('#confirm_modal').modal('show');
};

$(document).ready(function() {
    // Login
    $('#login-frm').submit(function(e) {
        e.preventDefault()
        start_loader()
        if ($('.err_msg').length > 0)
            $('.err_msg').remove()
        $.ajax({
            url: _base_url_ + 'classes/Login.php?f=login',
            method: 'POST',
            data: $(this).serialize(),
            error: err => {
                console.log(err)

            },
            success: function(resp) {
                if (resp) {
                    resp = JSON.parse(resp)
                    if (resp.status == 'success') {
                        location.replace(_base_url_ + 'admin');
                    } else if (resp.status == 'incorrect') {
                        var _frm = $('#login-frm')
                        var _msg = "<div class='alert alert-danger text-white err_msg'><i class='fa fa-exclamation-triangle'></i> Incorrect username or password</div>"
                        _frm.prepend(_msg)
                        _frm.find('input').addClass('is-invalid')
                        $('[name="username"]').focus()
                    } else if (resp.status == 'notverified') {
                        var _frm = $('#login-frm')
                        var _msg = "<div class='alert alert-danger text-white err_msg'><i class='fa fa-exclamation-triangle'></i> Your Account is not yet verified.</div>"
                        _frm.prepend(_msg)
                        _frm.find('input').addClass('is-invalid')
                        $('[name="username"]').focus()
                    }
                    end_loader()
                }
            }
        })
    })
    $('#clogin-frm').submit(function(e) {
        e.preventDefault()
        start_loader()
        if ($('.err_msg').length > 0)
            $('.err_msg').remove()
        $.ajax({
            url: _base_url_ + 'classes/Login.php?f=clogin',
            method: 'POST',
            data: $(this).serialize(),
            error: err => {
                console.log(err)
                alert_toast("An error occured", 'danger')
                end_loader()
            },
            success: function(resp) {
                if (resp) {
                    resp = JSON.parse(resp)
                    if (resp.status == 'success') {
                        location.replace(_base_url_);
                    } else if (resp.status == 'incorrect') {
                        var _frm = $('#clogin-frm')
                        var _msg = "<div class='alert alert-danger text-white err_msg'><i class='fa fa-exclamation-triangle'></i> Incorrect code or password</div>"
                        _frm.prepend(_msg)
                        _frm.find('input').addClass('is-invalid')
                        $('[name="username"]').focus()
                    }
                }
                end_loader()
            }
        })
    })

    //user login
    $('#slogin-frm').submit(function(e) {
            e.preventDefault()
            start_loader()
            if ($('.err_msg').length > 0)
                $('.err_msg').remove()
            $.ajax({
                url: _base_url_ + 'classes/Login.php?f=slogin',
                method: 'POST',
                data: $(this).serialize(),
                error: err => {
                    console.log(err)

                },
                success: function(resp) {
                    if (resp) {
                        resp = JSON.parse(resp)
                        if (resp.status == 'success') {
                            location.replace(_base_url_ + 'student');
                        } else if (resp.status == 'incorrect') {
                            var _frm = $('#slogin-frm')
                            var _msg = "<div class='alert alert-danger text-white err_msg'><i class='fa fa-exclamation-triangle'></i> Incorrect username or password</div>"
                            _frm.prepend(_msg)
                            _frm.find('input').addClass('is-invalid')
                            $('[name="username"]').focus()
                        }
                        end_loader()
                    }
                }
            })
        })
        // System Info
    $('#system-frm').submit(function(e) {
        e.preventDefault()
            // start_loader()
        if ($('.err_msg').length > 0)
            $('.err_msg').remove()
        $.ajax({
            url: _base_url_ + 'classes/SystemSettings.php?f=update_settings',
            data: new FormData($(this)[0]),
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            type: 'POST',
            success: function(resp) {
                if (resp == 1) {
                    // alert_toast("Data successfully saved",'success')
                    location.reload()
                } else {
                    $('#msg').html('<div class="alert alert-danger err_msg">An Error occured</div>')
                    end_load()
                }
            }
        })
    })
})