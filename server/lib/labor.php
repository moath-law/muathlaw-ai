<?php
// حاسبة المستحقات العمالية — نظام العمل السعودي بعد تعديلات 19 فبراير 2025م

const END_REASONS = [
  'employer_unlawful' => 'إنهاء صاحب العمل للعقد دون سبب مشروع (فصل تعسفي)',
  'employer_lawful' => 'إنهاء صاحب العمل للعقد غير محدد المدة لسبب مشروع مع الإشعار (م75)',
  'contract_expiry' => 'انتهاء مدة العقد محدد المدة (م74)',
  'mutual_agreement' => 'اتفاق الطرفين على إنهاء العقد (م74)',
  'resignation' => 'استقالة العامل (م85)',
  'article_80' => 'فصل العامل وفق إحدى حالات المادة 80',
  'article_81' => 'ترك العامل العمل وفق المادة 81 (إخلال صاحب العمل)',
  'force_majeure' => 'ترك العمل لقوة قاهرة خارجة عن إرادة العامل (م87)',
  'female_marriage_childbirth' => 'إنهاء العاملة خلال 6 أشهر من الزواج أو 3 أشهر من الوضع (م87)',
  'retirement' => 'بلوغ سن التقاعد (م74)',
];

function r2($n) { return round($n, 2); }

function labor_date($s, $field) {
  if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) throw new Exception("صيغة التاريخ غير صحيحة في ($field). المطلوب: YYYY-MM-DD ميلادي");
  $d = DateTime::createFromFormat('!Y-m-d', $s, new DateTimeZone('UTC'));
  if (!$d) throw new Exception("صيغة التاريخ غير صحيحة في ($field)");
  return $d;
}

