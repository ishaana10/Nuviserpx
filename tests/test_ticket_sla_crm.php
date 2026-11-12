<?php
// Ticket service targets and CRM contacts / lead source / lost reason.
$root = dirname(__DIR__);
require_once "$root/src/Services/TicketSlaService.php";
require_once "$root/src/Services/CrmService.php";
use App\Services\TicketSlaService as S;
use App\Services\CrmService as C;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$H = 3600;

// ---- clocks
check('time not spent waiting counts', S::elapsed(1000, 1000 + 10 * $H, 2 * $H, null) === 8 * $H);
check('an open pause stops the clock', S::elapsed(1000, 1000 + 10 * $H, 0, 1000 + 6 * $H) === 6 * $H);
check('banked and open pause both count', S::elapsed(0, 10 * $H, 2 * $H, 8 * $H) === 6 * $H);
check('never negative', S::elapsed(1000, 1500, 99999, null) === 0);

check('finished inside target is met', S::clock(4, 0, 3 * $H)['state'] === 'met');
check('finished late is met late', S::clock(4, 0, 5 * $H)['state'] === 'met_late');
check('plenty of time is on track', S::clock(4, 1 * $H, null)['state'] === 'on_track');
check('last quarter is at risk', S::clock(4, 3 * $H + 600, null)['state'] === 'at_risk');
check('past the limit is breached with time overdue', ($c = S::clock(4, 5 * $H, null)) && $c['state'] === 'breached' && $c['seconds_left'] === -1 * $H);

$created = '2026-11-01 08:00:00';
$ts = strtotime($created);
$t = ['date_created' => $created, 'first_response_at' => null, 'resolved_at' => null, 'paused_at' => null, 'paused_seconds' => 0];
$e = S::evaluate($t, [4, 24], $ts + 5 * $H);
check('unanswered past its reply target breaches, resolution still on track', $e['response']['state'] === 'breached' && $e['resolve']['state'] === 'on_track');
$t2 = $t; $t2['first_response_at'] = date('Y-m-d H:i:s', $ts + 2 * $H);
check('replied in time meets the reply target', S::evaluate($t2, [4, 24], $ts + 5 * $H)['response']['state'] === 'met');
$t3 = $t; $t3['paused_at'] = date('Y-m-d H:i:s', $ts + 2 * $H);
$e3 = S::evaluate($t3, [4, 24], $ts + 40 * $H);
check('waiting on the customer pauses resolution, not the reply clock', $e3['resolve']['state'] === 'on_track' && $e3['resolve']['paused'] === true && $e3['response']['state'] === 'breached');
$t4 = $t; $t4['resolved_at'] = date('Y-m-d H:i:s', $ts + 30 * $H);
check('resolved after the target is met late', S::evaluate($t4, [4, 24], $ts + 99 * $H)['resolve']['state'] === 'met_late');
check('resolution due moves later by the paused time', S::evaluate(array_merge($t, ['paused_seconds' => 3 * $H]), [4, 24], $ts + $H)['resolve']['due'] === $ts + 27 * $H);

// ---- status changes
$now = $ts + 10 * $H;
$tr = S::transition(['status' => 'in_progress', 'resolved_at' => null, 'paused_at' => null, 'paused_seconds' => 0], 'waiting_client', $now);
check('entering waiting pauses', isset($tr['paused_at']) && !isset($tr['paused_seconds']));
$tr = S::transition(['status' => 'waiting_client', 'resolved_at' => null, 'paused_at' => date('Y-m-d H:i:s', $now - $H), 'paused_seconds' => 600], 'in_progress', $now);
check('leaving waiting banks the wait', $tr['paused_seconds'] === 600 + $H && $tr['paused_at'] === null);
$tr = S::transition(['status' => 'in_progress', 'resolved_at' => null, 'paused_at' => null, 'paused_seconds' => 0], 'resolved', $now);
check('resolving stamps the time', isset($tr['resolved_at']));
$tr = S::transition(['status' => 'waiting_client', 'resolved_at' => null, 'paused_at' => date('Y-m-d H:i:s', $now - $H), 'paused_seconds' => 0], 'closed', $now);
check('closing while waiting ends the pause and stamps', $tr['paused_at'] === null && $tr['paused_seconds'] === $H && isset($tr['resolved_at']));
$tr = S::transition(['status' => 'resolved', 'resolved_at' => '2026-11-01 12:00:00', 'paused_at' => null, 'paused_seconds' => 0], 'in_progress', $now);
check('reopening clears the resolution time', array_key_exists('resolved_at', $tr) && $tr['resolved_at'] === null);
check('a resolved ticket cannot start waiting', !isset(S::transition(['status' => 'resolved', 'resolved_at' => 'x', 'paused_at' => null, 'paused_seconds' => 0], 'waiting_client', $now)['paused_at']));

