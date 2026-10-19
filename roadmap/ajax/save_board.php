<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

// A canvas is bigger than the rest of this module's payloads — pasted images
// are inlined as data URLs in scene.files. php.ini allows 120M, but decoding
// roughly doubles the string in memory against a 128M limit, so the scene is
// capped well below that and refused with a message the canvas can show.
const RM_MAX_SCENE_BYTES = 24 * 1024 * 1024;

$raw = file_get_contents('php://input');

// An over-limit POST arrives as an empty body rather than an error, so an
// empty payload is reported as "too large" instead of a confusing parse error.
if ($raw === '' || $raw === false) {
    echo json_encode(['success' => false, 'error' => 'Nothing was received — the board may be too large to save.']); exit;
}
if (strlen($raw) > RM_MAX_SCENE_BYTES) {
    echo json_encode(['success' => false, 'error' => 'This board is too large to save. Try removing some pasted images.']); exit;
}

$data    = json_decode($raw, true);
$ownerId = (int)($data['owner_id'] ?? 0);
$type    = $data['owner_type'] ?? '';
$scene   = $data['scene'] ?? null;
$pdo     = getPDO();

// 'item' saves a whole task board. 'goal' saves ONE REGION of a task board and
// is spliced into it — a goal has no canvas of its own.
if (!in_array($type, ['goal', 'item'], true) || !$ownerId) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid board owner.']); exit;
}
// The scene is written back into the canvas on the next load, so it is checked
// for shape here rather than trusting whatever the client sent.
if (!is_array($scene) || !isset($scene['elements']) || !is_array($scene['elements'])) {
    echo json_encode(['success' => false, 'error' => 'That does not look like a whiteboard scene.']); exit;
}

/**
 * Excalidraw's files and appState are keyed maps, but an EMPTY JSON object
 * decodes to an empty PHP array and would re-encode as "[]" — handing the
 * canvas an array where it expects a map. Only the empty case degrades like
 * this; a populated map round-trips as an object on its own.
 */
function rmNormaliseMaps(array $scene): array {
    foreach (['files', 'appState'] as $key) {
        if (isset($scene[$key]) && is_array($scene[$key]) && !$scene[$key]) {
            $scene[$key] = new stdClass();
        }
    }
    return $scene;
}

function rmLiveCount(array $elements): int {
    return count(array_filter($elements, fn($el) => empty($el['isDeleted'])));
}

// ── Resolve which task board is being written, and how ────────────────────
if ($type === 'item') {
    $itemId = $ownerId;
    $stmt = $pdo->prepare("SELECT 1 FROM roadmap_items WHERE item_id = ?");
    $stmt->execute([$itemId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'That task no longer exists.']); exit;
    }
    $scene = rmNormaliseMaps($scene);
    $count = rmLiveCount($scene['elements']);

} else {
    $stmt = $pdo->prepare("SELECT goal_id, item_id, frame_id FROM roadmap_goals WHERE goal_id = ?");
    $stmt->execute([$ownerId]);
    $goal = $stmt->fetch();
    if (!$goal) {
        echo json_encode(['success' => false, 'error' => 'That goal no longer exists.']); exit;
    }
    if (!$goal['frame_id']) {
        echo json_encode(['success' => false, 'error' => 'This goal has no region on the task board yet.']); exit;
    }

    $itemId  = (int)$goal['item_id'];
    $frameId = $goal['frame_id'];

    // The task's CURRENT scene is read here, not taken from the client, so two
    // people editing two different regions of the same task both survive —
    // each splice only replaces its own frame.
    $stmt = $pdo->prepare("SELECT scene_json FROM roadmap_boards WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $taskJson = $stmt->fetchColumn();
    $task = $taskJson ? json_decode($taskJson, true) : null;
    if (!is_array($task) || !isset($task['elements']) || !is_array($task['elements'])) {
        $task = ['type' => 'excalidraw', 'version' => 2, 'source' => 'local', 'elements' => [], 'appState' => [], 'files' => []];
    }

    // The region has to still be on the board. Without this a tab left open
    // from before someone deleted the frame would quietly put it back on the
    // next autosave, and report success for a write nobody asked for.
    $frameOnBoard = false;
    foreach ($task['elements'] as $el) {
        if (($el['id'] ?? '') === $frameId && empty($el['isDeleted'])) { $frameOnBoard = true; break; }
    }
    if (!$frameOnBoard) {
        echo json_encode([
            'success' => false,
            'error'   => 'This goal’s region is no longer on the task board — reload to see what changed.',
        ]); exit;
    }

    // Everything currently in the region comes out: the frame itself, anything
    // claiming it, and any label bound to one of those (bound text is a
    // separate element and would be left dangling otherwise).
    $dropped = [];
    foreach ($task['elements'] as $el) {
        $id = $el['id'] ?? '';
        if ($id === $frameId || (($el['frameId'] ?? null) === $frameId)) $dropped[$id] = true;
    }
    foreach ($task['elements'] as $el) {
        if (isset($el['containerId']) && isset($dropped[$el['containerId']])) $dropped[$el['id'] ?? ''] = true;
    }

    // Spliced back in at the position the region used to occupy, so promoting
    // or editing a goal does not quietly bring its region to the front.
    $at   = null;
    $kept = [];
    foreach ($task['elements'] as $i => $el) {
        if (isset($dropped[$el['id'] ?? ''])) {
            if ($at === null) $at = count($kept);
            continue;
        }
        $kept[] = $el;
    }
    if ($at === null) $at = count($kept);

    // Anything the editor sent that is NOT in this frame (drawn outside its
    // bounds, where Excalidraw assigns no frameId) is kept as a task-level
    // element rather than silently dropped on the way back in.
    array_splice($kept, $at, 0, $scene['elements']);

    $merged = $task;
    $merged['elements'] = $kept;
    // Images added inside the region have to survive into the task's file map.
    $taskFiles  = (isset($task['files'])  && is_array($task['files']))  ? $task['files']  : [];
    $sceneFiles = (isset($scene['files']) && is_array($scene['files'])) ? $scene['files'] : [];
    $merged['files'] = $sceneFiles + $taskFiles;

    $scene = rmNormaliseMaps($merged);
    $count = rmLiveCount($kept);
}

// Re-encoded rather than storing the raw body: what lands in the column is
// then guaranteed to be the JSON that just passed validation.
$json = json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$pdo->prepare("
    INSERT INTO roadmap_boards (item_id, scene_json, element_count, updated_by)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE scene_json = VALUES(scene_json),
                            element_count = VALUES(element_count),
                            updated_by = VALUES(updated_by)
")->execute([$itemId, $json, $count, Auth::getFullName()]);

echo json_encode(['success' => true, 'element_count' => $count, 'saved_at' => date('g:i A')]);
