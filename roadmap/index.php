<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pdo = getPDO();

$items = $pdo->query("SELECT * FROM roadmap_items ORDER BY due_date IS NULL, due_date ASC, created_at DESC")->fetchAll();

// -- People, for assignment and the filter --
$users = $pdo->query("
    SELECT user_id, TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) AS full_name
    FROM ff_users
    WHERE is_active = 1
    ORDER BY first_name, last_name
")->fetchAll();
$userById = [];
foreach ($users as $u) $userById[(int)$u['user_id']] = $u['full_name'];

// How many goals each item has, and how many are done. Goals themselves live
// on the item's own page (item.php) - here they are only a badge and a link.
$goalCounts = [];
foreach ($pdo->query("SELECT item_id, COUNT(*) AS total, SUM(status = 'done') AS done FROM roadmap_goals GROUP BY item_id") as $row) {
    $goalCounts[(int)$row['item_id']] = ['total' => (int)$row['total'], 'done' => (int)$row['done']];
}

// The assignee filter is applied to $items BEFORE anything is bucketed, so the
// timeline, every card tab and all the tab counts agree with it instead of
// each one having to remember to apply it.
$who = (string)($_GET['who'] ?? 'all');
if ($who !== 'all' && $who !== 'none' && !isset($userById[(int)$who])) $who = 'all';
if ($who !== 'all') {
    $wantId = $who === 'none' ? null : (int)$who;
    $items  = array_values(array_filter(
        $items,
        fn($i) => ($i['assigned_to'] === null ? null : (int)$i['assigned_to']) === $wantId
    ));
}

$today   = date('Y-m-d');
$dow     = (int)date('N'); // 1 = Monday ... 7 = Sunday
$weekEnd = date('Y-m-d', strtotime("+" . (7 - $dow) . " days"));

$tasks    = array_values(array_filter($items, fn($i) => $i['type'] === 'task' && $i['status'] !== 'archived'));
$ideas    = array_values(array_filter($items, fn($i) => $i['type'] === 'idea' && $i['status'] !== 'archived'));
$archived = array_values(array_filter($items, fn($i) => $i['status'] === 'archived'));

// ── Reports: Today / This Week ──
$reportToday = array_values(array_filter($tasks, fn($i) => $i['status'] !== 'done' && $i['due_date'] !== null && $i['due_date'] <= $today));
$reportWeek  = array_values(array_filter($tasks, fn($i) => $i['status'] !== 'done' && $i['due_date'] !== null && $i['due_date'] >= $today && $i['due_date'] <= $weekEnd));

// ── Timeline window: current quarter + next 3 quarters ──
$qStartMonth  = intdiv((int)date('n') - 1, 3) * 3 + 1;
$firstMonthTs = strtotime(sprintf('%04d-%02d-01', (int)date('Y'), $qStartMonth));
$windowStart  = date('Y-m-01', $firstMonthTs);
$windowEnd    = date('Y-m-t', strtotime('+11 months', $firstMonthTs));

// Zoom decides how fine the columns are: one per week, month, or quarter.
$zoom = $_GET['zoom'] ?? 'week';
if (!in_array($zoom, ['week', 'month', 'quarter'], true)) $zoom = 'week';

// Zoom and assignee both live in the querystring, so every control has to
// carry the other one's current value or clicking one resets the other.
$rmUrl = fn(array $overrides) => '?' . http_build_query(array_merge(['zoom' => $zoom, 'who' => $who], $overrides));

// The 12 months of the window are the backbone — every zoom level is derived
// from them, so all three share the same quarter/month grouping.
$months = [];
for ($m = 0; $m < 12; $m++) {
    $mTs = strtotime("+{$m} months", $firstMonthTs);
    $months[] = [
        'key'       => date('Y-m', $mTs),
        'label'     => date('M', $mTs),
        'year'      => (int)date('Y', $mTs),
        'monthNum'  => (int)date('n', $mTs),
        'start'     => date('Y-m-01', $mTs),
        'end'       => date('Y-m-t', $mTs),
        'qIdx'      => intdiv($m, 3),
        'isCurrent' => date('Y-m', $mTs) === date('Y-m'),
    ];
}

$quarterLabels = [];
for ($q = 0; $q < 4; $q++) {
    $qm = array_slice($months, $q * 3, 3);
    $quarterLabels[$q] = 'Q' . (intdiv($qm[0]['monthNum'] - 1, 3) + 1) . ' ' . $qm[0]['year'];
}

// Build the columns for the chosen zoom. Each column carries its date range
// plus which month and quarter it belongs to, so the header rows can be
// derived by simply grouping columns.
$cols = [];
if ($zoom === 'week') {
    // Weeks are built PER MONTH and clipped at the month boundary, so a week
    // column never spans two months — a date can't land in a column that
    // belongs to a neighbouring month (Sep 30 stays in September rather than
    // falling into the Sep 28 – Oct 4 week under October's header). Weeks are
    // Monday-start; the first and last week of a month may be partial.
    foreach ($months as $mi => $mo) {
        $monthEnd = strtotime($mo['end']);
        $cursor   = strtotime($mo['start']);
        $wk       = 1;
        while ($cursor <= $monthEnd) {
            $endTs = min(strtotime('+' . (7 - (int)date('N', $cursor)) . ' days', $cursor), $monthEnd);
            $cols[] = [
                'start'    => date('Y-m-d', $cursor),
                'end'      => date('Y-m-d', $endTs),
                'label'    => 'Wk ' . $wk,
                'monthIdx' => $mi,
                'qIdx'     => $mo['qIdx'],
            ];
            $cursor = strtotime('+1 day', $endTs);
            $wk++;
        }
    }
} elseif ($zoom === 'month') {
    foreach ($months as $mi => $mo) {
        $cols[] = ['start' => $mo['start'], 'end' => $mo['end'], 'label' => $mo['label'], 'monthIdx' => $mi, 'qIdx' => $mo['qIdx']];
    }
} else { // quarter
    for ($q = 0; $q < 4; $q++) {
        $qm = array_slice($months, $q * 3, 3);
        $cols[] = ['start' => $qm[0]['start'], 'end' => $qm[2]['end'], 'label' => $quarterLabels[$q], 'monthIdx' => null, 'qIdx' => $q];
    }
}

$numCols = count($cols);
foreach ($cols as $idx => &$c) {
    $next             = $cols[$idx + 1] ?? null;
    $c['isCurrent']   = $today >= $c['start'] && $today <= $c['end'];
    $c['isMonthEnd']  = $next === null || $next['monthIdx'] !== $c['monthIdx'];
    $c['isQtrEnd']    = $next === null || $next['qIdx'] !== $c['qIdx'];
    // Heavier divider at the boundary one level coarser than the columns.
    $c['isHeavy']     = $zoom === 'week' ? $c['isMonthEnd'] : $c['isQtrEnd'];
    // Day count drives the sub-column geometry of the task bars.
    $c['days']        = (int)round((strtotime($c['end']) - strtotime($c['start'])) / 86400) + 1;
}
unset($c);

