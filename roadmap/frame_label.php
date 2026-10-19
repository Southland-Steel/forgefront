<?php
/**
 * A goal's title is shown on the canvas as part of its frame's label, so the
 * board reads "Frame 1 - Implementation of our own fabrication database".
 * Renaming or deleting the goal has to keep that label honest, and those
 * actions happen on item.php with no canvas open — so the scene JSON is
 * edited server-side here.
 *
 * This mirrors labelFrame() in board.php, which does the same thing live while
 * someone is drawing. Change one and change the other.
 */

/**
 * Excalidraw labels an unnamed frame "Frame N" by its position among the
 * frames, and setting a name replaces that — so the default has to be
 * reproduced before a goal can be appended to it.
 */
function rmDefaultFrameName(array $elements, string $frameId): string {
    $n = 0;
    foreach ($elements as $el) {
        if (($el['type'] ?? '') !== 'frame') continue;
        $n++;
        if (($el['id'] ?? '') === $frameId) return 'Frame ' . $n;
    }
    return 'Frame ' . ($n + 1);
}

/**
 * Rewrites one frame's label for a goal change. Pass $goalTitle = null to take
 * the goal off (deleted or unlinked); $previousTitle is the goal that was on
 * it, so the old suffix can be removed instead of stacking a second one.
 */
function rmApplyFrameLabel(PDO $pdo, int $itemId, string $frameId, ?string $goalTitle, ?string $previousTitle): void {
    if ($frameId === '') return;

    $stmt = $pdo->prepare("SELECT scene_json FROM roadmap_boards WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $json = $stmt->fetchColumn();
    if (!$json) return;

    $scene = json_decode($json, true);
    if (!is_array($scene) || !isset($scene['elements']) || !is_array($scene['elements'])) return;

    $index = null;
    foreach ($scene['elements'] as $i => $el) {
        if (($el['id'] ?? '') === $frameId && ($el['type'] ?? '') === 'frame') { $index = $i; break; }
    }
    // The frame is gone from the canvas — the goal is orphaned, and there is
    // no label left to keep in step.
    if ($index === null) return;

    $frame = $scene['elements'][$index];
    $base  = trim((string)($frame['name'] ?? ''));
    if ($base === '') $base = rmDefaultFrameName($scene['elements'], $frameId);

    // The previous goal comes off first, or renaming stacks suffixes into
    // "Frame 1 - Old goal - New goal".
    if ($previousTitle !== null && $previousTitle !== '') {
        $suffix = ' - ' . $previousTitle;
        if (substr($base, -strlen($suffix)) === $suffix) {
            $base = substr($base, 0, -strlen($suffix));
        }
    }

    if ($goalTitle !== null && $goalTitle !== '') {
        $next = $base . ' - ' . $goalTitle;
    } else {
        // Nothing custom was ever typed, so hand the label back to
        // Excalidraw's own numbering rather than freezing "Frame 1" onto it.
        $next = $base === rmDefaultFrameName($scene['elements'], $frameId) ? null : $base;
    }

    if ($next === ($frame['name'] ?? null)) return;

    // version/versionNonce are bumped by hand so a board open in someone's
    // browser treats this as a real change rather than a no-op.
    $frame['name']         = $next;
    $frame['version']      = (int)($frame['version'] ?? 1) + 1;
    $frame['versionNonce'] = random_int(0, 2147483647);
    $frame['updated']      = (int)(microtime(true) * 1000);
    $scene['elements'][$index] = $frame;

    $pdo->prepare("UPDATE roadmap_boards SET scene_json = ? WHERE item_id = ?")
        ->execute([json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $itemId]);
}
