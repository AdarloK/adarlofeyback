<?php

/**
 * Inserts a few sample products (only when the table is empty).
 */
class Seed_sample_products {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $db = $this->_lava->db;

        $count = (int) $db->raw('SELECT COUNT(*) FROM products')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $samples = [
            ['Wireless Mouse',        'Ergonomic 2.4GHz wireless mouse with USB receiver.',        499.00,  50],
            ['Mechanical Keyboard',   'Compact 75% layout, blue switches, RGB backlight.',         2499.50, 25],
            ['USB-C Hub 7-in-1',      'HDMI, 3x USB 3.0, SD/microSD reader and 100W PD charging.', 1299.00, 40],
            ['27" IPS Monitor',       'Full HD 75Hz monitor with thin bezels and HDMI/VGA ports.', 8999.00, 12],
            ['Laptop Stand',          'Adjustable aluminum stand, fits 11-17 inch laptops.',       799.75,  60],
        ];

        foreach ($samples as [$name, $desc, $price, $qty]) {
            $db->raw(
                'INSERT INTO products (product_name, description, price, quantity) VALUES (?, ?, ?, ?)',
                [$name, $desc, $price, $qty]
            );
        }
    }

    public function down()
    {
        $this->_lava->db->raw(
            'DELETE FROM products WHERE product_name IN (?, ?, ?, ?, ?)',
            ['Wireless Mouse', 'Mechanical Keyboard', 'USB-C Hub 7-in-1', '27" IPS Monitor', 'Laptop Stand']
        );
    }
}