// Header rows: grouping rows one level coarser than the columns, then a final
// row of per-column labels.
//   week zoom    → quarter groups, month groups, "Wk n" per column
//   month zoom   → quarter groups, "Jul" per column
//   quarter zoom → "Q3 2026" per column
// 'corner' names the row in the label column, so it's obvious what each
// header row reads as.
$zoomPlural = ['week' => 'Weeks', 'month' => 'Months', 'quarter' => 'Quarters'][$zoom];
$headerRows = [];
if ($zoom !== 'quarter') $headerRows[] = ['kind' => 'q', 'corner' => 'Quarters', 'groups' => rmGroupCols($cols, 'qIdx')];
if ($zoom === 'week')    $headerRows[] = ['kind' => 'm', 'corner' => 'Months', 'groups' => rmGroupCols($cols, 'monthIdx')];
$headerRows[] = ['kind' => 'labels', 'corner' => $zoomPlural, 'groups' => null];

// The per-column label row is small at week zoom, prominent otherwise.
$labelRowClass = $zoom === 'week' ? 'rm-w' : 'rm-m';

function rmGroupCols(array $cols, string $by): array {
    $groups = [];
    foreach ($cols as $idx => $c) {
        if ($c[$by] === null) continue;
        $last = array_key_last($groups);
        if ($last !== null && $groups[$last]['val'] === $c[$by]) {
            $groups[$last]['span']++;
        } else {
            $groups[] = ['val' => $c[$by], 'startCol' => 3 + $idx, 'span' => 1];
        }
    }
    return $groups;
}

function findColIndex(array $cols, string $date): int {
    foreach ($cols as $idx => $c) {
        if ($date >= $c['start'] && $date <= $c['end']) return $idx;
    }
    return $date < $cols[0]['start'] ? 0 : count($cols) - 1;
}

// Column width is FIXED per zoom. It has to be: a bar's width is meant to be
// proportional to its dates, which is only true if the time axis is linear.
// (Content-sized columns would make "half the column" stop meaning "half the
// week".) The task title is allowed to overflow a narrow bar instead.
$colW = ['week' => 74, 'month' => 150, 'quarter' => 240][$zoom];

// The two frozen columns ahead of the time axis. The task-name column is
// sticky at exactly LANE_W, so these two values have to stay in step - they
// are written into the CSS below rather than repeated as literals.
const RM_LANE_W = 110;
const RM_NAME_W = 270;

// Tasks due within the window (anything overdue clamps to the first column).
// Each bar also gets sub-column insets so it starts and ends at its real
// dates rather than snapping to whole columns: a task due mid-week reaches
// only halfway into that week's column.
$timelineTasks = [];
foreach ($tasks as $t) {
    if ($t['due_date'] === null || $t['due_date'] > $windowEnd) continue;
    $startDate      = max($t['start_date'] ?: $t['due_date'], $cols[0]['start']);
    $t['_startCol'] = findColIndex($cols, $startDate);
    $t['_endCol']   = findColIndex($cols, $t['due_date']);
    if ($t['_endCol'] < $t['_startCol']) $t['_endCol'] = $t['_startCol'];

    $sc = $cols[$t['_startCol']];
    $ec = $cols[$t['_endCol']];
    // Day offset of the start within its column, and of the due date within
    // its column, both clamped inside that column.
    $i0 = min(max((int)round((strtotime($startDate)     - strtotime($sc['start'])) / 86400), 0), $sc['days'] - 1);
    $i1 = min(max((int)round((strtotime($t['due_date']) - strtotime($ec['start'])) / 86400), 0), $ec['days'] - 1);
    // Trim the leading edge by the unused days at the start of the first
    // column, and the trailing edge by the unused days after the due date.
    $t['_leadPx']  = round(($i0 / $sc['days']) * $colW, 1);
    $t['_trailPx'] = round((($ec['days'] - 1 - $i1) / $ec['days']) * $colW, 1);
    $timelineTasks[] = $t;
}

$lanes = ['in_progress' => 'In Progress', 'backlog' => 'Backlog', 'done' => 'Done'];

// Grid rows: the header rows, then one row per task in each lane (min 1 so an
// empty lane still has a row — the grid always renders, tasks or not).
$numHeaderRows = count($headerRows);
$laneRowInfo   = [];
$currentRow    = $numHeaderRows + 1;
foreach ($lanes as $laneKey => $laneLabel) {
    $laneTasks = array_values(array_filter($timelineTasks, fn($t) => $t['status'] === $laneKey));
    $rowCount  = max(1, count($laneTasks));
    $laneRowInfo[$laneKey] = ['startRow' => $currentRow, 'rowCount' => $rowCount, 'tasks' => $laneTasks];
    $currentRow += $rowCount;
}
$totalGridRows = $currentRow - 1;

// Today marker: which column it sits in, and how far into THAT column (as a
// percentage of the column's own day count — columns differ in width, so a
// fraction of the whole timeline's width won't do).
$curColIdx   = 0;
$todayDayPct = 0;
foreach ($cols as $idx => $c) {
    if (!$c['isCurrent']) continue;
    $curColIdx   = $idx;
    $colDays     = (int)round((strtotime($c['end']) - strtotime($c['start'])) / 86400) + 1;
    $dayIndex    = (int)round((strtotime($today) - strtotime($c['start'])) / 86400);
    $todayDayPct = round(($dayIndex / max(1, $colDays)) * 100, 2);
    break;
}

// Where to auto-scroll on load: the start of the CURRENT MONTH, so the whole
// month is in view rather than just the current week. Columns are a fixed
// width, so the offset is exact arithmetic — the month's first column lands
// flush against the (sticky) label column.
$scrollColIdx = $curColIdx;
foreach ($months as $mi => $mo) {
    if (!$mo['isCurrent']) continue;
    foreach ($cols as $ci => $c) {
        if ($c['monthIdx'] === $mi) { $scrollColIdx = $ci; break 2; }
    }
    break;
}
$scrollLeftPx = max(0, $scrollColIdx * $colW);


$progressPct   = ['backlog' => 0, 'in_progress' => 50, 'done' => 100];
$priorityColor = ['low' => '#64748b', 'medium' => '#d97706', 'high' => '#dc2626'];

include __DIR__ . '/../includes/header.php';

// A name can't fit inside a timeline bar's label, so a person is drawn as an
// initials avatar. The colour comes off the user id, so one person keeps the
// same colour everywhere they appear on the page.
function rmInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . $last);
}

function rmAvatarColor(int $userId): string {
    $palette = ['#2D6BF5', '#7c3aed', '#0891b2', '#059669', '#d97706', '#db2777', '#4f46e5'];
    return $palette[$userId % count($palette)];
}

