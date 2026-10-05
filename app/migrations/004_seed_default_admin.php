<?php

/**
 * Seeds a default account so you can log in right after migrating.
 *   username: admin      password: Admin@123
 * CHANGE THIS PASSWORD (or delete the account) after your first login.
 */
class Seed_default_admin {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $db = $this->_lava->db;

        $exists = $db->raw('SELECT id FROM users WHERE username = ? LIMIT 1', ['admin'])
                     ->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            return;
        }

        $db->raw(
            'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)',
            ['admin', 'admin@example.com', password_hash('Admin@123', PASSWORD_DEFAULT), 'admin']
        );
    }

    public function down()
    {
        $this->_lava->db->raw(
            'DELETE FROM users WHERE username = ? AND email = ?',
            ['admin', 'admin@example.com']
        );
    }
}
