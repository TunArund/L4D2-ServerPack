<?php
// ============================================================
// emails 表操作函数
// ============================================================

function find_email(string $email): array
{
    return db_fetch_one(
        'SELECT email, vericode, expire FROM emails WHERE email = ?',
        [$email]
    );
}

function upsert_email(string $email, string $vericode, string $expire): array
{
    return db_execute_write(
        'INSERT INTO emails (email, vericode, expire) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE vericode = VALUES(vericode), expire = VALUES(expire)',
        [$email, $vericode, $expire]
    );
}