function rmAvatar($userId, array $userById, string $extraClass = ''): string {
    $id = ($userId === null || $userId === '') ? null : (int)$userId;
    if ($id === null || !isset($userById[$id])) return '';
    $name = $userById[$id];
    return '<span class="rm-avatar ' . $extraClass . '" style="background:' . rmAvatarColor($id) . '"'
         . ' title="' . htmlspecialchars($name) . '">' . htmlspecialchars(rmInitials($name)) . '</span>';
}

// "2/3 goals done" on a task, doubling as the link to the task's own page
// where its goals and their whiteboards live.
function rmGoalBadge(array $goalCounts, $itemId): string {
    $id = (int)$itemId;
    if (!isset($goalCounts[$id])) return '';
    $c = $goalCounts[$id];
    return '<a class="rm-goal-badge" href="item.php?id=' . $id . '" onclick="event.stopPropagation()"'
         . ' title="' . $c['done'] . ' of ' . $c['total'] . ' goals done">'
         . '<i class="fas fa-diagram-project"></i>' . $c['done'] . '/' . $c['total'] . '</a>';
}

function chipColor(array $r, string $today, array $priorityColor): string {
    $isOverdue = $r['status'] !== 'done' && $r['due_date'] !== null && $r['due_date'] < $today;
    return $isOverdue ? '#dc2626' : $priorityColor[$r['priority']];
}

/**
 * How full a task's timeline bar should be, as a percentage, or NULL when
 * there is nothing to go on.
 *
 * Progress comes from the task's goals — the share of them marked done — since
 * that is the only real completion signal in the module. A task with no goals
 * has no progress to report and keeps a solid bar rather than showing an empty
 * one, which would read as "nothing done" when it actually means "not tracked".
 */
function rmGoalProgress(array $goalCounts, array $r): ?int {
    // A task marked done is done, whatever its goals were left at.
    if ($r['status'] === 'done') return 100;
    $id = (int)$r['item_id'];
    if (empty($goalCounts[$id]['total'])) return null;
    return (int)round($goalCounts[$id]['done'] / $goalCounts[$id]['total'] * 100);
}

