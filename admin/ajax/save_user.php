<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requirePermission('users.manage');
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

try {
    $userId    = (int)($data['user_id'] ?? 0);
    $username  = trim($data['username']   ?? '');
    $firstName = trim($data['first_name'] ?? '');
    $lastName  = trim($data['last_name']  ?? '');
    $email     = trim($data['email']      ?? '');
    $password  = $data['password'] ?? '';
    $isActive  = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    $roles     = array_map('intval', $data['roles'] ?? []);

    if (!$username) { echo json_encode(['success' => false, 'error' => 'Username required']); exit; }

    if ($userId) {
        $sql    = "UPDATE ff_users SET username=?, first_name=?, last_name=?, email=?, is_active=?";
        $params = [$username, $firstName ?: null, $lastName ?: null, $email ?: null, $isActive];
        if ($password !== '') {
            $sql    .= ", password_hash=?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= " WHERE user_id=?";
        $params[] = $userId;
        $pdo->prepare($sql)->execute($params);
        $pdo->prepare("DELETE FROM ff_user_roles WHERE user_id=?")->execute([$userId]);
    } else {
        if (!$password) { echo json_encode(['success' => false, 'error' => 'Password required']); exit; }
        $pdo->prepare("
            INSERT INTO ff_users (username, password_hash, first_name, last_name, email)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$username, password_hash($password, PASSWORD_DEFAULT), $firstName ?: null, $lastName ?: null, $email ?: null]);
        $userId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("INSERT INTO ff_user_roles (user_id, role_id) VALUES (?, ?)");
    foreach ($roles as $roleId) {
        if ($roleId) $stmt->execute([$userId, $roleId]);
    }

    echo json_encode(['success' => true, 'user_id' => $userId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
