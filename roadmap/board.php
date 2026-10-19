<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pdo = getPDO();

// There is ONE canvas per task. ?item= opens the whole thing; ?goal= opens just
// the region (an Excalidraw frame) that a goal occupies, editable, and splices
// the edits back on save. ?link= puts the task board in region-linking mode for
// a goal that doesn't have one yet.
$goalId     = (int)($_GET['goal'] ?? 0);
$itemId     = (int)($_GET['item'] ?? 0);
$linkGoalId = (int)($_GET['link'] ?? 0);

if ($goalId) {
    $stmt = $pdo->prepare("
        SELECT 'goal' AS owner_type, g.goal_id AS owner_id, g.title, g.status, g.item_id, g.frame_id,
               i.title AS item_title
        FROM roadmap_goals g
        JOIN roadmap_items i ON i.item_id = g.item_id
        WHERE g.goal_id = ?
    ");
    $stmt->execute([$goalId]);
} elseif ($itemId) {
    $stmt = $pdo->prepare("
        SELECT 'item' AS owner_type, i.item_id AS owner_id, i.title, i.status, i.item_id, NULL AS frame_id,
               i.title AS item_title
        FROM roadmap_items i
        WHERE i.item_id = ?
    ");
    $stmt->execute([$itemId]);
} else {
    header('Location: /roadmap/index.php');
    exit;
}

$board = $stmt->fetch();

if (!$board) {
    header('Location: /roadmap/index.php');
    exit;
}

$itemId = (int)$board['item_id'];

// The one board this page reads or writes, whichever end it was opened from.
$stmt = $pdo->prepare("SELECT scene_json, updated_by AS board_updated_by, updated_at AS board_updated_at FROM roadmap_boards WHERE item_id = ?");
$stmt->execute([$itemId]);
$boardRow = $stmt->fetch() ?: ['scene_json' => null, 'board_updated_by' => null, 'board_updated_at' => null];
$board['board_updated_by'] = $boardRow['board_updated_by'];
$board['board_updated_at'] = $boardRow['board_updated_at'];

// Which frames actually exist on the board right now. Deleting a frame does
// NOT clear the goals that pointed at it — the goal survives, orphaned, with a
// frame_id aimed at something gone. So "does this goal have a region" has to be
// answered against the scene, not against frame_id being NULL, or an orphaned
// goal becomes invisible: missing from the picker and from the ?link= banner,
// with no way left to give it a new region.
$liveFrames = [];
if ($boardRow['scene_json']) {
    $peek = json_decode($boardRow['scene_json'], true);
    foreach (($peek['elements'] ?? []) as $el) {
        if (($el['type'] ?? '') === 'frame' && empty($el['isDeleted'])) $liveFrames[$el['id'] ?? ''] = true;
    }
}

// One pass splits this task's goals into the ones holding a live region (which
// mark their frame and can't be claimed again) and the ones available to link —
// never linked, or orphaned by a deleted frame.
$frameGoals    = [];
$unlinkedGoals = [];
$stmt = $pdo->prepare("SELECT goal_id, title, frame_id FROM roadmap_goals WHERE item_id = ? ORDER BY sort_order, goal_id");
$stmt->execute([$itemId]);
foreach ($stmt->fetchAll() as $g) {
    if ($g['frame_id'] !== null && isset($liveFrames[$g['frame_id']])) {
        $frameGoals[$g['frame_id']] = ['goal_id' => (int)$g['goal_id'], 'title' => $g['title']];
    } else {
        $unlinkedGoals[] = ['id' => (int)$g['goal_id'], 'title' => $g['title']];
    }
}

// Region-linking mode, using that same availability rule so the button on
// item.php works for an orphaned goal as well as a brand new one.
$linkGoal = null;
if ($linkGoalId && $board['owner_type'] === 'item') {
    foreach ($unlinkedGoals as $g) {
        if ($g['id'] === $linkGoalId) { $linkGoal = ['goal_id' => $g['id'], 'title' => $g['title']]; break; }
    }
}

// ── Build the scene this page will hand to Excalidraw ──────────────────────
$regionMissing = false;

if ($board['owner_type'] === 'item') {
    // Whole canvas: handed over as the stored string, no decode needed.
    $sceneJson = $boardRow['scene_json'] ?: 'null';
} else {
    // One region: the frame, everything that claims it, and any label bound to
    // one of those (bound text is its own element and would be left behind).
    $task = $boardRow['scene_json'] ? json_decode($boardRow['scene_json'], true) : null;
    $frameId = $board['frame_id'];

    if (!$frameId || !is_array($task) || !isset($task['elements'])) {
        $regionMissing = true;
        $sceneJson = 'null';
    } else {
        $picked = [];
        $ids    = [];
        foreach ($task['elements'] as $el) {
            if (($el['id'] ?? '') === $frameId || (($el['frameId'] ?? null) === $frameId)) {
                $picked[] = $el;
                $ids[$el['id'] ?? ''] = true;
            }
        }
        foreach ($task['elements'] as $el) {
            if (isset($el['containerId']) && isset($ids[$el['containerId']]) && empty($ids[$el['id'] ?? ''])) {
                $picked[] = $el;
            }
        }
        // The frame itself being gone means someone deleted the region on the
        // task board. The goal survives that — it just has nothing to show.
        if (empty($ids[$frameId])) {
            $regionMissing = true;
            $sceneJson = 'null';
        } else {
            $sceneJson = json_encode([
                'type' => 'excalidraw', 'version' => 2, 'source' => 'local',
                'elements' => $picked,
                'appState' => (object)['viewBackgroundColor' => $task['appState']['viewBackgroundColor'] ?? '#ffffff'],
                'files'    => isset($task['files']) && $task['files'] ? $task['files'] : new stdClass(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}

// Goals and tasks have different status vocabularies, so the pill is built
// from whichever one owns this board.
$statusLabel = $board['owner_type'] === 'goal'
    ? ['open' => 'Open', 'in_progress' => 'In Progress', 'done' => 'Done']
    : ['backlog' => 'Backlog', 'in_progress' => 'In Progress', 'done' => 'Done', 'archived' => 'Archived'];
$statusColor = $board['owner_type'] === 'goal'
    ? ['open' => '#64748b', 'in_progress' => '#4338ca', 'done' => '#16a34a']
    : ['backlog' => '#64748b', 'in_progress' => '#2D6BF5', 'done' => '#16a34a', 'archived' => '#94a3b8'];

// Excalidraw pins its own asset path for the fonts and locale chunks it
// lazy-loads; the version here has to match the script tag below or those
// fetches 404 silently and the fonts quietly fall back.
const RM_EXCALIDRAW_VER = '0.17.6';

// The stored scene goes into a JSON script block verbatim. Any "</" inside it
// can only be inside a JSON string (no JSON structural character is "<" or
// "/"), so escaping it as "<\/" stays valid JSON and cannot end the block early.
$sceneJson = str_replace('</', '<\/', $sceneJson);

include __DIR__ . '/../includes/header.php';
?>

<style>
:root { --rm-blue: #2D6BF5; }

.rm-board-bar {
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    padding: .7rem 1.1rem; margin: .4rem .5rem .5rem;
    background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.rm-board-heading { min-width: 0; flex-grow: 1; }
.rm-board-crumb { font-size: .74rem; color: #94a3b8; font-weight: 600; display: flex; align-items: center; gap: .4rem; }
.rm-board-crumb a { color: #64748b; text-decoration: none; }
.rm-board-crumb a:hover { color: var(--rm-blue); text-decoration: underline; }
.rm-board-title {
    font-family: 'Exo 2', sans-serif; font-weight: 700; font-size: 1.18rem; color: #1e293b;
    margin: .15rem 0 0; line-height: 1.25;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.rm-pill { display: inline-flex; align-items: center; gap: 4px; font-size: .7rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }
.rm-board-actions { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }

/* The save state is the only feedback that autosave is working, so it gets a
   fixed width — a label that changes width jitters the toolbar beside it. */
.rm-save-state { font-size: .78rem; font-weight: 600; color: #94a3b8; min-width: 132px; text-align: right; }
.rm-save-state.is-dirty { color: #d97706; }
.rm-save-state.is-saved { color: #16a34a; }
.rm-save-state.is-error { color: #dc2626; }

/* Excalidraw fills its container absolutely, so the stage needs a real height
   of its own or the canvas collapses to nothing. */
.rm-board-stage {
    position: relative; margin: 0 .5rem .5rem;
    height: calc(100vh - 232px); min-height: 440px;
    background: #fff; border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    display: flex;
}
#boardCanvas { flex: 1 1 auto; min-width: 0; height: 100%; position: relative; }

/* ══ Layers panel ══
   Docked BESIDE the canvas rather than floating over it: Excalidraw owns all
   four corners of its own canvas, and anything overlaid on them collides (the
   full-screen exit button did exactly that). Taking width instead means
   Excalidraw just resizes — it watches its container. */
.rm-dock {
    flex: 0 0 272px; display: none; flex-direction: column;
    border-left: 1px solid #e2e8f0; background: #fbfcfe; min-height: 0;
}
/* The dock opens for EITHER of its two sections — Layers on demand, the frame
   panel on its own whenever a frame is in play. */
body.rm-dock-open .rm-dock { display: flex; }
.rm-layers-body { display: none; flex-direction: column; min-height: 0; flex: 1 1 auto; }
body.rm-layers-open .rm-layers-body { display: flex; }

/* ── Frame → goal, injected into Excalidraw's own left properties island ──
   Styled off Excalidraw's CSS variables rather than this module's palette so
   it reads as one more property of the frame, and follows the canvas theme. */
.rm-goal-panel { display: flex; flex-direction: column; row-gap: .4rem; }
.rm-goal-panel legend,
.rm-goal-panel .rm-goal-legend {
    font-size: .75rem; font-weight: 400; color: var(--color-on-surface, #1b1b1f);
    margin: 0; padding: 0; opacity: .7;
}
.rm-goal-panel .rm-goal-row { display: flex; align-items: center; gap: 4px; }
.rm-goal-panel input {
    flex: 1; min-width: 0; height: 2rem; padding: 0 .5rem;
    font-size: .8rem; font-family: inherit; color: var(--color-on-surface, #1b1b1f);
    background: var(--input-bg-color, #fff);
    border: 1px solid var(--input-border-color, #d6d4d4);
    border-radius: var(--border-radius-lg, 8px);
}
.rm-goal-panel input:focus { outline: none; border-color: var(--color-primary, #6965db); }
.rm-goal-panel input::placeholder { color: var(--color-on-surface, #1b1b1f); opacity: .45; }
.rm-goal-panel .rm-goal-go {
    flex-shrink: 0; width: 2rem; height: 2rem; border: none; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--border-radius-lg, 8px); font-size: .75rem;
    background: var(--color-primary, #6965db); color: #fff;
}
.rm-goal-panel .rm-goal-go:hover { filter: brightness(1.08); }
.rm-goal-panel .rm-goal-note { font-size: .68rem; line-height: 1.4; opacity: .6; color: var(--color-on-surface, #1b1b1f); }
.rm-goal-panel .rm-goal-note.is-error { color: #d93b3b; opacity: 1; }
.rm-goal-panel .rm-goal-note a { color: var(--color-primary, #6965db); }
.rm-layers-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .55rem .75rem; border-bottom: 1px solid #e8edf3; flex-shrink: 0;
}
.rm-layers-head span { font-size: .68rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; }
.rm-layers-hint { padding: .5rem .75rem; font-size: .68rem; color: #a9b4c2; border-bottom: 1px solid #e8edf3; flex-shrink: 0; }
.rm-layers-list { overflow-y: auto; flex-grow: 1; padding: .3rem; min-height: 0; }

.rm-layer {
    display: flex; align-items: center; gap: .45rem;
    padding: .3rem .4rem; border-radius: 7px; cursor: pointer; user-select: none;
}
.rm-layer:hover { background: #eef2f7; }
.rm-layer.is-selected { background: #e0e7ff; }
.rm-layer-icon { width: 15px; text-align: center; color: #8b98a8; font-size: .72rem; flex-shrink: 0; }
.rm-layer.is-selected .rm-layer-icon { color: #4338ca; }
.rm-layer-name {
    flex: 1; min-width: 0; font-size: .76rem; font-weight: 600; color: #334155;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.rm-layer.is-locked .rm-layer-name { color: #94a3b8; font-style: italic; }
/* Reorder buttons appear on hover so a long name keeps the width; the lock is
   always visible because it is state, not an action. */
.rm-layer-btns { display: none; gap: 1px; flex-shrink: 0; }
.rm-layer:hover .rm-layer-btns { display: flex; }
.rm-layer-btn {
    border: none; background: none; color: #7c8899; cursor: pointer;
    width: 18px; height: 18px; padding: 0; border-radius: 4px; font-size: .64rem;
    display: inline-flex; align-items: center; justify-content: center;
}
.rm-layer-btn:hover { background: #cbd5e1; color: #1e293b; }
.rm-layer-lock { color: #b8c2cf; }
.rm-layer-lock.is-on { color: #d97706; }
.rm-layers-empty { padding: 1.6rem .8rem; text-align: center; color: #a9b4c2; font-size: .76rem; }
/* A frame that is already a goal is marked rather than offered again. */
.rm-layer-goal { color: #4338ca; font-size: .6rem; flex-shrink: 0; }
.rm-layer-make {
    border: none; background: #e0e7ff; color: #4338ca; cursor: pointer; flex-shrink: 0;
    font-size: .6rem; font-weight: 700; padding: 2px 6px; border-radius: 999px; white-space: nowrap;
}
.rm-layer-make:hover { background: #c7d2fe; }

.rm-link-banner {
    display: flex; align-items: center; gap: .7rem;
    margin: 0 .5rem .5rem; padding: .6rem .9rem;
    background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 10px;
    font-size: .82rem; color: #3730a3;
}
.rm-link-banner i { font-size: 1rem; color: #4f46e5; }
/* Full screen keeps the app bar pinned at the top rather than covering the
   canvas with a floating exit button — Excalidraw owns all four corners of its
   own canvas (Library sits top-right), and anything floated over them collides.
   Keeping the bar also keeps the save indicator in view, which is the only
   feedback that autosave is working.
   --rm-bar-h is measured in JS: the bar wraps at narrow widths, so its height
   can't be assumed here. */
body.rm-full .rm-board-bar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 1081;
    margin: 0; border-radius: 0; box-shadow: 0 1px 6px rgba(0,0,0,.12);
}
body.rm-full .rm-board-stage {
    position: fixed; left: 0; right: 0; bottom: 0; top: var(--rm-bar-h, 64px);
    margin: 0; height: auto; border-radius: 0; z-index: 1080;
}

.rm-board-loading {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: .8rem; color: #94a3b8; text-align: center;
}
.rm-board-loading i { font-size: 2rem; opacity: .3; }

/* ══ Bootstrap ↔ Excalidraw class collisions ══
   includes/header.php loads Bootstrap on every page, and Bootstrap and
   Excalidraw both define .dropdown-menu and .popover. Comparing every
   ".excalidraw <class>" in the bundle against every class Bootstrap styles
   returns exactly those two — nothing else overlaps.

   .dropdown-menu was a real bug: Bootstrap sets display:none on it (it expects
   its own JS to add .show), and Excalidraw's rule never declares display, so
   the main menu and the More tools menu mounted invisibly. Their keyboard
   shortcuts still worked, which is what gave it away.

   Each rule below re-declares ONLY what Bootstrap adds and Excalidraw does
   not. Do not add position/top/margin-top here (Excalidraw sets those on
   .dropdown-menu) or padding/z-index (it sets those on .popover) — this style
   block comes later in document order than Excalidraw's injected stylesheet,
   so at equal specificity anything declared here silently wins. */
.excalidraw .dropdown-menu {
    display: block;
    min-width: 0;
    padding: 0;
    background: none;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    color: inherit;
    font-size: inherit;
}
.excalidraw .popover {
    max-width: none;
    background: none;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    color: inherit;
    font-size: inherit;
    font-family: inherit;
}
</style>

<div class="rm-board-bar">
    <div class="rm-board-heading">
        <div class="rm-board-crumb">
            <a href="/roadmap/index.php">Roadmap</a>
            <?php if ($board['owner_type'] === 'goal'): ?>
            <i class="fas fa-chevron-right" style="font-size:.6rem"></i>
            <a href="/roadmap/item.php?id=<?= (int)$board['item_id'] ?>"><?= htmlspecialchars($board['item_title']) ?></a>
            <?php else: ?>
            <i class="fas fa-chevron-right" style="font-size:.6rem"></i>
            <span>Task overview</span>
            <?php endif; ?>
        </div>
        <h1 class="rm-board-title">
            <i class="fas fa-pen-ruler me-2" style="color:#4f46e5"></i><?= htmlspecialchars($board['title']) ?>
        </h1>
    </div>

    <span class="rm-pill" style="background:<?= $statusColor[$board['status']] ?>1a;color:<?= $statusColor[$board['status']] ?>"><?= $statusLabel[$board['status']] ?></span>

    <div class="rm-board-actions">
        <span class="rm-save-state" id="saveState"></span>
        <button class="btn btn-primary btn-sm" style="border-radius:999px;font-weight:600" id="saveBtn">
            <i class="fas fa-save me-1"></i>Save
        </button>
        <button class="btn btn-outline-secondary btn-sm" style="border-radius:999px;font-weight:600" id="layersBtn" title="Show the layer stack">
            <i class="fas fa-layer-group me-1"></i>Layers
        </button>
        <button class="btn btn-outline-secondary btn-sm" style="border-radius:999px;font-weight:600" id="fullBtn" title="Expand to full screen">
            <i class="fas fa-expand"></i>
        </button>
        <a class="btn btn-outline-secondary btn-sm" style="border-radius:999px;font-weight:600" href="/roadmap/item.php?id=<?= (int)$board['item_id'] ?>">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<?php if ($linkGoal): ?>
<div class="rm-link-banner">
    <i class="fas fa-crosshairs"></i>
    <div>
        Pick the region for <strong><?= htmlspecialchars($linkGoal['title']) ?></strong>.
        Draw a frame around it (More tools → Frame tool), then press <strong>Make goal</strong> on that frame in the Layers panel.
    </div>
</div>
<?php endif; ?>

<div class="rm-board-stage" id="boardStage">
    <div id="boardCanvas"></div>
    <div class="rm-dock" id="dock">
        <div class="rm-layers-body" id="layersBody">
            <div class="rm-layers-head">
                <span>Layers</span>
                <span id="layersCount"></span>
            </div>
            <div class="rm-layers-hint">Top of this list is the front of the board.</div>
            <div class="rm-layers-list" id="layersList"></div>
        </div>
    </div>
    <div class="rm-board-loading" id="boardLoading">
        <i class="fas fa-pen-ruler"></i>
        <div>Loading the whiteboard…</div>
    </div>
</div>

<?php if ($board['board_updated_at']): ?>
<div class="text-muted small px-3 pb-3" style="margin-left:.2rem">
    Last saved <?= date('M j, Y \a\t g:i A', strtotime($board['board_updated_at'])) ?><?= $board['board_updated_by'] ? ' by ' . htmlspecialchars($board['board_updated_by']) : '' ?>.
</div>
<?php endif; ?>

<script type="application/json" id="sceneData"><?= $sceneJson ?></script>

<script>
// Set before Excalidraw loads: it reads this when lazy-loading its fonts and
// locale chunks, and a missing asset path fails silently.
window.EXCALIDRAW_ASSET_PATH = 'https://cdn.jsdelivr.net/npm/@excalidraw/excalidraw@<?= RM_EXCALIDRAW_VER ?>/dist/';
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.3.1/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.3.1/umd/react-dom.production.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@excalidraw/excalidraw@<?= RM_EXCALIDRAW_VER ?>/dist/excalidraw.production.min.js"></script>

<script>
(function () {
    const OWNER          = { type: <?= json_encode($board['owner_type']) ?>, id: <?= (int)$board['owner_id'] ?> };
    const ITEM_ID        = <?= (int)$board['item_id'] ?>;
    const FRAME_GOALS    = <?= json_encode((object)$frameGoals) ?>;
    const LINK_GOAL      = <?= $linkGoal ? json_encode(['id' => (int)$linkGoal['goal_id'], 'title' => $linkGoal['title']]) : 'null' ?>;
    let   UNLINKED_GOALS = <?= json_encode($unlinkedGoals) ?>;
    const REGION_MISSING = <?= $regionMissing ? 'true' : 'false' ?>;
    const loading = document.getElementById('boardLoading');
    const stateEl = document.getElementById('saveState');
    const saveBtn = document.getElementById('saveBtn');
    const fullBtn = document.getElementById('fullBtn');

    function setState(text, cls) {
        stateEl.textContent = text;
        stateEl.className = 'rm-save-state ' + (cls || '');
    }

    // All three libraries come from a CDN, so a blocked or failed fetch is a
    // real possibility — say so instead of leaving an empty white box.
    if (!window.React || !window.ReactDOM || !window.ExcalidrawLib) {
        loading.innerHTML =
            '<i class="fas fa-triangle-exclamation" style="color:#dc2626"></i>' +
            '<div><strong>The whiteboard failed to load.</strong><br>' +
            'Check this machine can reach cdn.jsdelivr.net and cdnjs.cloudflare.com, then reload.</div>';
        saveBtn.disabled = true;
        setState('Not loaded', 'is-error');
        return;
    }

    // A goal keeps existing when its region is deleted from the task board, so
    // this is a normal state to land in, not an error — offer the way to fix it.
    if (REGION_MISSING) {
        loading.innerHTML =
            '<i class="fas fa-crosshairs"></i>' +
            '<div><strong>This goal has no region on the task board yet.</strong><br>' +
            'Draw a frame around its part of the board, then turn that frame into this goal.</div>' +
            '<a class="btn btn-primary btn-sm" style="border-radius:999px;font-weight:600" ' +
            'href="/roadmap/board.php?item=' + ITEM_ID + '&link=' + OWNER.id + '">Open the task board</a>';
        saveBtn.disabled = true;
        layersBtn.disabled = true;
        setState('No region', '');
        return;
    }

    let stored = null;
    try { stored = JSON.parse(document.getElementById('sceneData').textContent); } catch (e) { stored = null; }

    let api = null;
    let dirty = false;
    let saving = false;
    let saveAgain = false;   // a change landed while a save was in flight
    let saveTimer = null;

    // getSceneVersion ignores pans, zooms and selections, so the board is not
    // re-saved every time someone just moves the pointer around. It also
    // ignores STACKING ORDER, though — reordering the array mutates no element,
    // so its summed versions don't move. Excalidraw's own "send backward" and
    // the layers panel below both reorder, so order is hashed separately or
    // those edits would never autosave.
    function orderHash(els) {
        let h = 0;
        for (let i = 0; i < els.length; i++) {
            const id = els[i].id;
            for (let j = 0; j < id.length; j++) h = (h * 31 + id.charCodeAt(j)) | 0;
        }
        return h;
    }

    const storedElements = (stored && stored.elements) ? stored.elements : [];
    let savedVersion = ExcalidrawLib.getSceneVersion(storedElements);
    let savedOrder = orderHash(storedElements);
    let savedBackground = (stored && stored.appState && stored.appState.viewBackgroundColor) || null;

    // Returns a promise so "Make goal" can be sure the frame is in the SAVED
    // scene before asking the server to link it — link_frame.php checks the
    // region against what is stored, not against what is on screen.
    function doSave() {
        if (!api) return Promise.resolve();
        if (saving) { saveAgain = true; return Promise.resolve(); }
        clearTimeout(saveTimer);

        const elements = api.getSceneElements();
        const appState = api.getAppState();
        const version  = ExcalidrawLib.getSceneVersion(elements);
        const order    = orderHash(elements);

        // serializeAsJSON is Excalidraw's own writer: it prunes unused files
        // and strips the volatile parts of appState (cursor, selection, zoom),
        // so what gets stored is a real .excalidraw document.
        const scene = JSON.parse(ExcalidrawLib.serializeAsJSON(elements, appState, api.getFiles(), 'local'));

        saving = true;
        setState('Saving…', '');
        return fetch('/roadmap/ajax/save_board.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ owner_type: OWNER.type, owner_id: OWNER.id, scene: scene })
        })
        .then(r => r.json())
        .then(d => {
            saving = false;
            if (!d.success) { setState(d.error || 'Save failed', 'is-error'); return; }
            savedVersion = version;
            savedOrder = order;
            savedBackground = appState.viewBackgroundColor;
            if (saveAgain) { saveAgain = false; doSave(); return; }
            dirty = false;
            setState('Saved ' + d.saved_at, 'is-saved');
        })
        .catch(() => {
            saving = false;
            setState('Save failed — retry', 'is-error');
        });
    }

    function onChange(elements, appState) {
        // Ahead of the early return: a pure selection change saves nothing but
        // still has to move the highlight in the layers panel.
        schedulePanelRefresh();

        const version = ExcalidrawLib.getSceneVersion(elements);
        // Changing the canvas background is a real edit but does not move the
        // scene version, so it is tracked alongside it.
        if (version === savedVersion && orderHash(elements) === savedOrder
            && appState.viewBackgroundColor === savedBackground) return;
        dirty = true;
        setState('Unsaved changes', 'is-dirty');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(doSave, 1500);
    }

    setState(stored ? 'Saved' : 'Empty board', stored ? 'is-saved' : '');

    saveBtn.addEventListener('click', doSave);

    // Ctrl+S is muscle memory on a canvas; without this it saves the HTML page.
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); doSave(); }
    });

    // ══ Layers panel ══════════════════════════════════════════════════════
    // Excalidraw has no layer model: the elements array IS the z-order, and the
    // only built-in UI is relative nudges, so you can move a shape without ever
    // seeing where it sits. This reads that array out and lets it be reordered
    // directly.
    const layersList  = document.getElementById('layersList');
    const layersCount = document.getElementById('layersCount');
    const layersBtn   = document.getElementById('layersBtn');

    const TYPE_META = {
        rectangle:  { icon: 'fa-square',            label: 'Rectangle' },
        diamond:    { icon: 'fa-diamond',           label: 'Diamond' },
        ellipse:    { icon: 'fa-circle',            label: 'Ellipse' },
        arrow:      { icon: 'fa-arrow-right-long',  label: 'Arrow' },
        line:       { icon: 'fa-minus',             label: 'Line' },
        freedraw:   { icon: 'fa-pen',               label: 'Drawing' },
        text:       { icon: 'fa-font',              label: 'Text' },
        image:      { icon: 'fa-image',             label: 'Image' },
        embeddable: { icon: 'fa-globe',             label: 'Web embed' },
        iframe:     { icon: 'fa-globe',             label: 'Web embed' }
    };

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function truncate(s, n) {
        s = String(s).replace(/\s+/g, ' ').trim();
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    }

    // Rows are TOP-LEVEL things, not raw elements. A frame owns its children, a
    // group moves as one unit, and a shape's bound label belongs to the shape —
    // all three have to stay intact in the elements array, so the panel never
    // offers to move their parts independently.
    function buildRows(els) {
        const byId = new Map();
        els.forEach(function (e) { byId.set(e.id, e); });

        function keyFor(el) {
            if (el.type === 'frame') return 'frame:' + el.id;
            if (el.frameId) return 'frame:' + el.frameId;
            if (el.groupIds && el.groupIds.length) return 'group:' + el.groupIds[el.groupIds.length - 1];
            return 'el:' + el.id;
        }

        const byKey = new Map();
        const order = [];
        function push(key, el) {
            if (!byKey.has(key)) { byKey.set(key, []); order.push(key); }
            byKey.get(key).push(el);
        }

        els.forEach(function (el) { if (!el.containerId) push(keyFor(el), el); });
        // Bound text in a second pass, so it lands in its container's row
        // whichever way round the two appear in the array.
        els.forEach(function (el) {
            if (!el.containerId) return;
            const owner = byId.get(el.containerId);
            push(owner ? keyFor(owner) : keyFor(el), el);
        });

        const rows = order.map(function (key) {
            const members = byKey.get(key);
            let icon, label;
            if (key.indexOf('frame:') === 0) {
                const frame = members.find(function (e) { return e.type === 'frame'; });
                icon  = 'fa-table-cells-large';
                label = 'Frame: ' + ((frame && frame.name) || 'Untitled');
            } else if (key.indexOf('group:') === 0) {
                icon  = 'fa-object-group';
                label = 'Group (' + members.filter(function (e) { return !e.containerId; }).length + ')';
            } else {
                const main = members[0];
                const meta = TYPE_META[main.type] || { icon: 'fa-shapes', label: main.type };
                icon  = meta.icon;
                label = meta.label;
                if (main.type === 'text' && main.text) {
                    label = truncate(main.text, 26);
                } else {
                    const bound = members.find(function (e) { return e.id !== main.id && e.text; });
                    if (bound) label = meta.label + ' “' + truncate(bound.text, 15) + '”';
                }
            }
            return {
                key: key, icon: icon, label: label,
                ids: members.map(function (e) { return e.id; }),
                locked: members.every(function (e) { return !!e.locked; })
            };
        });

        return { rows: rows, byKey: byKey, order: order };
    }

    let layerRows = [];
    let lastLayerSig = '';
    let panelTimer = null;

    function layersOpen() { return document.body.classList.contains('rm-layers-open'); }

    function schedulePanelRefresh() {
        clearTimeout(panelTimer);
        panelTimer = setTimeout(refreshDock, 120);
    }

    // The frame panel tracks the selection, so this runs on every change even
    // with Layers closed; only the list rebuild is skipped in that case.
    function refreshDock() {
        if (!api) return;
        // The goal field lives in Excalidraw's own island now, so it no longer
        // decides whether the right-hand dock is open — only Layers does.
        renderGoalPanel();
        if (layersOpen()) refreshLayers();
        document.body.classList.toggle('rm-dock-open', layersOpen());
    }

    function refreshLayers() {
        if (!api || !layersOpen()) return;
        const built = buildRows(api.getSceneElements());
        layerRows = built.rows;
        layersCount.textContent = built.rows.length || '';
        // Redrawing on every change would fight the hover state and reset the
        // scroll position, so the list is only rebuilt when it actually differs.
        const sig = built.rows.map(function (r) { return r.key + '|' + r.label + '|' + r.locked; }).join('~');
        if (sig !== lastLayerSig) { drawLayers(); lastLayerSig = sig; }
        highlightLayers();
    }

    function drawLayers() {
        if (!layerRows.length) {
            layersList.innerHTML = '<div class="rm-layers-empty">Nothing on the board yet.</div>';
            return;
        }
        function btn(act, icon, title) {
            return '<button class="rm-layer-btn" data-act="' + act + '" title="' + title + '"><i class="fas ' + icon + '"></i></button>';
        }
        let html = '';
        // The array runs bottom-to-top; the panel reads top-to-bottom.
        for (let i = layerRows.length - 1; i >= 0; i--) {
            const r = layerRows[i];
            // A frame is a candidate region: on the task board it can become a
            // goal. Inside a goal's own view there is nothing to promote — the
            // one frame there IS the goal.
            let goalBit = '';
            if (OWNER.type === 'item' && r.key.indexOf('frame:') === 0) {
                const g = FRAME_GOALS[r.key.slice(6)];
                goalBit = g
                    ? '<i class="fas fa-bullseye rm-layer-goal" title="Goal: ' + esc(g.title) + '"></i>'
                    : '<button class="rm-layer-make" data-act="goal" title="Turn this region into a goal">'
                      + (LINK_GOAL ? 'Link' : 'Make goal') + '</button>';
            }
            html += '<div class="rm-layer' + (r.locked ? ' is-locked' : '') + '" data-key="' + esc(r.key) + '">'
                  +   '<i class="fas ' + r.icon + ' rm-layer-icon"></i>'
                  +   '<span class="rm-layer-name" title="' + esc(r.label) + '">' + esc(r.label) + '</span>'
                  +   goalBit
                  +   '<span class="rm-layer-btns">'
                  +     btn('front', 'fa-angles-up',   'Bring to front')
                  +     btn('up',    'fa-chevron-up',  'Bring forward')
                  +     btn('down',  'fa-chevron-down','Send backward')
                  +     btn('back',  'fa-angles-down', 'Send to back')
                  +   '</span>'
                  +   '<button class="rm-layer-btn rm-layer-lock' + (r.locked ? ' is-on' : '') + '" data-act="lock" '
                  +     'title="' + (r.locked ? 'Unlock' : 'Lock') + '"><i class="fas ' + (r.locked ? 'fa-lock' : 'fa-lock-open') + '"></i></button>'
                  + '</div>';
        }
        layersList.innerHTML = html;
    }

    function highlightLayers() {
        const sel = api.getAppState().selectedElementIds || {};
        Array.prototype.forEach.call(layersList.querySelectorAll('.rm-layer'), function (node) {
            const r = layerRows.find(function (x) { return x.key === node.dataset.key; });
            const on = !!r && r.ids.some(function (id) { return sel[id]; });
            node.classList.toggle('is-selected', on);
        });
    }

    function moveRow(key, where) {
        const built = buildRows(api.getSceneElements());
        const order = built.order.slice();
        const i = order.indexOf(key);
        if (i === -1) return;

        order.splice(i, 1);
        let j;
        if (where === 'up')        j = Math.min(i + 1, order.length);
        else if (where === 'down') j = Math.max(i - 1, 0);
        else if (where === 'front') j = order.length;
        else                        j = 0;
        order.splice(j, 0, key);

        // Rebuilding from the row order also makes every row contiguous, which
        // is what Excalidraw needs for a group's and a frame's members.
        const out = [];
        order.forEach(function (k) {
            built.byKey.get(k).forEach(function (el) { out.push(el); });
        });
        api.updateScene({ elements: out, commitToHistory: true });
        refreshLayers();
    }

    function toggleLock(key) {
        const built = buildRows(api.getSceneElements());
        const row = built.rows.find(function (r) { return r.key === key; });
        if (!row) return;
        const ids  = new Set(row.ids);
        const want = !row.locked;
        // version and versionNonce are bumped by hand: updateScene does not do
        // it, and getSceneVersion — which decides whether to autosave — is a sum
        // of element versions, so an unbumped edit would never be written.
        const out = api.getSceneElements().map(function (el) {
            if (!ids.has(el.id)) return el;
            return Object.assign({}, el, {
                locked: want,
                version: (el.version || 1) + 1,
                versionNonce: (Math.random() * 2147483647) | 0,
                updated: Date.now()
            });
        });
        api.updateScene({ elements: out, commitToHistory: true });
        refreshLayers();
    }

    function selectRow(key) {
        const row = layerRows.find(function (r) { return r.key === key; });
        if (!row) return;
        const sel = {};
        row.ids.forEach(function (id) { sel[id] = true; });
        api.updateScene({ appState: { selectedElementIds: sel } });
        highlightLayers();
    }

    // ══ Frame → goal ══════════════════════════════════════════════════════
    // Sketch the goals out as frames on the task board, then turn each frame
    // into a goal — the frame becomes the goal's region, and the goal's own
    // whiteboard button opens exactly that part of this canvas.

    // Excalidraw labels an unnamed frame "Frame N" by its position among the
    // frames, and setting a name replaces that — so the default has to be
    // reproduced before the goal can be appended to it.
    function defaultFrameName(frameId) {
        const frames = api.getSceneElements().filter(function (e) { return e.type === 'frame'; });
        const i = frames.findIndex(function (e) { return e.id === frameId; });
        return 'Frame ' + (i + 1);
    }

    // The frame's own on-canvas label carries its goal, so the board reads
    // "Frame 1 - Implementation of our own fabrication database".
    function labelFrame(frameId, goalTitle, previousTitle) {
        const frame = api.getSceneElements().find(function (e) { return e.id === frameId; });
        if (!frame) return;

        let base = (frame.name || '').trim() || defaultFrameName(frameId);
        // The previous goal comes back off first, or relinking would stack
        // suffixes into "Frame 1 - Old goal - New goal".
        if (previousTitle) {
            const suffix = ' - ' + previousTitle;
            if (base.slice(-suffix.length) === suffix) base = base.slice(0, -suffix.length);
        }

        let next;
        if (goalTitle) {
            next = base + ' - ' + goalTitle;
        } else {
            // Nothing custom was ever typed, so hand the label back to
            // Excalidraw's own numbering rather than freezing "Frame 1" on it.
            next = base === defaultFrameName(frameId) ? null : base;
        }
        if (next === frame.name) return;

        const out = api.getSceneElements().map(function (el) {
            if (el.id !== frameId) return el;
            return Object.assign({}, el, {
                name: next,
                version: (el.version || 1) + 1,
                versionNonce: (Math.random() * 2147483647) | 0,
                updated: Date.now()
            });
        });
        api.updateScene({ elements: out, commitToHistory: true });
    }

    // Either half of the panel ends here: `goalId` links an existing goal,
    // otherwise `name` titles a new one.
    function promoteFrame(frameId, name, goalId, onError) {
        setState('Saving…', '');
        // Saved first: link_frame.php validates the region against the STORED
        // scene, so a frame drawn seconds ago would not be found yet.
        doSave().then(function () {
            return fetch('/roadmap/ajax/link_frame.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_id: ITEM_ID, frame_id: frameId,
                    frame_name: name || '', goal_id: goalId || 0
                })
            });
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) {
                setState('Could not link that region', 'is-error');
                if (onError) onError(d.error || 'Could not link that region');
                return;
            }
            const title = d.title || name || (UNLINKED_GOALS.find(function (g) { return g.id === goalId; }) || {}).title || 'Untitled region';
            const prev  = FRAME_GOALS[frameId];

            labelFrame(frameId, title, prev ? prev.title : null);

            FRAME_GOALS[frameId] = { goal_id: d.goal_id, title: title };
            UNLINKED_GOALS = UNLINKED_GOALS.filter(function (g) { return g.id !== d.goal_id; });
            lastLayerSig = ''; panelSig = '';
            refreshDock();

            // Saved again so the renamed frame is stored, then — if this was
            // started from a goal looking for a region — go and show it.
            return doSave().then(function () {
                if (LINK_GOAL) { window.location.href = '/roadmap/board.php?goal=' + d.goal_id; return; }
                setState(d.created ? 'Goal created' : 'Goal linked', 'is-saved');
            });
        })
        .catch(function () {
            setState('Could not link that region', 'is-error');
            if (onError) onError('Could not link that region');
        });
    }

    function makeGoal(key) {
        const frameId = key.slice(6);
        const row = layerRows.find(function (r) { return r.key === key; });
        let name = row ? row.label.replace(/^Frame: /, '') : '';
        if (name === 'Untitled') name = '';
        promoteFrame(frameId, name, LINK_GOAL ? LINK_GOAL.id : 0);
    }

    // What the frame panel should be showing right now. Returns null when no
    // frame is in play, which is also what hides the section.
    function frameContext() {
        if (OWNER.type !== 'item') return null;
        const st   = api.getAppState();
        const tool = !!(st.activeTool && st.activeTool.type === 'frame');
        const sel  = st.selectedElementIds || {};
        const frames = api.getSceneElements().filter(function (e) {
            return e.type === 'frame' && sel[e.id];
        });
        if (frames.length === 1) return { frame: frames[0] };
        if (frames.length > 1)  return { many: frames.length };
        if (tool)               return { tool: true };
        return null;
    }

    // The panel lives INSIDE Excalidraw's own left properties island, so the
    // goal reads as another property of the frame. Excalidraw has no extension
    // point for that island in 0.17, so the node is appended to it and a
    // MutationObserver puts it back whenever React re-renders the island. It is
    // appended as a trailing child and never reorders or removes React's own
    // nodes, which is the part React will not tolerate.
    const goalPanel = document.createElement('fieldset');
    goalPanel.className = 'rm-goal-panel panelColumn';
    let panelSig = '';

    function islandColumn() {
        const left = document.querySelector('.excalidraw .App-menu__left');
        if (!left) return null;
        return left.querySelector('.panelColumn') || left.querySelector('.Island') || left;
    }

    // Always the LAST child, never merely present. Excalidraw renders the
    // Actions fieldset into this same column, and right after a frame is drawn
    // it arrives AFTER our node — which left the goal field sitting above
    // Actions until the frame was reselected. appendChild moves an existing
    // node, so this re-seats it under whatever React just added.
    function mountGoalPanel() {
        const col = islandColumn();
        if (col && col.lastElementChild !== goalPanel) col.appendChild(goalPanel);
    }

    function renderGoalPanel() {
        const ctx = frameContext();

        if (!ctx) {
            if (goalPanel.parentNode) goalPanel.parentNode.removeChild(goalPanel);
            panelSig = '';
            return false;
        }

        const linked = ctx.frame ? FRAME_GOALS[ctx.frame.id] : null;
        // Redrawing on every canvas event would wipe a half-typed goal name, so
        // the markup is only rebuilt when the frame or its goal actually change.
        const sig = ctx.many ? 'many:' + ctx.many
                  : ctx.tool ? 'tool'
                  : 'frame:' + ctx.frame.id + ':' + (linked ? linked.goal_id + ':' + linked.title : 'none');

        if (sig !== panelSig) {
            panelSig = sig;
            if (ctx.tool) {
                goalPanel.innerHTML = '<div class="rm-goal-legend">Goal</div>'
                    + '<div class="rm-goal-note">Draw a frame, then name the goal it stands for.</div>';
            } else if (ctx.many) {
                goalPanel.innerHTML = '<div class="rm-goal-legend">Goal</div>'
                    + '<div class="rm-goal-note">' + ctx.many + ' frames selected — pick one.</div>';
            } else {
                const val  = linked ? linked.title : (ctx.frame.name || '');
                const note = linked
                    ? 'Type over it to rename the goal, or clear it to free this region. <a href="/roadmap/board.php?goal=' + linked.goal_id + '">Open goal board</a>'
                    : (UNLINKED_GOALS.length
                        ? 'Type a new goal, or pick one of this task’s goals that has no region yet.'
                        : 'Type a name to make this region a goal.');
                goalPanel.innerHTML = '<div class="rm-goal-legend">Goal</div>'
                    + '<div class="rm-goal-row">'
                    +   '<input id="rmGoalInput" list="rmGoalOptions" placeholder="Not a goal yet" value="' + esc(val) + '">'
                    +   '<button class="rm-goal-go" id="rmGoalGo" title="Apply"><i class="fas fa-check"></i></button>'
                    + '</div>'
                    + '<datalist id="rmGoalOptions">'
                    +   UNLINKED_GOALS.map(function (g) { return '<option value="' + esc(g.title) + '">'; }).join('')
                    + '</datalist>'
                    + '<div class="rm-goal-note" id="rmGoalNote">' + note + '</div>';
            }
        }

        mountGoalPanel();
        return true;
    }

    function goalNote(msg, isError) {
        const el = goalPanel.querySelector('#rmGoalNote');
        if (!el) return;
        el.innerHTML = msg;
        el.classList.toggle('is-error', !!isError);
    }

    // Committing the field: empty releases the region, a name that matches one
    // of this task's region-less goals links that goal, anything else makes a
    // new goal called that.
    function commitGoalField() {
        const ctx = frameContext();
        const input = goalPanel.querySelector('#rmGoalInput');
        if (!ctx || !ctx.frame || !input) return;

        const frameId = ctx.frame.id;
        const linked  = FRAME_GOALS[frameId];
        const typed   = input.value.trim();

        if (!typed) {
            if (!linked) return;
            setState('Saving…', '');
            fetch('/roadmap/ajax/link_frame.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: ITEM_ID, frame_id: frameId, unlink: true })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { goalNote(d.error, true); setState('Could not unlink', 'is-error'); return; }
                // The goal comes back off the frame's label too.
                labelFrame(frameId, null, d.title);
                UNLINKED_GOALS.push({ id: d.goal_id, title: d.title });
                delete FRAME_GOALS[frameId];
                panelSig = ''; lastLayerSig = '';
                refreshDock();
                return doSave().then(function () { setState('Region freed', 'is-saved'); });
            })
            .catch(function () { goalNote('Could not unlink that goal.', true); });
            return;
        }

        // Typing over a linked goal renames the goal itself — everywhere, the
        // goals list included — and the frame's label follows it.
        if (linked && typed === linked.title) return;
        if (linked) {
            setState('Saving…', '');
            fetch('/roadmap/ajax/link_frame.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: ITEM_ID, frame_id: frameId, rename: true, title: typed })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { goalNote(d.error, true); setState('Could not rename', 'is-error'); return; }
                labelFrame(frameId, d.title, d.previous);
                FRAME_GOALS[frameId] = { goal_id: d.goal_id, title: d.title };
                panelSig = ''; lastLayerSig = '';
                refreshDock();
                return doSave().then(function () { setState('Goal renamed', 'is-saved'); });
            })
            .catch(function () { goalNote('Could not rename that goal.', true); });
            return;
        }

        const match = UNLINKED_GOALS.find(function (g) { return g.title.toLowerCase() === typed.toLowerCase(); });
        promoteFrame(frameId, match ? '' : typed, match ? match.id : 0, function (msg) { goalNote(msg, true); });
    }

    goalPanel.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); commitGoalField(); }
        // Excalidraw binds single keys to tools globally; without this, typing
        // a goal name would switch tools under you.
        e.stopPropagation();
    });
    goalPanel.addEventListener('click', function (e) {
        if (e.target.closest('#rmGoalGo')) commitGoalField();
    });
    goalPanel.addEventListener('focusout', function (e) {
        if (goalPanel.contains(e.relatedTarget)) return;
        commitGoalField();
    });

    // React rebuilds the island on every property change, taking our node with
    // it; this puts it straight back.
    const islandWatcher = new MutationObserver(function () {
        if (panelSig) mountGoalPanel();
    });
    islandWatcher.observe(document.getElementById('boardCanvas'), { childList: true, subtree: true });

    layersList.addEventListener('click', function (e) {
        const row = e.target.closest('.rm-layer');
        if (!row) return;
        const action = e.target.closest('[data-act]');
        if (!action) { selectRow(row.dataset.key); return; }
        if (action.dataset.act === 'lock') toggleLock(row.dataset.key);
        else if (action.dataset.act === 'goal') makeGoal(row.dataset.key);
        else moveRow(row.dataset.key, action.dataset.act);
    });

    layersBtn.addEventListener('click', function () {
        const open = !layersOpen();
        document.body.classList.toggle('rm-layers-open', open);
        layersBtn.classList.toggle('btn-primary', open);
        layersBtn.classList.toggle('btn-outline-secondary', !open);
        if (open) { lastLayerSig = ''; refreshDock(); }
    });

    // In linking mode the panel is where the Make goal button lives, so it
    // starts open rather than making you find it.
    if (LINK_GOAL) layersBtn.click();

    const bar = document.querySelector('.rm-board-bar');

    // The pinned bar is taken out of flow in full screen, so the stage has to
    // be pushed down by its real height — which changes when the bar wraps.
    function syncBarHeight() {
        document.body.style.setProperty('--rm-bar-h', bar.offsetHeight + 'px');
    }

    function setFull(full) {
        document.body.classList.toggle('rm-full', full);
        document.body.style.overflow = full ? 'hidden' : '';
        if (full) syncBarHeight();
        fullBtn.innerHTML = full ? '<i class="fas fa-compress"></i>' : '<i class="fas fa-expand"></i>';
        fullBtn.title = full ? 'Exit full screen (Esc)' : 'Expand to full screen';
    }

    fullBtn.addEventListener('click', function () {
        setFull(!document.body.classList.contains('rm-full'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('rm-full')) setFull(false);
    });
    window.addEventListener('resize', function () {
        if (document.body.classList.contains('rm-full')) syncBarHeight();
    });

    // Autosave runs 1.5s behind the last edit, so a fast close can still race it.
    window.addEventListener('beforeunload', function (e) {
        if (!dirty && !saving) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // Mounted LAST, deliberately: React calls onChange as soon as it renders,
    // and onChange now drives the layers panel, so everything that touches must
    // already exist. Mounting earlier put the panel's own state in a temporal
    // dead zone.
    const props = {
        initialData: stored
            ? { elements: stored.elements || [], appState: stored.appState || {}, files: stored.files || {}, scrollToContent: true }
            : null,
        excalidrawAPI: function (a) { api = a; loading.style.display = 'none'; refreshDock(); },
        onChange: onChange,
        name: <?= json_encode($board['title']) ?>,
        // Both of these act on local .excalidraw files, which would compete
        // with the board's own saving. Image export is deliberately left on.
        UIOptions: { canvasActions: { loadScene: false, saveToActiveFile: false } }
    };

    const node = React.createElement(ExcalidrawLib.Excalidraw, props);
    const el   = document.getElementById('boardCanvas');
    if (ReactDOM.createRoot) ReactDOM.createRoot(el).render(node);
    else ReactDOM.render(node, el);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
