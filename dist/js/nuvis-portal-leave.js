/* Employee Portal: My Leave tabs, calendar and the live balance check on the request form. */
(function () {
  'use strict';
  var $ = window.jQuery;
  var cal = window.PP_CAL || { leave: [], holidays: [] };
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var hol = {}; cal.holidays.forEach(function (h) { hol[h.d] = h.n; });
  function iso(y, m, d) { return y + '-' + ('0' + (m + 1)).slice(-2) + '-' + ('0' + d).slice(-2); }
  function leaveOn(date) {
    var hit = null;
    cal.leave.forEach(function (l) { if (l.s <= date && l.e >= date && (!hit || l.st === 'approved')) { hit = l.st; } });
    return hit;
  }
  var today = new Date(); var todayIso = iso(today.getFullYear(), today.getMonth(), today.getDate());

  /* ---- tabs ---- */
  function showTab(name) {
    if (!$('[data-pane="' + name + '"]').length) { name = 'balances'; }
    $('[data-pane]').removeClass('on'); $('[data-pane="' + name + '"]').addClass('on');
    $('#ppTabs a').removeClass('active'); $('#ppTabs a[data-tab="' + name + '"]').addClass('active');
  }
  $('#ppTabs a').on('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + $(this).data('tab')); showTab($(this).data('tab')); });
  showTab((location.hash || '#balances').slice(1));

  /* ---- calendar ---- */
  function monthTable(y, m, pickable) {
    var first = new Date(y, m, 1); var lead = (first.getDay() + 6) % 7; var days = new Date(y, m + 1, 0).getDate();
    var s = '<table class="pp-cal"><caption>' + MONTHS[m] + ' ' + y + '</caption><thead><tr>' +
      ['M', 'T', 'W', 'T', 'F', 'S', 'S'].map(function (d) { return '<th>' + d + '</th>'; }).join('') + '</tr></thead><tbody><tr>';
    for (var i = 0; i < lead; i++) { s += '<td class="off"></td>'; }
    for (var d = 1; d <= days; d++) {
      var date = iso(y, m, d); var col = (lead + d - 1) % 7; var cls = [];
      if (col > 4) { cls.push('we'); }
      if (hol[date]) { cls.push('hol'); }
      var st = leaveOn(date); if (st) { cls.push(st); }
      if (date === todayIso) { cls.push('today'); }
      var sd = $('#sd').val(), ed = $('#ed').val();
      if (pickable && sd && date >= sd && date <= (ed || sd)) { cls.push('pick'); }
      if (pickable) { cls.push('sel'); }
      s += '<td class="' + cls.join(' ') + '" data-date="' + date + '"' + (hol[date] ? ' title="' + $('<i>').text(hol[date]).html() + '"' : '') + '>' + d + '</td>';
      if (col === 6 && d < days) { s += '</tr><tr>'; }
    }
    var tail = (lead + days) % 7; if (tail) { for (var t = tail; t < 7; t++) { s += '<td class="off"></td>'; } }
    return s + '</tr></tbody></table>';
  }
  function mount(sel, pickable) {
    var $box = $(sel); if (!$box.length) { return; }
    var view = new Date(today.getFullYear(), today.getMonth(), 1);
    function draw() {
      var wide = window.innerWidth >= 768 && !pickable;
      var n = wide ? 2 : 1; var months = '';
      for (var k = 0; k < n; k++) { var v = new Date(view.getFullYear(), view.getMonth() + k, 1); months += monthTable(v.getFullYear(), v.getMonth(), pickable); }
      $box.html('<div class="pp-calhead"><button type="button" data-nav="-1" aria-label="Previous month">&lsaquo;</button><b>' +
        MONTHS[view.getMonth()] + ' ' + view.getFullYear() + '</b><button type="button" data-nav="1" aria-label="Next month">&rsaquo;</button></div>' +
        '<div class="pp-calmonths' + (n === 2 ? ' two' : '') + '">' + months + '</div>' +
        '<div class="pp-legend"><span><i style="background:#bbf7d0"></i>Approved leave</span><span><i style="background:#fef3c7"></i>Pending</span>' +
        '<span><i style="background:#dbeafe"></i>Public holiday</span><span><i style="background:#e2e8f0"></i>Weekend</span>' + (pickable ? '<span><i style="background:#2563eb"></i>Your selection</span>' : '') + '</div>');
    }
    $box.on('click', '[data-nav]', function () { view = new Date(view.getFullYear(), view.getMonth() + parseInt($(this).data('nav'), 10), 1); draw(); });
    if (pickable) {
      $box.on('click', 'td.sel', function () {
        var d = $(this).data('date'); var sd = $('#sd').val(), ed = $('#ed').val();
        if (!sd || (sd && ed && sd !== ed) || d < sd) { $('#sd').val(d); $('#ed').val(d); } else { $('#ed').val(d); }
        $('#df').val() === '0.5' && $('#ed').val($('#sd').val());
        check(); draw();
      });
    }
    $(window).on('resize', draw); draw();
    return draw;
  }
  var redrawApply = mount('#calApply', true); mount('#calView', false);

  /* ---- live validation ---- */
  var seq = 0, ok = false;
  function post(fn, data, done, opts) {
    $.ajax($.extend({ url: _base_url_ + 'classes/Master.php?f=' + fn, method: 'POST', data: data, dataType: 'json',
      error: function (xhr) {
        var t = String(xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140);
        done({ status: 'failed', msg: 'Something went wrong (' + (xhr.status || 'no connection') + '). Please try again.' + (t ? ' ' + t : '') });
      },
      success: function (r) { if (r && r.debug) { r.msg = (r.msg || '') + ' [' + r.debug + ']'; } done(r); } }, opts || {}));
  }
  function num(v) { return (Math.round(v * 100) / 100).toString(); }
  function check() {
    var sd = $('#sd').val(), ed = $('#ed').val(), mine = ++seq; ok = false; $('#leaveBtn').prop('disabled', true); $('#leaveErr').text('');
    $('#docLbl').html('Attachment <span class="text-muted">(optional)</span>');
    $('#docHint').text($('#lt').val() === 'sick' ? 'For sick leave, attach your medical certificate. PDF, JPG or PNG, up to 2 MB.' : 'PDF, JPG or PNG, up to 2 MB.');
    if (!sd || !ed) { $('#ppDays').text('0'); $('#ppBalRow').hide(); $('#ppMsg').text(''); return; }
    post('employee_portal_leave_preview', { leave_type: $('#lt').val(), start_date: sd, end_date: ed, day_fraction: $('#df').val() }, function (r) {
      if (mine !== seq) { return; }
      if (r.status !== 'success') { $('#ppMsg').removeClass('ok').text(r.msg || ''); return; }
      $('#ppDays').text(num(r.days));
      if (r.tracked) { $('#ppBal').text(num(r.balance)); $('#ppAfter').text(num(r.after)); $('#ppBalRow').show(); } else { $('#ppBalRow').hide(); }
      ok = !!r.ok; $('#ppMsg').toggleClass('ok', ok).text(ok ? 'Looks good. You can submit this request.' : (r.msg || ''));
      $('#leaveBtn').prop('disabled', !ok);
    });
  }
  $('#sd').on('change', function () { var e = $('#ed'); if (!e.val() || e.val() < this.value || $('#df').val() === '0.5') { e.val(this.value); } e.attr('min', this.value); check(); redrawApply && redrawApply(); });
  $('#ed').on('change', function () { check(); redrawApply && redrawApply(); });
  $('#lt').on('change', check);
  $('#df').on('change', function () { if (this.value === '0.5') { $('#ed').val($('#sd').val()); } check(); redrawApply && redrawApply(); });
  $('#doc').on('change', function () {
    var f = this.files && this.files[0]; if (!f) { return; }
    if (f.size > 2 * 1024 * 1024) { alert('That file is larger than 2 MB. Please choose a smaller one (a phone photo of the certificate is fine).'); this.value = ''; }
  });
  $('#leaveFrm').on('submit', function (e) {
    e.preventDefault(); if (!ok) { return; }
    var $b = $('#leaveBtn').prop('disabled', true); $('#leaveErr').text('');
    post('employee_portal_leave_apply', new FormData(this), function (r) {
      if (r.status === 'success') { location.href = 'employee_leave.php?sent=1#requests'; }
      else { $('#leaveErr').text(r.msg || 'Could not submit.'); $b.prop('disabled', false); }
    }, { processData: false, contentType: false });
  });
  $('.cancel-leave').on('click', function () {
    if (!confirm('Cancel this leave request?')) { return; }
    var $b = $(this).prop('disabled', true);
    post('employee_portal_leave_cancel', { id: $b.data('id') }, function (r) {
      if (r.status === 'success') { location.reload(); } else { alert(r.msg || 'Could not cancel.'); $b.prop('disabled', false); }
    });
  });
  if (location.search.indexOf('sent=1') > -1) { $('<div class="alert alert-success">Your request was sent to your approver.</div>').insertBefore('.pp-h1').delay(5000).fadeOut(); }
})();
