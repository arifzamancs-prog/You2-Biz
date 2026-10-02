<?php

// Compatibility with live installations where the disabled stock modules never
// created their base tables. Existing tables and financial history are retained.
function ensure_stock_base_tables($conn)
{
    $definitions = [
        'product_categories' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,category_name VARCHAR(100) NOT NULL,category_type ENUM('non_stock','stock_product') NOT NULL DEFAULT 'non_stock',status ENUM('active','inactive') DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'products' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,category_id INT NOT NULL,product_name VARCHAR(150) NOT NULL,sku VARCHAR(50),photo_path VARCHAR(255) NULL,purchase_price DECIMAL(15,2) DEFAULT 0,sale_price DECIMAL(15,2) DEFAULT 0,expired_on DATE,current_stock INT NOT NULL DEFAULT 0,minimum_stock INT NOT NULL DEFAULT 0,status ENUM('active','inactive') DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'suppliers' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,supplier_name VARCHAR(150) NOT NULL,phone VARCHAR(30),email VARCHAR(150),address TEXT,opening_balance DECIMAL(12,2) DEFAULT 0,status ENUM('active','inactive') DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'purchases' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,purchase_no VARCHAR(50) NOT NULL,supplier_id INT NOT NULL,payment_wallet_id INT,purchase_date DATE NOT NULL,total_amount DECIMAL(12,2) DEFAULT 0,paid_amount DECIMAL(12,2) DEFAULT 0,due_amount DECIMAL(12,2) DEFAULT 0,payment_status ENUM('paid','partial','due') DEFAULT 'due',notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'purchase_items' => "id INT AUTO_INCREMENT PRIMARY KEY,purchase_id INT NOT NULL,product_id INT NOT NULL,quantity INT NOT NULL,unit_cost DECIMAL(12,2) DEFAULT 0,total_cost DECIMAL(12,2) DEFAULT 0",
        'invoices' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,invoice_no VARCHAR(50) NOT NULL,customer_id INT,receive_wallet_id INT,customer_name VARCHAR(150),invoice_date DATE NOT NULL,total_amount DECIMAL(15,2) DEFAULT 0,paid_amount DECIMAL(15,2) DEFAULT 0,due_amount DECIMAL(15,2) DEFAULT 0,payment_status ENUM('paid','partial','due') DEFAULT 'due',accounting_status VARCHAR(20) NOT NULL DEFAULT 'posted',notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'invoice_items' => "id INT AUTO_INCREMENT PRIMARY KEY,invoice_id INT NOT NULL,product_id INT NOT NULL,quantity INT NOT NULL,unit_price DECIMAL(15,2) NOT NULL,total_price DECIMAL(15,2) NOT NULL",
        'stock_transactions' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,product_id INT NOT NULL,transaction_type ENUM('stock_in','stock_out') NOT NULL,quantity INT NOT NULL,note TEXT,txn_date DATE NOT NULL,reference_no VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'supplier_payments' => "id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,supplier_id INT NOT NULL,purchase_id INT NOT NULL,wallet_id INT NOT NULL,amount DECIMAL(12,2) NOT NULL,payment_date DATE NOT NULL,note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach ($definitions as $table => $columns) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `{$table}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
