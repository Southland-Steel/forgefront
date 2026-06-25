<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requirePermission('users.manage');
header('Content-Type: application/json');
$pdo = getPDO();

$rows = $pdo->query("
    SELECT u.*,
           GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_id SEPARATOR ',') AS roles,
           GROUP_CONCAT(DISTINCT ur.role_id  ORDER BY ur.role_id SEPARATOR ',') AS role_ids
    FROM ff_users u
    LEFT JOIN ff_user_roles ur ON ur.user_id = u.user_id
    LEFT JOIN ff_roles r ON r.role_id = ur.role_id
    GROUP BY u.user_id
    ORDER BY u.first_name, u.last_name
")->fetchAll();

echo json_encode($rows);
