<?php
// ============================================================
// users 表操作函数
// ============================================================

function find_user_by_id(int $id): array
{
    return db_fetch_one(
        'SELECT id, username, email, role, created_at FROM users WHERE id = ?',
        [$id]
    );
}

function find_user_by_username(string $username): array
{
    return db_fetch_one(
        'SELECT id, username, hashpass, email, role, created_at FROM users WHERE username = ?',
        [$username]
    );
}

function find_user_by_email(string $email): array
{
    return db_fetch_one(
        'SELECT id, username, email, role, created_at FROM users WHERE email = ?',
        [$email]
    );
}

function user_email_exists(string $email): array
{
    $result = db_fetch_column(
        'SELECT COUNT(*) FROM users WHERE email = ?',
        [$email]
    );
    if (!$result['success']) return $result;
    return array_success((int)$result['data'] > 0);
}

function insert_user(string $username, string $email, string $hashpass, string $role = 'guest'): array
{
    return db_insert(
        'INSERT INTO users (username, email, hashpass, role) VALUES (?, ?, ?, ?)',
        [$username, $email, $hashpass, $role]
    );
}