function calculate_labor_dues(array $a) {
  $basic = (float)($a['basic_wage'] ?? 0);
  $reason = $a['end_reason'] ?? '';
  $contract = $a['contract_type'] ?? 'indefinite';
  $pay = $a['wage_payment'] ?? 'monthly';
  $remaining = (int)($a['remaining_contract_days'] ?? 0);
  $noticeGiven = array_key_exists('notice_days_given', $a) && $a['notice_days_given'] !== null ? (int)$a['notice_days_given'] : null;
  $leave = (float)($a['unused_leave_days'] ?? 0);
  $unpaid = (float)($a['unpaid_wages'] ?? 0);
  $contractual = array_key_exists('contractual_compensation', $a) && $a['contractual_compensation'] !== null ? (float)$a['contractual_compensation'] : null;

  if (!($basic > 0)) throw new Exception('الأجر الأساسي مطلوب ويجب أن يكون أكبر من صفر');
  if (!isset(END_REASONS[$reason])) throw new Exception('سبب انتهاء العلاقة غير معروف. القيم المتاحة: ' . implode(', ', array_keys(END_REASONS)));
  $start = labor_date($a['start_date'] ?? null, 'start_date');
  $end = labor_date($a['end_date'] ?? null, 'end_date');
  if ($end <= $start) throw new Exception('تاريخ انتهاء الخدمة يجب أن يكون بعد تاريخ بدايتها');

  $days = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 86400);
  $years = $days / 365;
  $monthly = $basic + (float)($a['housing_allowance'] ?? 0) + (float)($a['transport_allowance'] ?? 0) + (float)($a['other_allowances'] ?? 0);
  $daily = $monthly / 30;
  $items = []; $notes = [];

  // م84
  $fullEos = min($years, 5) * ($monthly / 2) + max($years - 5, 0) * $monthly;
  $factor = 1;
  $basis = 'المادة 84: نصف أجر شهر عن كل سنة من السنوات الخمس الأولى، وأجر شهر عن كل سنة بعدها، على أساس آخر أجر، ويستحق عن أجزاء السنة بنسبتها.';
  if ($reason === 'article_80') {
    $factor = 0;
    $basis = 'لا مكافأة: الفصل وفق المادة 80 يسقط المكافأة (م80، م84). يُنظر في توافر حالة المادة 80 فعلًا وإجراءاتها — وهي محل الطعن عادةً.';
  } elseif ($reason === 'resignation') {
    if ($years < 2) { $factor = 0; $t = 'لا شيء (أقل من سنتين)'; }
    elseif ($years < 5) { $factor = 1/3; $t = 'ثلث المكافأة (2 إلى أقل من 5 سنوات)'; }
    elseif ($years < 10) { $factor = 2/3; $t = 'ثلثي المكافأة (5 إلى أقل من 10 سنوات)'; }
    else { $factor = 1; $t = 'المكافأة كاملة (10 سنوات فأكثر)'; }
    $basis = 'المادة 85: الاستقالة بعد ' . r2($years) . " سنة تستحق $t.";
  } elseif (in_array($reason, ['force_majeure', 'female_marriage_childbirth'])) {
    $basis .= ' والمكافأة كاملة استثناءً من المادة 85 بموجب المادة 87.';
  } elseif ($reason === 'article_81') {
    $basis .= ' وترك العمل وفق المادة 81 يحفظ للعامل حقوقه النظامية كاملة.';
  }
  $items[] = ['key' => 'end_of_service', 'title' => 'مكافأة نهاية الخدمة', 'amount' => r2($fullEos * $factor), 'basis' => $basis];

  // م77
  if ($reason === 'employer_unlawful' || $reason === 'article_81') {
    if ($contractual !== null && $contractual >= 0) { $comp = $contractual; $b = 'المادة 77: التعويض المتفق عليه في العقد.'; }
    elseif ($contract === 'fixed') { $comp = $remaining * $daily; $b = "المادة 77/ب: أجر المدة الباقية من العقد محدد المدة ($remaining يومًا)."; }
    else { $comp = $years * 15 * $daily; $b = 'المادة 77/أ: أجر 15 يومًا عن كل سنة خدمة في العقد غير محدد المدة (' . r2($years) . ' سنة).'; }
    if ($contractual === null && $comp < 2 * $monthly) { $comp = 2 * $monthly; $b .= ' وقد رُفع إلى الحد الأدنى: أجر شهرين (م77).'; }
    if ($reason === 'article_81') $b .= ' (تطبيق المادة 77 على ترك العمل وفق المادة 81 محل تقدير المحكمة بحسب ثبوت الإخلال.)';
    $items[] = ['key' => 'unlawful_termination', 'title' => 'التعويض عن الإنهاء غير المشروع', 'amount' => r2($comp), 'basis' => $b];
  }

  // م75، م76
  if ($reason === 'employer_unlawful' && $contract === 'indefinite' && $noticeGiven !== null) {
    $req = $pay === 'monthly' ? 60 : 30;
    $missing = max($req - $noticeGiven, 0);
    if ($missing > 0) {
      $items[] = ['key' => 'notice', 'title' => 'بدل مهلة الإشعار', 'amount' => r2($missing * $daily), 'basis' => "المادتان 75 و76: مهلة إشعار صاحب العمل $req يومًا، أُعطي منها $noticeGiven، فيستحق العامل أجر $missing يومًا."];
      $notes[] = 'بدل الإشعار (م76) مستقل عن تعويض الإنهاء غير المشروع (م77) ويُطالب بهما معًا.';
    }
  }

  if ($leave > 0) $items[] = ['key' => 'leave', 'title' => 'بدل الإجازة السنوية غير المستنفدة', 'amount' => r2($leave * $daily), 'basis' => "المادة 111: أجر $leave يومًا من الإجازة المستحقة غير المستنفدة. (الإجازة 21 يومًا سنويًا، و30 يومًا بعد 5 سنوات متصلة — م109.)"];
  if ($unpaid > 0) $items[] = ['key' => 'unpaid_wages', 'title' => 'الأجور المتأخرة', 'amount' => r2($unpaid), 'basis' => 'المادة 90: يجب الوفاء بالأجر في موعده، وكل أجر مستحق لم يُدفع يُطالب به.'];

  $total = r2(array_sum(array_column($items, 'amount')));
  $notes[] = 'الحساب على أساس الأجر الفعلي (الأساسي + البدلات الثابتة) آخر أجر تقاضاه العامل، والشهر 30 يومًا، والسنة 365 يومًا.';
  $notes[] = 'سماع الدعوى العمالية: لا تُسمع بعد مضي 12 شهرًا من انتهاء العلاقة التعاقدية (م222). احسب المهلة من تاريخ الانتهاء.';
  $notes[] = 'هذه نتيجة تقديرية للتوعية وليست استشارة قانونية؛ الوقائع والمستندات (العقد، مسيرات الرواتب، خطاب الإنهاء، محادثات الواتساب) قد تغيّر النتيجة.';

  return [
    'service' => ['start_date' => $a['start_date'], 'end_date' => $a['end_date'], 'days' => $days, 'years' => r2($years)],
    'wage' => ['monthly_actual_wage' => r2($monthly), 'daily_wage' => r2($daily)],
    'end_reason' => ['code' => $reason, 'label' => END_REASONS[$reason]],
    'items' => $items, 'total' => $total, 'currency' => 'SAR', 'notes' => $notes,
    'contact' => 'لمراجعة قضيتك: شركة معاذ للمحاماة — muathlaw.com — واتساب 0125118667',
  ];
}

function format_labor_result(array $r) {
  $L = [];
  $L[] = "مدة الخدمة: {$r['service']['years']} سنة ({$r['service']['days']} يومًا) | الأجر الفعلي: {$r['wage']['monthly_actual_wage']} ريال شهريًا";
  $L[] = "سبب الانتهاء: {$r['end_reason']['label']}"; $L[] = '';
  foreach ($r['items'] as $i) $L[] = "• {$i['title']}: " . number_format($i['amount'], 2) . " ريال — {$i['basis']}";
  $L[] = ''; $L[] = 'الإجمالي التقديري: ' . number_format($r['total'], 2) . ' ريال'; $L[] = '';
  foreach ($r['notes'] as $n) $L[] = "- $n";
  $L[] = ''; $L[] = $r['contact'];
  return implode("\n", $L);
}
