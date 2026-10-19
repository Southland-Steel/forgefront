<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pdo = getPDO();

$itemId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM roadmap_items WHERE item_id = ?");
$stmt->execute([$itemId]);
$item = $stmt->fetch();

// Nothing to show without a real item, and a detail page for a deleted task is
// just a dead end — send them back to the board.
if (!$item) {
    header('Location: /roadmap/index.php');
    exit;
}

$users = $pdo->query("
    SELECT user_id, TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) AS full_name
    FROM ff_users
    WHERE is_active = 1
    ORDER BY first_name, last_name
")->fetchAll();
$userById = [];
foreach ($users as $u) $userById[(int)$u['user_id']] = $u['full_name'];

// The goals that describe this task. sort_order first so the list can be
// ordered deliberately rather than by creation accident.
$stmt = $pdo->prepare("SELECT * FROM roadmap_goals WHERE item_id = ? ORDER BY sort_order ASC, goal_id ASC");
$stmt->execute([$itemId]);
$goals = $stmt->fetchAll();

// There is one whiteboard per task, and each goal is a REGION of it (an
// Excalidraw frame). The scene is read once here to work out which goals still
// have a live region and how much is drawn in each — a goal whose frame was
// deleted on the canvas keeps existing, it just has nothing to show.
$stmt = $pdo->prepare("SELECT scene_json, element_count FROM roadmap_boards WHERE item_id = ?");
$stmt->execute([$itemId]);
$boardRow = $stmt->fetch();

$itemBoardCount = $boardRow ? (int)$boardRow['element_count'] : 0;
$frameExists    = [];
$regionCounts   = [];

if ($boardRow && $boardRow['scene_json']) {
    $scene = json_decode($boardRow['scene_json'], true);
    foreach (($scene['elements'] ?? []) as $el) {
        if (!empty($el['isDeleted'])) continue;
        if (($el['type'] ?? '') === 'frame') $frameExists[$el['id'] ?? ''] = true;
        $frameId = $el['frameId'] ?? null;
        if ($frameId !== null) $regionCounts[$frameId] = ($regionCounts[$frameId] ?? 0) + 1;
    }
}

$doneGoals = count(array_filter($goals, fn($g) => $g['status'] === 'done'));
$goalPct   = $goals ? (int)round($doneGoals / count($goals) * 100) : 0;

$today = date('Y-m-d');

$priorityColor = ['low' => '#64748b', 'medium' => '#d97706', 'high' => '#dc2626'];
$statusLabel   = ['backlog' => 'Backlog', 'in_progress' => 'In Progress', 'done' => 'Done', 'archived' => 'Archived'];
$statusColor   = ['backlog' => '#64748b', 'in_progress' => '#2D6BF5', 'done' => '#16a34a', 'archived' => '#94a3b8'];
$goalStatus    = ['open' => 'Open', 'in_progress' => 'In Progress', 'done' => 'Done'];
$goalColor     = ['open' => '#64748b', 'in_progress' => '#4338ca', 'done' => '#16a34a'];

$isLate = $item['status'] !== 'done' && $item['due_date'] !== null && $item['due_date'] < $today;

include __DIR__ . '/../includes/header.php';

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
?>