function renderReportCard(array $r, string $today, array $priorityColor, array $progressPct, array $userById, array $goalCounts): string {
    $pColor      = $priorityColor[$r['priority']];
    $isLate      = $r['status'] !== 'done' && $r['due_date'] !== null && $r['due_date'] < $today;
    $borderColor = $isLate ? '#dc2626' : $pColor;
    $pct         = $progressPct[$r['status']];
    $dueLabel    = $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '';
    if ($isLate) $dueLabel .= ' (overdue)';

    ob_start(); ?>
    <div class="rm-card" style="border-left-color: <?= $borderColor ?>">
        <div class="rm-card-top">
            <div class="d-flex align-items-center gap-2">
                <span class="rm-pill" style="background:<?= $pColor ?>1a;color:<?= $pColor ?>"><?= ucfirst($r['priority']) ?></span>
                <?= rmAvatar($r['assigned_to'] ?? null, $userById) ?>
                <?= rmGoalBadge($goalCounts, $r['item_id']) ?>
            </div>
            <div class="rm-card-actions">
                <a class="rm-icon-btn" title="Open" href="item.php?id=<?= (int)$r['item_id'] ?>"><i class="fas fa-up-right-from-square"></i></a>
                <?php if ($r['status'] !== 'done'): ?>
                <button class="rm-icon-btn rm-icon-success" title="Mark Done" onclick='markDone(<?= json_encode($r) ?>)'><i class="fas fa-check"></i></button>
                <?php endif; ?>
                <button class="rm-icon-btn" title="Edit" onclick='openEditModal(<?= json_encode($r) ?>)'><i class="fas fa-pencil"></i></button>
                <button class="rm-icon-btn rm-icon-danger" title="Delete" onclick="confirmDelete(<?= $r['item_id'] ?>, <?= htmlspecialchars(json_encode($r['title'])) ?>)"><i class="fas fa-trash-can"></i></button>
            </div>
        </div>
        <div class="rm-card-title"><?= htmlspecialchars($r['title']) ?></div>
        <?php if ($r['description']): ?><div class="rm-card-desc"><?= htmlspecialchars($r['description']) ?></div><?php endif; ?>
        <div class="rm-card-progress"><div class="rm-card-progress-fill" style="width:<?= $pct ?>%"></div></div>
        <div class="rm-card-bottom">
            <span class="rm-card-meta <?= $isLate ? 'text-danger fw-semibold' : '' ?>"><i class="fas fa-calendar-day"></i> <?= htmlspecialchars($dueLabel) ?></span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>

<style>
:root { --rm-blue: #2D6BF5; }
.rm-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem; }
.rm-title {
    font-family: 'Exo 2', sans-serif; font-weight: 700; font-size: 1.9rem; color: #1e293b;
    display: flex; align-items: center; gap: .6rem; margin: 0;
}
.rm-title i {
    background: linear-gradient(135deg, #fb923c, #c2410c);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.rm-add-btns .btn { border-radius: 999px; font-weight: 600; font-size: .9rem; }

.rm-tabs { display: flex; gap: .4rem; background: #eef1f6; padding: 5px; border-radius: 999px; width: fit-content; margin-bottom: 1.5rem; }
.rm-tab {
    border: none; background: transparent; padding: .5rem 1.1rem; border-radius: 999px;
    font-weight: 600; font-size: .88rem; color: #64748b; cursor: pointer; transition: all .15s ease;
    display: flex; align-items: center; gap: .4rem;
}
.rm-tab:hover { color: #1e293b; }
.rm-tab.active { background: #fff; color: var(--rm-blue); box-shadow: 0 1px 4px rgba(0,0,0,.1); }
.rm-tab .count { background: #e2e8f0; color: #475569; font-size: .72rem; font-weight: 700; padding: 1px 7px; border-radius: 999px; min-width: 20px; text-align: center; }
.rm-tab.active .count { background: var(--rm-blue); color: #fff; }
.rm-pane { display: none; }
.rm-pane.active { display: block; }

/* ══ TIMELINE ══
   One CSS grid. Columns: a fixed label column + one column per week.
   Rows: 3 header rows (quarter / month / week) + one row per task.
   Everything is a plain bordered grid cell — no overlays except the Today
   line, and every column/row count is written as a literal value. */
.rm-zoom { display: flex; align-items: center; gap: .35rem; margin-bottom: .6rem; }
.rm-zoom-label { font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; margin-right: .2rem; }
.rm-zoom-btn {
    text-decoration: none; font-size: .78rem; font-weight: 600; color: #64748b;
    background: #eef1f6; padding: .25rem .8rem; border-radius: 999px; transition: all .15s ease;
}
.rm-zoom-btn:hover { color: #1e293b; }
.rm-zoom-btn.active { background: var(--rm-blue); color: #fff; }

.rm-timeline-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
.rm-timeline-grid { position: relative; display: grid; }

.rm-corner {
    position: sticky; left: 0; z-index: 6;
    background: #fff; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #eef1f6;
    display: flex; align-items: center; justify-content: flex-start; padding: 0 .9rem;
    font-size: .64rem; font-weight: 700; color: #b6bfcc; text-transform: uppercase; letter-spacing: .05em;
}

/* Column 2's header is blank but carries .rm-name's 2px right edge, so both
   frozen dividers run unbroken from the top of the header to the last row. */
.rm-corner-name {
    position: sticky; left: <?= RM_LANE_W ?>px; z-index: 6;
    background: #fff; border-right: 2px solid #aab5c4; border-bottom: 1px solid #eef1f6;
}

.rm-q {
    background: #fff; border-right: 2px solid #aab5c4; border-bottom: 1px solid #eef1f6;
    padding: .45rem .5rem; text-align: center; font-size: .78rem; font-weight: 700; color: #94a3b8;
    white-space: nowrap; overflow: hidden;
}
.rm-q.is-now { color: var(--rm-blue); }
/* Not scoped to a parent class — the badge sits in the quarter-group row at
   week/month zoom but in the per-column labels row at quarter zoom. */
.rm-now-tag {
    display: inline-block; margin-left: 6px; background: var(--rm-blue); color: #fff;
    font-size: .62rem; font-weight: 700; padding: 1px 7px; border-radius: 999px; vertical-align: middle;
}
/* Inverted only where the cell itself is blue-filled (.rm-m.is-now). On
   .rm-q.is-now the background stays white, so the badge keeps its blue fill. */
.rm-m.is-now > .rm-now-tag { background: #fff; color: var(--rm-blue); }

.rm-m {
    background: #fff; border-right: 2px solid #aab5c4; border-bottom: 1px solid #e2e8f0;
    padding: .5rem .4rem; text-align: center; font-size: 1.05rem; font-weight: 700; color: #1e293b;
    white-space: nowrap; overflow: hidden;
}
.rm-m.is-now { background: var(--rm-blue); color: #fff; }

.rm-w {
    background: #fff; border-right: 1px solid #dbe1e9; border-bottom: 1px solid #e2e8f0;
    padding: .25rem .1rem; text-align: center; font-size: .62rem; font-weight: 600; color: #b6bfcc;
    white-space: nowrap; overflow: hidden;
}
.rm-w.is-month-end { border-right: 2px solid #aab5c4; }
.rm-w.is-now { color: #92400e; font-weight: 700; background: #fef9c3; }

/* The label column is pinned while the timeline scrolls horizontally, so the
   lane you're looking at is always named — you can't lose your place. */
.rm-lane {
    position: sticky; left: 0; z-index: 5;
    border-right: 1px solid #cbd5e1;
    padding: .7rem .9rem; font-size: .8rem; font-weight: 700; color: #334155;
    display: flex; align-items: flex-start;
}
/* No per-row horizontal lines inside a lane: a lane with three tasks should
   read as one band, not three spreadsheet cells. Only the light vertical
   time lines remain, plus a divider between lanes. */
/* Week lines are dark enough to read individually; the month boundary stays
   a step heavier and darker again, so the hierarchy is still obvious. */
.rm-slot { border-right: 1px solid #dbe1e9; min-height: 40px; }
.rm-slot.is-month-end { border-right: 2px solid #aab5c4; }
/* Current column highlight, applied as a background-IMAGE so it tints over
   the lane's background-color instead of replacing it — one element can't
   hold two background-colors, which is why the lane colour won before.
   Yellow so it can't be confused with the blue In Progress lane. */
.rm-slot.is-now { background-image: linear-gradient(rgba(250,204,21,.22), rgba(250,204,21,.22)); }

/* Each lane is colour-coded, so its rows are identifiable on their own. */
.lane-in_progress { background: #f4f8ff; }
.lane-backlog     { background: #fafbfc; }
.lane-done        { background: #f5fcf7; }
.rm-lane.lane-in_progress { box-shadow: inset 4px 0 0 #60a5fa; }
.rm-lane.lane-backlog     { box-shadow: inset 4px 0 0 #cbd5e1; }
.rm-lane.lane-done        { box-shadow: inset 4px 0 0 #4ade80; }
.is-lane-start { border-top: 2px solid #e2e8f0; }

/* The Today marker is a real grid cell placed in the current week's column
   and spanning every row, with the line positioned inside it as a percentage
   of THAT column — so it stays put even though columns differ in width. */
.rm-today-col { position: relative; z-index: 4; pointer-events: none; }
.rm-today {
    position: absolute; top: 0; bottom: 0; width: 2px; background: #dc2626;
}
.rm-today::before {
    content: 'Today'; position: absolute; top: 2px; left: 50%; transform: translateX(-50%);
    background: #dc2626; color: #fff; font-size: .58rem; font-weight: 700;
    padding: 0 5px; border-radius: 3px; white-space: nowrap; line-height: 1.5;
}

/* A pill fills exactly the week columns its date range covers — one column
   for a same-week task, several only when start/due are in different weeks.
   It is NOT clipped: its content contributes to the column's width, so a
   week column widens to fit its pill (see minmax(...,auto) on the grid).
   The title is capped so one long name can't blow out a column. */
/* The bar is sized purely by its dates, so it can be only a few pixels wide
   and can sit anywhere on the axis — including hard against the right edge.
   That is why the name is NOT attached to the bar: a label anchored to the
   bar (the old left:100% approach) runs off the end of the window for
   anything scheduled late, taking its action buttons out of reach with it.
   The name lives in its own frozen column instead. */
.rm-chip {
    position: relative; justify-self: stretch; align-self: center; z-index: 2;
    box-sizing: border-box; min-width: 6px; height: 18px; border-radius: 4px;
    cursor: pointer; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.12);
}
.rm-chip:hover { z-index: 3; box-shadow: 0 2px 6px rgba(0,0,0,.22); }
/* ══ Progress fill ══
   A bar is filled to the share of its goals that are done; the rest is the
   same colour hatched, so an unstarted task still reads as a bar rather than
   as a gap in the row. The hatch and the fill are both built from the task's
   own colour inline, so an overdue bar hatches red like it fills red.
   The fill only rounds its LEFT corners — the chip clips the right ones for
   it, so a 100% fill still comes out fully rounded without a second rule. */
.rm-chip-fill {
    position: absolute; top: 0; bottom: 0; left: 0;
    border-radius: 4px 0 0 4px;
}
.rm-chip.is-done { opacity: .5; }
/* ══ The frozen task-name column ══
   Sticky at exactly the lane column's width, so the two panes stay locked
   together while the time axis scrolls underneath. It needs an opaque
   background (it inherits the lane's) or bars show through it. */
.rm-name {
    position: sticky; left: <?= RM_LANE_W ?>px; z-index: 5;
    border-right: 2px solid #aab5c4;
    padding: .45rem .6rem; cursor: pointer;
    display: flex; align-items: center; gap: .4rem; min-width: 0;
}
.rm-name:hover { filter: brightness(.97); }
.rm-name-title {
    flex: 1; min-width: 0; font-size: .78rem; font-weight: 600; color: #334155;
    line-height: 1.25;
    /* Capped at two lines: a long name stays readable without letting one
       task make its row twice as tall as the rest. */
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden;
}
.rm-name-title.is-done { color: #94a3b8; text-decoration: line-through; }
.rm-name-actions { display: flex; gap: 3px; opacity: 0; transition: opacity .15s ease; flex-shrink: 0; }
.rm-name:hover .rm-name-actions { opacity: 1; }
.rm-chip-actions { display: flex; gap: 3px; opacity: 0; transition: opacity .15s ease; }
.rm-chip:hover .rm-chip-actions { opacity: 1; }
.rm-chip-btn {
    border: none; background: rgba(0,0,0,.07); width: 18px; height: 18px; border-radius: 4px;
    font-size: .6rem; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
}

/* ══ Cards (Reports / Ideas / Archived) ══ */
.rm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.rm-card {
    background: #fff; border-radius: 12px; border-left: 5px solid #ccc;
    box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 1.1rem 1.2rem;
    transition: transform .15s ease, box-shadow .15s ease;
    display: flex; flex-direction: column; gap: .55rem;
}
.rm-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,.1); }
.rm-card-top { display: flex; justify-content: space-between; align-items: flex-start; }
.rm-card-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s ease; }
.rm-card:hover .rm-card-actions { opacity: 1; }
.rm-icon-btn {
    border: none; background: #f1f5f9; color: #475569; width: 26px; height: 26px; border-radius: 7px;
    font-size: .72rem; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
}
.rm-icon-btn:hover { background: #e2e8f0; }
.rm-icon-btn.rm-icon-success:hover { background: #dcfce7; color: #16a34a; }
.rm-icon-btn.rm-icon-danger:hover { background: #fee2e2; color: #dc2626; }
.rm-card-title { font-weight: 700; font-size: 1.02rem; color: #1e293b; line-height: 1.3; }
.rm-card-desc { font-size: .85rem; color: #64748b; line-height: 1.4; }
.rm-card-bottom { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-top: auto; padding-top: .3rem; }
.rm-card-meta { font-size: .74rem; color: #94a3b8; }
.rm-card-progress { height: 4px; background: rgba(0,0,0,.08); border-radius: 2px; overflow: hidden; }
.rm-card-progress-fill { height: 100%; background: var(--rm-blue); }
.rm-pill { display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }
.rm-promote-btn { border: 1px solid var(--rm-blue); background: transparent; color: var(--rm-blue); font-size: .74rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; cursor: pointer; }
.rm-promote-btn:hover { background: var(--rm-blue); color: #fff; }
/* Goal count on a task, and the way through to the task's own page. */
.rm-goal-badge {
    display: inline-flex; align-items: center; gap: 4px; text-decoration: none;
    background: #eef2ff; color: #4338ca; font-size: .66rem; font-weight: 700;
    padding: 1px 7px; border-radius: 999px; flex-shrink: 0;
}
.rm-goal-badge:hover { background: #e0e7ff; color: #312e81; }
/* The chip and card action buttons are <button>s; these same-looking controls
   are <a>s, so they need the anchor defaults knocked back out. */
a.rm-chip-btn, a.rm-icon-btn { color: #334155; text-decoration: none; }

/* Initials avatar. Sized to sit inside an 18px-tall timeline bar label (sm),
   or beside a card's priority pill (base). */
.rm-avatar {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 20px; height: 20px; border-radius: 50%; line-height: 1;
    color: #fff; font-size: .62rem; font-weight: 700;
}
.rm-avatar-sm { width: 16px; height: 16px; font-size: .55rem; }

/* Tabs and the assignee filter share one row. */
.rm-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.rm-toolbar .rm-tabs { margin-bottom: 0; }
.rm-who { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.rm-who-label { font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; margin-right: .2rem; }
.rm-who-btn {
    display: inline-flex; align-items: center; gap: .35rem; text-decoration: none;
    font-size: .78rem; font-weight: 600; color: #64748b;
    background: #eef1f6; padding: .2rem .7rem .2rem .25rem; border-radius: 999px; transition: all .15s ease;
}
.rm-who-btn.is-plain { padding-left: .8rem; }
.rm-who-btn:hover { color: #1e293b; }
.rm-who-btn.active { background: var(--rm-blue); color: #fff; }
.rm-who-btn.active .rm-avatar { box-shadow: 0 0 0 2px rgba(255,255,255,.75); }

.rm-empty { text-align: center; padding: 4rem 1rem; color: #94a3b8; background: #fff; border-radius: 12px; }
.rm-empty i { font-size: 2.5rem; opacity: .25; display: block; margin-bottom: 1rem; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="rm-header">
        <h4 class="rm-title"><i class="fas fa-road"></i>Roadmap</h4>
        <div class="rm-add-btns d-flex gap-2">
            <button class="btn btn-outline-primary" onclick="openAddModal('idea')"><i class="fas fa-lightbulb me-1"></i>Add Idea</button>
            <button class="btn btn-primary" onclick="openAddModal('task')"><i class="fas fa-plus me-1"></i>Add Task</button>
        </div>
    </div>

    <div class="rm-toolbar">
        <div class="rm-tabs" data-group="main">
            <button class="rm-tab active" data-pane="pane-timeline">Timeline <span class="count"><?= count($timelineTasks) ?></span></button>
            <button class="rm-tab" data-pane="pane-reports">Reports <span class="count"><?= count($reportToday) ?></span></button>
            <button class="rm-tab" data-pane="pane-ideas">Ideas <span class="count"><?= count($ideas) ?></span></button>
            <button class="rm-tab" data-pane="pane-archived">Archived <span class="count"><?= count($archived) ?></span></button>
        </div>

        <?php /* Filters every tab at once - it is applied to $items up front. */ ?>
        <div class="rm-who">
            <span class="rm-who-label">Assignee</span>
            <a class="rm-who-btn is-plain <?= $who === 'all' ? 'active' : '' ?>" href="<?= $rmUrl(['who' => 'all']) ?>">Everyone</a>
            <?php foreach ($users as $u): $uid = (int)$u['user_id']; ?>
            <a class="rm-who-btn <?= $who === (string)$uid ? 'active' : '' ?>" href="<?= $rmUrl(['who' => $uid]) ?>">
                <?= rmAvatar($uid, $userById) ?><?= htmlspecialchars(explode(' ', $u['full_name'])[0]) ?>
            </a>
            <?php endforeach; ?>
            <a class="rm-who-btn is-plain <?= $who === 'none' ? 'active' : '' ?>" href="<?= $rmUrl(['who' => 'none']) ?>">Unassigned</a>
        </div>
    </div>

    <!-- ══ Timeline ══ -->
    <div class="rm-pane active" data-group="main" id="pane-timeline">
        <div class="rm-zoom">
            <span class="rm-zoom-label">Zoom</span>
            <?php foreach (['week' => 'Weeks', 'month' => 'Months', 'quarter' => 'Quarters'] as $z => $zLabel): ?>
            <a class="rm-zoom-btn <?= $zoom === $z ? 'active' : '' ?>" href="<?= $rmUrl(['zoom' => $z]) ?>"><?= $zLabel ?></a>
            <?php endforeach; ?>
        </div>
        <div class="rm-timeline-wrap" data-scroll-left="<?= $scrollLeftPx ?>">
            <?php /* Fixed column width, so the time axis is linear and a bar's width can
                     be proportional to its dates. Content-sized columns would break
                     that (a column's width would depend on its pill's text). */ ?>
            <div class="rm-timeline-grid" style="grid-template-columns: <?= RM_LANE_W ?>px <?= RM_NAME_W ?>px repeat(<?= $numCols ?>, <?= $colW ?>px); grid-template-rows: repeat(<?= $totalGridRows ?>, auto); width: <?= RM_LANE_W + RM_NAME_W + $numCols * $colW ?>px; min-width: 100%">

                <div class="rm-today-col" style="grid-column: <?= 3 + $curColIdx ?>; grid-row: 1 / -1">
                    <div class="rm-today" style="left: <?= $todayDayPct ?>%"></div>
                </div>

                <?php /* Every cell below gets an EXPLICIT grid-column. Auto-placement
                         would skip over the Today column (it spans every row), pushing
                         each following header one column to the right. */ ?>

                <?php foreach ($headerRows as $hri => $hr): $rowNum = $hri + 1; ?>
                <div class="rm-corner" style="grid-row:<?= $rowNum ?>;grid-column:1"><?= htmlspecialchars($hr['corner']) ?></div>
                <div class="rm-corner-name" style="grid-row:<?= $rowNum ?>;grid-column:2"></div>
                <?php if ($hr['kind'] === 'labels'): ?>
                    <?php foreach ($cols as $ci => $c): ?>
                    <div class="<?= $labelRowClass ?> <?= $c['isCurrent'] ? 'is-now' : '' ?> <?= $c['isHeavy'] ? 'is-month-end' : '' ?>" style="grid-row:<?= $rowNum ?>;grid-column:<?= 3 + $ci ?>"><?= htmlspecialchars($c['label']) ?><?php if ($zoom === 'quarter' && $c['qIdx'] === 0): ?><span class="rm-now-tag">NOW</span><?php endif; ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($hr['groups'] as $g):
                        $isNowGroup = $hr['kind'] === 'q' ? $g['val'] === 0 : $months[$g['val']]['isCurrent'];
                        $gLabel     = $hr['kind'] === 'q' ? $quarterLabels[$g['val']] : $months[$g['val']]['label'];
                    ?>
                    <div class="<?= $hr['kind'] === 'q' ? 'rm-q' : 'rm-m' ?> <?= $isNowGroup ? 'is-now' : '' ?>" style="grid-row:<?= $rowNum ?>;grid-column:<?= $g['startCol'] ?> / span <?= $g['span'] ?>"><?= htmlspecialchars($gLabel) ?><?php if ($hr['kind'] === 'q' && $g['val'] === 0): ?><span class="rm-now-tag">NOW</span><?php endif; ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php endforeach; ?>

                <!-- Body: one row per task, one empty slot per column per row -->
                <?php $laneNo = 0; foreach ($laneRowInfo as $laneKey => $info): $laneNo++; ?>
                <div class="rm-lane lane-<?= $laneKey ?> <?= $laneNo > 1 ? 'is-lane-start' : '' ?>" style="grid-column:1;grid-row:<?= $info['startRow'] ?> / span <?= $info['rowCount'] ?>"><?= htmlspecialchars($lanes[$laneKey]) ?></div>
                <?php /* The task-name column. One cell per row, so a name can never
                         collide with a bar, run off the right edge, or take its
                         action buttons out of reach - which is exactly what the old
                         floating labels did. */ ?>
                <?php foreach ($info['tasks'] as $i => $r):
                    $isLate = $r['status'] !== 'done' && $r['due_date'] !== null && $r['due_date'] < $today;
                ?>
                <div class="rm-name lane-<?= $laneKey ?> <?= ($i === 0 && $laneNo > 1) ? 'is-lane-start' : '' ?>"
                     style="grid-column:2;grid-row:<?= $info['startRow'] + $i ?>"
                     onclick='openEditModal(<?= json_encode($r) ?>)'>
                    <?= rmAvatar($r['assigned_to'], $userById, 'rm-avatar-sm') ?>
                    <span class="rm-name-title <?= $laneKey === 'done' ? 'is-done' : '' ?>" title="<?= htmlspecialchars($r['title']) ?>">
                        <?php if ($isLate): ?><i class="fas fa-clock" style="font-size:.6rem;color:#dc2626"></i> <?php endif; ?>
                        <?= htmlspecialchars($r['title']) ?>
                    </span>
                    <?= rmGoalBadge($goalCounts, $r['item_id']) ?>
                    <span class="rm-name-actions">
                        <a class="rm-chip-btn" title="Open" href="item.php?id=<?= (int)$r['item_id'] ?>" onclick="event.stopPropagation()"><i class="fas fa-up-right-from-square"></i></a>
                        <?php if ($r['status'] !== 'done'): ?>
                        <button class="rm-chip-btn" title="Mark Done" onclick='event.stopPropagation(); markDone(<?= json_encode($r) ?>)'><i class="fas fa-check"></i></button>
                        <?php endif; ?>
                        <button class="rm-chip-btn" title="Delete" onclick='event.stopPropagation(); confirmDelete(<?= $r['item_id'] ?>, <?= htmlspecialchars(json_encode($r['title'])) ?>)'><i class="fas fa-trash-can"></i></button>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($info['tasks'])): ?>
                <div class="rm-name lane-<?= $laneKey ?> <?= $laneNo > 1 ? 'is-lane-start' : '' ?>" style="grid-column:2;grid-row:<?= $info['startRow'] ?>"></div>
                <?php endif; ?>

                <?php for ($ri = 0; $ri < $info['rowCount']; $ri++): ?>
                    <?php foreach ($cols as $ci => $c): ?>
                    <div class="rm-slot lane-<?= $laneKey ?> <?= $c['isCurrent'] ? 'is-now' : '' ?> <?= $c['isHeavy'] ? 'is-month-end' : '' ?> <?= ($ri === 0 && $laneNo > 1) ? 'is-lane-start' : '' ?>" style="grid-column:<?= 3 + $ci ?>;grid-row:<?= $info['startRow'] + $ri ?>"></div>
                    <?php endforeach; ?>
                <?php endfor; ?>

                <?php foreach ($info['tasks'] as $i => $r):
                    $c        = chipColor($r, $today, $priorityColor);
                    $isLate   = $r['status'] !== 'done' && $r['due_date'] !== null && $r['due_date'] < $today;
                    $colStart = 3 + $r['_startCol'];
                    $colEnd   = 4 + $r['_endCol'];
                    // The bar spans the columns its date range touches, then
                    // the margins trim it back to the exact start and due
                    // dates within the first and last of those columns.
                    $style = "grid-column:{$colStart} / {$colEnd};grid-row:" . ($info['startRow'] + $i)
                           . ";margin-left:{$r['_leadPx']}px;margin-right:{$r['_trailPx']}px";
                    $dates = $r['start_date'] && $r['start_date'] !== $r['due_date']
                        ? date('M j', strtotime($r['start_date'])) . ' – ' . date('M j', strtotime($r['due_date']))
                        : date('M j', strtotime($r['due_date']));

                    // Untracked tasks stay solid; the rest are hatched behind a
                    // fill sized to their finished goals. The 8-digit hex adds
                    // alpha to the task's own colour, so both layers stay on it.
                    $pct   = rmGoalProgress($goalCounts, $r);
                    $inner = '';
                    if ($pct === null) {
                        $style .= ";background:{$c}";
                    } else {
                        $style .= ";background-color:{$c}1f"
                                . ";background-image:repeating-linear-gradient(45deg,{$c}59 0 3px,transparent 3px 7px)";
                        $inner  = '<div class="rm-chip-fill" style="width:' . $pct . '%;background:' . $c . '"></div>';
                        $gc     = $goalCounts[(int)$r['item_id']] ?? null;
                        $dates .= $gc ? " — {$gc['done']}/{$gc['total']} goals done" : ' — done';
                    }
                ?>
                <div class="rm-chip <?= $laneKey === 'done' ? 'is-done' : '' ?>" style="<?= $style ?>" title="<?= htmlspecialchars($r['title']) ?> — <?= $dates ?>" onclick='openEditModal(<?= json_encode($r) ?>)'><?= $inner ?></div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ══ Reports ══ -->
    <div class="rm-pane" data-group="main" id="pane-reports">
        <div class="rm-tabs" data-group="report">
            <button class="rm-tab active" data-pane="pane-report-today">Today <span class="count"><?= count($reportToday) ?></span></button>
            <button class="rm-tab" data-pane="pane-report-week">This Week <span class="count"><?= count($reportWeek) ?></span></button>
        </div>
        <div class="rm-pane active" data-group="report" id="pane-report-today">
            <?php if (empty($reportToday)): ?>
            <div class="rm-empty"><i class="fas fa-mug-hot"></i><p>Nothing due today — you're clear.</p></div>
            <?php else: ?>
            <div class="rm-grid"><?php foreach ($reportToday as $r): ?><?= renderReportCard($r, $today, $priorityColor, $progressPct, $userById, $goalCounts) ?><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
        <div class="rm-pane" data-group="report" id="pane-report-week">
            <?php if (empty($reportWeek)): ?>
            <div class="rm-empty"><i class="fas fa-calendar-week"></i><p>Nothing due this week.</p></div>
            <?php else: ?>
            <div class="rm-grid"><?php foreach ($reportWeek as $r): ?><?= renderReportCard($r, $today, $priorityColor, $progressPct, $userById, $goalCounts) ?><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ Ideas ══ -->
    <div class="rm-pane" data-group="main" id="pane-ideas">
        <?php if (empty($ideas)): ?>
        <div class="rm-empty"><i class="fas fa-lightbulb"></i><p>No ideas yet. Click Add Idea to get started.</p></div>
        <?php else: ?>
        <div class="rm-grid">
            <?php foreach ($ideas as $r): $pColor = $priorityColor[$r['priority']]; $pct = $progressPct[$r['status']]; ?>
            <div class="rm-card" style="border-left-color: <?= $pColor ?>">
                <div class="rm-card-top">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rm-pill" style="background:<?= $pColor ?>1a;color:<?= $pColor ?>"><?= ucfirst($r['priority']) ?></span>
                        <?= rmAvatar($r['assigned_to'], $userById) ?>
                        <?= rmGoalBadge($goalCounts, $r['item_id']) ?>
                    </div>
                    <div class="rm-card-actions">
                        <a class="rm-icon-btn" title="Open" href="item.php?id=<?= (int)$r['item_id'] ?>"><i class="fas fa-up-right-from-square"></i></a>
                        <button class="rm-icon-btn" title="Edit" onclick='openEditModal(<?= json_encode($r) ?>)'><i class="fas fa-pencil"></i></button>
                        <button class="rm-icon-btn rm-icon-danger" title="Delete" onclick="confirmDelete(<?= $r['item_id'] ?>, <?= htmlspecialchars(json_encode($r['title'])) ?>)"><i class="fas fa-trash-can"></i></button>
                    </div>
                </div>
                <div class="rm-card-title"><?= htmlspecialchars($r['title']) ?></div>
                <?php if ($r['description']): ?><div class="rm-card-desc"><?= htmlspecialchars($r['description']) ?></div><?php endif; ?>
                <div class="rm-card-progress"><div class="rm-card-progress-fill" style="width:<?= $pct ?>%"></div></div>
                <div class="rm-card-bottom">
                    <button class="rm-promote-btn" onclick='promoteToTask(<?= json_encode($r) ?>)'><i class="fas fa-arrow-right"></i> Promote to Task</button>
                </div>
                <?php if ($r['created_by']): ?><div class="rm-card-meta">Added by <?= htmlspecialchars($r['created_by']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ Archived ══ -->
    <div class="rm-pane" data-group="main" id="pane-archived">
        <?php if (empty($archived)): ?>
        <div class="rm-empty"><i class="fas fa-box-archive"></i><p>Nothing archived.</p></div>
        <?php else: ?>
        <div class="rm-grid">
            <?php foreach ($archived as $r): $pColor = $priorityColor[$r['priority']]; ?>
            <div class="rm-card" style="border-left-color: <?= $pColor ?>; opacity: .7">
                <div class="rm-card-top">
                    <div class="d-flex align-items-center gap-1">
                        <span class="rm-pill" style="background:#e2e8f0;color:#475569"><?= $r['type'] === 'idea' ? 'Idea' : 'Task' ?></span>
                        <span class="rm-pill" style="background:<?= $pColor ?>1a;color:<?= $pColor ?>"><?= ucfirst($r['priority']) ?></span>
                        <?= rmAvatar($r['assigned_to'], $userById, 'ms-1') ?>
                    </div>
                    <div class="rm-card-actions">
                        <button class="rm-icon-btn" title="Edit" onclick='openEditModal(<?= json_encode($r) ?>)'><i class="fas fa-pencil"></i></button>
                        <button class="rm-icon-btn rm-icon-danger" title="Delete" onclick="confirmDelete(<?= $r['item_id'] ?>, <?= htmlspecialchars(json_encode($r['title'])) ?>)"><i class="fas fa-trash-can"></i></button>
                    </div>
                </div>
                <div class="rm-card-title"><?= htmlspecialchars($r['title']) ?></div>
                <?php if ($r['description']): ?><div class="rm-card-desc"><?= htmlspecialchars($r['description']) ?></div><?php endif; ?>
                <?php if ($r['due_date']): ?><div class="rm-card-meta">Was due <?= date('M j, Y', strtotime($r['due_date'])) ?></div><?php endif; ?>
                <div class="rm-card-bottom">
                    <button class="rm-promote-btn" onclick='unarchive(<?= json_encode($r) ?>)'><i class="fas fa-box-open"></i> Unarchive</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Add/Edit Modal ══ -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 14px; border: none;">
            <div class="modal-header">
                <h5 class="modal-title" id="itemModalTitle">Add Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editItemId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="itemType" onchange="onTypeChange()">
                        <option value="idea">Idea</option>
                        <option value="task">Task</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="itemTitle" placeholder="e.g. Roadmap module for ForgeFront">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea class="form-control" id="itemDescription" rows="3" placeholder="Optional details"></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Priority</label>
                        <select class="form-select" id="itemPriority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" id="itemStatus">
                            <option value="backlog">Backlog</option>
                            <option value="in_progress">In Progress</option>
                            <option value="done">Done</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Assignee</label>
                    <select class="form-select" id="itemAssignedTo">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3 mb-3" id="dueDateGroup">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="itemStartDate">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="itemDueDate">
                    </div>
                </div>
                <div id="itemModalError" class="text-danger small" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="saveItemBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Delete Modal ══ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 14px; border: none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2"><p id="deleteMsg" class="mb-0"></p></div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="deleteConfirmBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingDeleteId = null;

document.querySelectorAll('.rm-tabs').forEach(group => {
    const groupName = group.dataset.group;
    group.querySelectorAll(':scope > .rm-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            group.querySelectorAll(':scope > .rm-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.querySelectorAll(`.rm-pane[data-group="${groupName}"]`).forEach(p => p.classList.remove('active'));
            document.getElementById(tab.dataset.pane).classList.add('active');
        });
    });
});

// Open the timeline at the start of the current month (offset precomputed
// server-side, since the columns are a fixed width) rather than at the far
// left of the window.
(function scrollTimelineToCurrentMonth() {
    const wrap = document.querySelector('.rm-timeline-wrap');
    if (!wrap) return;
    wrap.scrollLeft = parseInt(wrap.dataset.scrollLeft, 10) || 0;
})();

function onTypeChange() {
    const isTask = document.getElementById('itemType').value === 'task';
    document.getElementById('dueDateGroup').style.display = isTask ? 'flex' : 'none';
}

function openAddModal(type) {
    document.getElementById('itemModalTitle').textContent = type === 'task' ? 'Add Task' : 'Add Idea';
    ['editItemId','itemTitle','itemDescription','itemStartDate','itemDueDate','itemAssignedTo'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('itemType').value = type;
    document.getElementById('itemPriority').value = 'medium';
    document.getElementById('itemStatus').value = 'backlog';
    document.getElementById('itemModalError').style.display = 'none';
    onTypeChange();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('itemModal')).show();
}

function openEditModal(item) {
    document.getElementById('itemModalTitle').textContent = 'Edit ' + (item.type === 'task' ? 'Task' : 'Idea');
    document.getElementById('editItemId').value      = item.item_id;
    document.getElementById('itemType').value        = item.type;
    document.getElementById('itemTitle').value       = item.title;
    document.getElementById('itemDescription').value = item.description ?? '';
    document.getElementById('itemPriority').value    = item.priority;
    document.getElementById('itemStatus').value      = item.status;
    document.getElementById('itemStartDate').value   = item.start_date ?? '';
    document.getElementById('itemDueDate').value     = item.due_date ?? '';
    document.getElementById('itemAssignedTo').value   = item.assigned_to ?? '';
    document.getElementById('itemModalError').style.display = 'none';
    onTypeChange();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('itemModal')).show();
}

function promoteToTask(item) {
    openEditModal({ ...item, type: 'task' });
    document.getElementById('itemModalTitle').textContent = 'Promote to Task';
}

function markDone(item)  { saveItem({ ...item, status: 'done' }); }
function unarchive(item) { saveItem({ ...item, status: 'backlog' }); }

function saveItem(payload) {
    fetch('/roadmap/ajax/save_item.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert(d.error);
    });
}

document.getElementById('saveItemBtn').addEventListener('click', () => {
    const errEl = document.getElementById('itemModalError');
    const type  = document.getElementById('itemType').value;
    const payload = {
        item_id: parseInt(document.getElementById('editItemId').value) || 0,
        type: type,
        title: document.getElementById('itemTitle').value.trim(),
        description: document.getElementById('itemDescription').value.trim(),
        priority: document.getElementById('itemPriority').value,
        status: document.getElementById('itemStatus').value,
        start_date: document.getElementById('itemStartDate').value || null,
        due_date: document.getElementById('itemDueDate').value || null,
        assigned_to: document.getElementById('itemAssignedTo').value || null,
    };
    if (!payload.title) {
        errEl.textContent = 'Title is required.';
        errEl.style.display = 'block'; return;
    }
    if (type === 'task' && (!payload.start_date || !payload.due_date)) {
        errEl.textContent = 'Tasks require both a start date and a due date.';
        errEl.style.display = 'block'; return;
    }
    if (type === 'task' && payload.start_date > payload.due_date) {
        errEl.textContent = 'Start date must be on or before the due date.';
        errEl.style.display = 'block'; return;
    }
    errEl.style.display = 'none';
    fetch('/roadmap/ajax/save_item.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else { errEl.textContent = d.error; errEl.style.display = 'block'; }
    });
});

function confirmDelete(id, title) {
    pendingDeleteId = id;
    document.getElementById('deleteMsg').textContent = `Delete "${title}"? This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
}

document.getElementById('deleteConfirmBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    fetch('/roadmap/ajax/delete_item.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ item_id: pendingDeleteId })
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); });
});

document.getElementById('deleteModal').addEventListener('hidden.bs.modal', () => { pendingDeleteId = null; });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