check('time wording', S::human(2700) === '45m' && S::human(8100) === '2h 15m' && S::human(100800) === '1d 4h' && S::human(-7200) === '2h 0m');

check('policy table accepted', S::cleanPolicies(['urgent' => '1', 'high' => '4', 'medium' => '8', 'low' => '24'], ['urgent' => '4', 'high' => '8', 'medium' => '24', 'low' => '72'])[1] === null);
check('reply after resolution refused', S::cleanPolicies(['urgent' => '9', 'high' => '4', 'medium' => '8', 'low' => '24'], ['urgent' => '4', 'high' => '8', 'medium' => '24', 'low' => '72'])[1] !== null);
check('missing or silly hours refused', S::cleanPolicies(['urgent' => ''], [])[1] !== null && S::cleanPolicies(['urgent' => '0', 'high' => '1', 'medium' => '1', 'low' => '1'], ['urgent' => '1', 'high' => '1', 'medium' => '1', 'low' => '1'])[1] !== null);
check('every priority has a default', array_keys(S::DEFAULTS) === S::PRIORITIES || count(array_diff(S::PRIORITIES, array_keys(S::DEFAULTS))) === 0);

// ---- CRM
check('lead source must be known', C::cleanSource('referral') === 'referral' && C::cleanSource('nonsense') === null && C::cleanSource('') === null);
check('lost reason must be known', C::cleanLostReason('price') === 'price' && C::cleanLostReason('x') === null);
check('a deal cannot be lost without a reason', C::stageProblem('lost', null) !== null && C::stageProblem('lost', 'price') === null);
check('other stages need no reason', C::stageProblem('won', null) === null && C::stageProblem('proposal', null) === null);
check('unknown stage refused', C::stageProblem('maybe', null) !== null);
check('win rate', C::winRate(3, 1) === 75.0 && C::winRate(0, 0) === null && C::winRate(0, 2) === 0.0);
[$f, $err] = C::cleanContact(['name' => '  Ana Rao ', 'email' => 'ana@example.com', 'is_primary' => '1']);
check('contact cleaned', $err === null && $f['name'] === 'Ana Rao' && $f['is_primary'] === 1);
check('contact needs a name', C::cleanContact(['name' => ' '])[1] !== null);
check('contact email checked', C::cleanContact(['name' => 'A', 'email' => 'nope'])[1] !== null);
check('blank email allowed', C::cleanContact(['name' => 'A'])[1] === null);

$m = file_get_contents("$root/classes/Master.php");
check('replies are limited to the company\'s own tickets', strpos($m, "SELECT 1 FROM `tickets` WHERE id = '{\$t_id}' AND tenant_id = '{\$tenant_id}'") !== false);
check('status changes run the SLA clocks', substr_count($m, '->applyStatus(') === 2 && strpos($m, '->markFirstResponse(') !== false);
check('moving to lost passes the reason', strpos($m, "(string)(\$_POST['lost_reason'] ?? '')") !== false);
foreach (['save_sla_policies', 'save_crm_contact', 'delete_crm_contact'] as $a) { check("$a is routed", strpos($m, "case '$a':") !== false); }
$perm = file_get_contents("$root/src/Support/Permissions.php");
check('SLA targets need settings_manage', preg_match("/'save_sla_policies'\s*=>\s*\['settings_manage'\]/", $perm) === 1);
check('contacts use CRM permissions', preg_match("/'save_crm_contact'\s*=>\s*\['crm', 'save'\]/", $perm) === 1 && preg_match("/'delete_crm_contact'\s*=>\s*\['crm', 'delete'\]/", $perm) === 1);
$mig = file_get_contents("$root/database/migrations/034_ticket_sla_crm_contacts.sql");
check('migration covers tickets, policies, deals and contacts', substr_count($mig, 'ADD COLUMN') === 7 && strpos($mig, 'ticket_sla_policies') !== false && strpos($mig, 'crm_contacts') !== false);
exit($fail ? 1 : 0);