<style>
:root {
    --rm-blue: #2D6BF5;
    /* Geometry shared by the header and the goal rows, so the two Whiteboard
       buttons can be lined up without hardcoding the offset between them. */
    --rm-head-pad: 1.5rem;   /* .rm-detail-head horizontal padding */
    --rm-row-pad: 1.2rem;    /* .rm-goal-row horizontal padding */
    --rm-row-tail: calc(56px + .8rem); /* the 2 icon buttons + 2 gaps after a goal's button */
}
.rm-avatar {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 20px; height: 20px; border-radius: 50%; line-height: 1;
    color: #fff; font-size: .62rem; font-weight: 700;
}
.rm-back { font-size: .82rem; font-weight: 600; color: #64748b; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; }
.rm-back:hover { color: var(--rm-blue); }
.rm-pill { display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }

.rm-detail-head {
    background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06);
    padding: 1.3rem 1.5rem; margin: .8rem 0 1.5rem;
}
.rm-detail-title {
    font-family: 'Exo 2', sans-serif; font-weight: 700; font-size: 1.7rem; color: #1e293b;
    margin: .5rem 0 .6rem; line-height: 1.2;
}
.rm-detail-meta { display: flex; align-items: center; gap: 1.4rem; flex-wrap: wrap; font-size: .84rem; color: #475569; }
.rm-detail-meta > div { display: flex; align-items: center; gap: .4rem; }
.rm-detail-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; }
.rm-detail-desc { font-size: .92rem; color: #475569; line-height: 1.55; margin-top: .9rem; white-space: pre-wrap; }

/* ══ Goals ══
   A goal describes part of the task and is the thing a whiteboard hangs off,
   so each one is a full-width row with room for its own description rather
   than a card in a grid. */
.rm-goals-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: .8rem; }
.rm-goals-title { font-weight: 700; font-size: 1.15rem; color: #1e293b; display: flex; align-items: center; gap: .5rem; margin: 0; }
.rm-goals-title i { color: #4f46e5; }
.rm-goals-progress { display: flex; align-items: center; gap: .6rem; min-width: 180px; }
.rm-goals-bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; flex-grow: 1; }
.rm-goals-bar-fill { height: 100%; background: linear-gradient(90deg, #6366f1, #4338ca); }
.rm-goals-count { font-size: .74rem; font-weight: 700; color: #4338ca; white-space: nowrap; }

.rm-goal-row {
    background: #fff; border-radius: 12px; border-left: 5px solid #4f46e5;
    box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 1rem 1.2rem; margin-bottom: .7rem;
    display: flex; flex-direction: column; gap: .5rem;
}
.rm-goal-row.is-done { border-left-color: #16a34a; }
.rm-goal-row-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.rm-goal-row-title { font-weight: 700; font-size: 1.02rem; color: #1e293b; line-height: 1.35; }
.rm-goal-row.is-done .rm-goal-row-title { color: #64748b; }
.rm-goal-row-desc { font-size: .87rem; color: #64748b; line-height: 1.5; white-space: pre-wrap; }
.rm-goal-row-actions { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }
.rm-icon-btn {
    border: none; background: #f1f5f9; color: #475569; width: 28px; height: 28px; border-radius: 7px;
    font-size: .74rem; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
    text-decoration: none;
}
.rm-icon-btn:hover { background: #e2e8f0; color: #1e293b; }
.rm-board-btn {
    display: inline-flex; align-items: center; gap: .4rem; text-decoration: none;
    background: #eef2ff; color: #4338ca; border: 1px solid #e0e7ff;
    font-size: .76rem; font-weight: 700; padding: .3rem .7rem; border-radius: 999px; white-space: nowrap;
}
.rm-board-btn:hover { background: #e0e7ff; color: #3730a3; }
/* The task's own Whiteboard button sits in the same vertical line as the
   per-goal ones. A goal row has two icon buttons after its button and slightly
   tighter padding than the header, so the header button is pushed right by
   exactly that difference — computed from the variables above rather than a
   magic number, so adding a row action or changing a padding stays correct. */
.rm-detail-head .rm-board-btn {
    margin-right: calc(var(--rm-row-tail) - (var(--rm-head-pad) - var(--rm-row-pad)));
}
/* A goal with a board already drawn on it reads differently at a glance from
   one with an empty canvas waiting. */
.rm-board-btn.has-board { background: #4f46e5; color: #fff; border-color: #4f46e5; }
.rm-board-btn.has-board:hover { background: #4338ca; color: #fff; }
/* A goal with no region on the board yet — or whose region was deleted. Reads
   as an invitation rather than as a whiteboard that exists. */
.rm-board-btn.is-unlinked { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
.rm-board-btn.is-unlinked:hover { background: #ffedd5; color: #9a3412; }
.rm-board-count {
    background: rgba(255,255,255,.25); border-radius: 999px; padding: 0 6px;
    font-size: .68rem; line-height: 1.5;
}
.rm-icon-btn.rm-icon-danger:hover { background: #fee2e2; color: #dc2626; }
.rm-empty { text-align: center; padding: 3rem 1rem; color: #94a3b8; background: #fff; border-radius: 12px; }
.rm-empty i { font-size: 2.5rem; opacity: .25; display: block; margin-bottom: 1rem; }
</style>

<div class="container-fluid px-4 pt-3" style="max-width: 1100px">

    <a class="rm-back" href="/roadmap/index.php"><i class="fas fa-arrow-left"></i>Back to Roadmap</a>

    <div class="rm-detail-head">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="rm-pill" style="background:#e2e8f0;color:#475569"><?= $item['type'] === 'idea' ? 'Idea' : 'Task' ?></span>
            <span class="rm-pill" style="background:<?= $statusColor[$item['status']] ?>1a;color:<?= $statusColor[$item['status']] ?>"><?= $statusLabel[$item['status']] ?></span>
            <span class="rm-pill" style="background:<?= $priorityColor[$item['priority']] ?>1a;color:<?= $priorityColor[$item['priority']] ?>"><?= ucfirst($item['priority']) ?></span>

            <a class="rm-board-btn ms-auto <?= $itemBoardCount > 0 ? 'has-board' : '' ?>"
               href="/roadmap/board.php?item=<?= (int)$item['item_id'] ?>"
               title="<?= $itemBoardCount > 0
                    ? 'Open this ' . ($item['type'] === 'idea' ? 'idea' : 'task') . '\'s whiteboard (' . $itemBoardCount . ' shape' . ($itemBoardCount === 1 ? '' : 's') . ')'
                    : 'Sketch the whole ' . ($item['type'] === 'idea' ? 'idea' : 'task') . ' — the goals can come out of it' ?>">
                <i class="fas fa-pen-ruler"></i>Whiteboard
                <?php if ($itemBoardCount > 0): ?>
                <span class="rm-board-count"><?= $itemBoardCount ?></span>
                <?php endif; ?>
            </a>
        </div>

        <h1 class="rm-detail-title"><?= htmlspecialchars($item['title']) ?></h1>

        <div class="rm-detail-meta">
            <div>
                <span class="rm-detail-label">Assignee</span>
                <?php if ($item['assigned_to'] !== null && isset($userById[(int)$item['assigned_to']])): ?>
                <?= rmAvatar($item['assigned_to'], $userById) ?>
                <span><?= htmlspecialchars($userById[(int)$item['assigned_to']]) ?></span>
                <?php else: ?>
                <span class="text-muted">Unassigned</span>
                <?php endif; ?>
            </div>
            <?php if ($item['start_date']): ?>
            <div>
                <span class="rm-detail-label">Start</span>
                <span><?= date('M j, Y', strtotime($item['start_date'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($item['due_date']): ?>
            <div>
                <span class="rm-detail-label">Due</span>
                <span class="<?= $isLate ? 'text-danger fw-semibold' : '' ?>">
                    <?= date('M j, Y', strtotime($item['due_date'])) ?><?= $isLate ? ' (overdue)' : '' ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($item['created_by']): ?>
            <div>
                <span class="rm-detail-label">Added by</span>
                <span><?= htmlspecialchars($item['created_by']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($item['description']): ?>
        <div class="rm-detail-desc"><?= htmlspecialchars($item['description']) ?></div>
        <?php endif; ?>
    </div>

    <div class="rm-goals-head">
        <h2 class="rm-goals-title"><i class="fas fa-diagram-project"></i>Goals</h2>
        <?php if ($goals): ?>
        <div class="rm-goals-progress">
            <div class="rm-goals-bar"><div class="rm-goals-bar-fill" style="width:<?= $goalPct ?>%"></div></div>
            <span class="rm-goals-count"><?= $doneGoals ?>/<?= count($goals) ?> done</span>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" style="border-radius:999px;font-weight:600" onclick="openAddGoalModal()">
            <i class="fas fa-plus me-1"></i>Add Goal
        </button>
    </div>

    <?php if (empty($goals)): ?>
    <div class="rm-empty">
        <i class="fas fa-diagram-project"></i>
        <p class="mb-0">No goals yet. A goal describes part of what this <?= $item['type'] === 'idea' ? 'idea' : 'task' ?> has to achieve.</p>
    </div>
    <?php else: ?>
    <?php foreach ($goals as $g): ?>
    <div class="rm-goal-row <?= $g['status'] === 'done' ? 'is-done' : '' ?>">
        <div class="rm-goal-row-top">
            <div>
                <div class="rm-goal-row-title"><?= htmlspecialchars($g['title']) ?></div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="rm-pill" style="background:<?= $goalColor[$g['status']] ?>1a;color:<?= $goalColor[$g['status']] ?>"><?= $goalStatus[$g['status']] ?></span>
                    <?= rmAvatar($g['owner_id'], $userById) ?>
                    <?php if ($g['owner_id'] !== null && isset($userById[(int)$g['owner_id']])): ?>
                    <span style="font-size:.78rem;color:#64748b"><?= htmlspecialchars($userById[(int)$g['owner_id']]) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rm-goal-row-actions">
                <?php
                /* The whiteboard is the point of a goal — mapping out how to
                   actually get there — so it gets a labelled button rather than
                   another anonymous icon. A goal with no live region gets the
                   button that goes and creates one instead. */
                $hasRegion  = $g['frame_id'] !== null && isset($frameExists[$g['frame_id']]);
                $regionSize = $hasRegion ? ($regionCounts[$g['frame_id']] ?? 0) : 0;
                ?>
                <?php if ($hasRegion): ?>
                <a class="rm-board-btn <?= $regionSize > 0 ? 'has-board' : '' ?>"
                   href="/roadmap/board.php?goal=<?= (int)$g['goal_id'] ?>"
                   title="<?= $regionSize > 0
                        ? 'Open this goal\'s region of the board (' . $regionSize . ' shape' . ($regionSize === 1 ? '' : 's') . ')'
                        : 'Open this goal\'s region — nothing drawn in it yet' ?>">
                    <i class="fas fa-pen-ruler"></i>Whiteboard
                    <?php if ($regionSize > 0): ?>
                    <span class="rm-board-count"><?= $regionSize ?></span>
                    <?php endif; ?>
                </a>
                <?php else: ?>
                <a class="rm-board-btn is-unlinked"
                   href="/roadmap/board.php?item=<?= (int)$item['item_id'] ?>&link=<?= (int)$g['goal_id'] ?>"
                   title="<?= $g['frame_id'] !== null
                        ? 'This goal\'s region was deleted from the board — pick a new one'
                        : 'Mark this goal\'s region on the task whiteboard' ?>">
                    <i class="fas fa-crosshairs"></i><?= $g['frame_id'] !== null ? 'Region gone' : 'Add region' ?>
                </a>
                <?php endif; ?>
                <button class="rm-icon-btn" title="Edit" onclick='openEditGoalModal(<?= json_encode($g) ?>)'><i class="fas fa-pencil"></i></button>
                <button class="rm-icon-btn rm-icon-danger" title="Delete" onclick="confirmDeleteGoal(<?= (int)$g['goal_id'] ?>, <?= htmlspecialchars(json_encode($g['title'])) ?>)"><i class="fas fa-trash-can"></i></button>
            </div>
        </div>
        <?php if ($g['description']): ?>
        <div class="rm-goal-row-desc"><?= htmlspecialchars($g['description']) ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ══ Goal Add/Edit Modal ══ -->
<div class="modal fade" id="goalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 14px; border: none;">
            <div class="modal-header">
                <h5 class="modal-title" id="goalModalTitle">Add Goal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editGoalId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Goal <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="goalTitle" placeholder="e.g. Implement SSO through FabTrak on ssf, grid and galv">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea class="form-control" id="goalDescription" rows="3" placeholder="What this goal covers"></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Owner</label>
                        <select class="form-select" id="goalOwnerId">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" id="goalStatus">
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="done">Done</option>
                        </select>
                    </div>
                </div>
                <div id="goalModalError" class="text-danger small" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="saveGoalBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Goal Delete Modal ══ -->
<div class="modal fade" id="deleteGoalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 14px; border: none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Goal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2"><p id="deleteGoalMsg" class="mb-0"></p></div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="deleteGoalConfirmBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
const RM_ITEM_ID = <?= (int)$item['item_id'] ?>;
let pendingDeleteGoalId = null;

function openAddGoalModal() {
    document.getElementById('goalModalTitle').textContent = 'Add Goal';
    ['editGoalId','goalTitle','goalDescription','goalOwnerId'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('goalStatus').value = 'open';
    document.getElementById('goalModalError').style.display = 'none';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('goalModal')).show();
}

function openEditGoalModal(goal) {
    document.getElementById('goalModalTitle').textContent = 'Edit Goal';
    document.getElementById('editGoalId').value      = goal.goal_id;
    document.getElementById('goalTitle').value       = goal.title;
    document.getElementById('goalDescription').value = goal.description ?? '';
    document.getElementById('goalOwnerId').value     = goal.owner_id ?? '';
    document.getElementById('goalStatus').value      = goal.status;
    document.getElementById('goalModalError').style.display = 'none';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('goalModal')).show();
}

document.getElementById('saveGoalBtn').addEventListener('click', () => {
    const errEl = document.getElementById('goalModalError');
    const payload = {
        goal_id: parseInt(document.getElementById('editGoalId').value) || 0,
        item_id: RM_ITEM_ID,
        title: document.getElementById('goalTitle').value.trim(),
        description: document.getElementById('goalDescription').value.trim(),
        owner_id: document.getElementById('goalOwnerId').value || null,
        status: document.getElementById('goalStatus').value,
    };
    if (!payload.title) {
        errEl.textContent = 'A goal needs a title.';
        errEl.style.display = 'block'; return;
    }
    errEl.style.display = 'none';
    fetch('/roadmap/ajax/save_goal.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else { errEl.textContent = d.error; errEl.style.display = 'block'; }
    });
});

function confirmDeleteGoal(id, title) {
    pendingDeleteGoalId = id;
    document.getElementById('deleteGoalMsg').textContent =
        `Delete the goal "${title}"? Its region stays on the task whiteboard — it just stops being a goal.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteGoalModal')).show();
}

document.getElementById('deleteGoalConfirmBtn').addEventListener('click', () => {
    if (!pendingDeleteGoalId) return;
    fetch('/roadmap/ajax/delete_goal.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ goal_id: pendingDeleteGoalId })
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
});

document.getElementById('deleteGoalModal').addEventListener('hidden.bs.modal', () => { pendingDeleteGoalId = null; });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
