<?php
include '../config.php';
require_once '../inventory_payment_helpers.php';
checkAuth();
checkRole(['librarian', 'admin']);

// Enhanced inventory schema with complete approval workflow
function ensureInventorySchema(PDO $pdo): void {
    // Main inventory items table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            item_code VARCHAR(50) UNIQUE NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            category VARCHAR(100) NOT NULL,
            sub_category VARCHAR(100) NULL,
            description TEXT,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quantity_in_stock INT DEFAULT 0,
            reorder_level INT DEFAULT 10,
            reorder_quantity INT DEFAULT 20,
            supplier_id INT NULL,
            location VARCHAR(100) NULL,
            barcode VARCHAR(100) NULL,
            qr_code VARCHAR(255) NULL,
            image_path VARCHAR(500) NULL,
            specifications JSON NULL,
            warranty_info TEXT NULL,
            expiry_date DATE NULL,
            last_restock_date TIMESTAMP NULL,
            last_count_date TIMESTAMP NULL,
            status ENUM('active', 'inactive', 'discontinued', 'damaged', 'lost') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY category (category),
            KEY status (status),
            KEY supplier_id (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Approval workflow columns
    $approvalColumns = [
        'approval_status' => "ALTER TABLE inventory_items ADD COLUMN approval_status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'approved' AFTER status",
        'requested_by' => "ALTER TABLE inventory_items ADD COLUMN requested_by INT NULL AFTER approval_status",
        'requested_at' => "ALTER TABLE inventory_items ADD COLUMN requested_at DATETIME NULL AFTER requested_by",
        'approved_by' => "ALTER TABLE inventory_items ADD COLUMN approved_by INT NULL AFTER requested_at",
        'approved_at' => "ALTER TABLE inventory_items ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
        'approval_notes' => "ALTER TABLE inventory_items ADD COLUMN approval_notes TEXT NULL AFTER approved_at",
        'rejection_reason' => "ALTER TABLE inventory_items ADD COLUMN rejection_reason TEXT NULL AFTER approval_notes",
        'payment_status' => "ALTER TABLE inventory_items ADD COLUMN payment_status ENUM('pending','paid','partial','cancelled','refunded') NOT NULL DEFAULT 'pending' AFTER rejection_reason",
        'payment_reference' => "ALTER TABLE inventory_items ADD COLUMN payment_reference VARCHAR(120) NULL AFTER payment_status",
        'payment_notes' => "ALTER TABLE inventory_items ADD COLUMN payment_notes TEXT NULL AFTER payment_reference",
        'paid_by' => "ALTER TABLE inventory_items ADD COLUMN paid_by INT NULL AFTER payment_notes",
        'paid_at' => "ALTER TABLE inventory_items ADD COLUMN paid_at DATETIME NULL AFTER paid_by",
        'payment_method' => "ALTER TABLE inventory_items ADD COLUMN payment_method VARCHAR(50) NULL AFTER paid_at"
    ];

    // Add approval columns if they don't exist
    foreach ($approvalColumns as $column => $sql) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM inventory_items LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
        }
    }

    // Suppliers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            supplier_code VARCHAR(50) UNIQUE NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(255),
            email VARCHAR(255),
            phone VARCHAR(50),
            alternative_phone VARCHAR(50),
            address TEXT,
            tax_number VARCHAR(100),
            payment_terms TEXT,
            lead_time_days INT DEFAULT 7,
            rating DECIMAL(2,1) DEFAULT 0,
            notes TEXT,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Stock movements table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_movements (
            id INT PRIMARY KEY AUTO_INCREMENT,
            item_id INT NOT NULL,
            movement_type ENUM('purchase', 'sale', 'adjustment', 'damage', 'loss', 'return', 'transfer') NOT NULL,
            quantity INT NOT NULL,
            previous_quantity INT NOT NULL,
            new_quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NULL,
            total_amount DECIMAL(10,2) NULL,
            reference_type VARCHAR(50) NULL,
            reference_id INT NULL,
            notes TEXT,
            performed_by INT,
            performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
            KEY movement_type (movement_type),
            KEY performed_at (performed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Stock counts/adjustments
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_counts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            count_code VARCHAR(50) UNIQUE NOT NULL,
            count_date DATE NOT NULL,
            count_type ENUM('full', 'partial', 'cycle') DEFAULT 'partial',
            status ENUM('draft', 'in_progress', 'completed', 'cancelled') DEFAULT 'draft',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_by INT NULL,
            completed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Stock count items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_count_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            count_id INT NOT NULL,
            item_id INT NOT NULL,
            system_quantity INT NOT NULL,
            physical_quantity INT NOT NULL,
            variance INT NOT NULL,
            unit_price DECIMAL(10,2) NULL,
            variance_value DECIMAL(10,2) NULL,
            notes TEXT,
            counted_by INT,
            counted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (count_id) REFERENCES stock_counts(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Purchase orders
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            po_number VARCHAR(50) UNIQUE NOT NULL,
            supplier_id INT NOT NULL,
            order_date DATE NOT NULL,
            expected_delivery DATE NULL,
            delivery_date DATE NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(10,2) DEFAULT 0,
            shipping_cost DECIMAL(10,2) DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('draft', 'submitted', 'approved', 'ordered', 'received', 'cancelled') DEFAULT 'draft',
            payment_status ENUM('unpaid', 'partial', 'paid') DEFAULT 'unpaid',
            approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            received_by INT NULL,
            received_at DATETIME NULL,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Purchase order items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            po_id INT NOT NULL,
            item_id INT NOT NULL,
            quantity_ordered INT NOT NULL,
            quantity_received INT DEFAULT 0,
            unit_price DECIMAL(10,2) NOT NULL,
            total_price DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'partial', 'received', 'cancelled') DEFAULT 'pending',
            notes TEXT,
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Categories table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_categories (
            id INT PRIMARY KEY AUTO_INCREMENT,
            category_name VARCHAR(100) NOT NULL,
            parent_id INT NULL,
            description TEXT,
            icon VARCHAR(50),
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES inventory_categories(id) ON DELETE CASCADE,
            UNIQUE KEY unique_category (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insert default categories if empty
    $check = $pdo->query("SELECT COUNT(*) FROM inventory_categories")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("
            INSERT INTO inventory_categories (category_name, description, icon, sort_order) VALUES
            ('Books', 'Library books and educational materials', 'fa-book', 1),
            ('Stationery', 'Office and school stationery', 'fa-pen', 2),
            ('Equipment', 'School equipment and tools', 'fa-tools', 3),
            ('Furniture', 'School furniture', 'fa-chair', 4),
            ('Electronics', 'Electronic devices and accessories', 'fa-laptop', 5),
            ('Sports', 'Sports equipment', 'fa-futbol', 6),
            ('Laboratory', 'Lab equipment and supplies', 'fa-flask', 7),
            ('Kitchen', 'Kitchen and dining supplies', 'fa-utensils', 8),
            ('Cleaning', 'Cleaning supplies', 'fa-broom', 9),
            ('Uniforms', 'School uniforms and clothing', 'fa-tshirt', 10)
        ");
    }
}

ensureInventorySchema($pdo);
ensureInventoryPaymentWorkflow($pdo);

$user_id = $_SESSION['user_id'];

if (($_POST['action'] ?? '') === 'save_supplier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $companyName = trim((string) ($_POST['company_name'] ?? ''));

        if ($companyName === '') {
            throw new RuntimeException('Supplier name is required.');
        }

        if ($supplierId > 0) {
            $stmt = $pdo->prepare("
                UPDATE suppliers
                SET company_name = ?, contact_person = ?, email = ?, phone = ?, alternative_phone = ?,
                    address = ?, tax_number = ?, payment_terms = ?, lead_time_days = ?, notes = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $companyName,
                trim((string) ($_POST['contact_person'] ?? '')) ?: null,
                trim((string) ($_POST['email'] ?? '')) ?: null,
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) ($_POST['alternative_phone'] ?? '')) ?: null,
                trim((string) ($_POST['address'] ?? '')) ?: null,
                trim((string) ($_POST['tax_number'] ?? '')) ?: null,
                trim((string) ($_POST['payment_terms'] ?? '')) ?: null,
                (int) ($_POST['lead_time_days'] ?? 7),
                trim((string) ($_POST['notes'] ?? '')) ?: null,
                $_POST['status'] ?? 'active',
                $supplierId
            ]);
            $_SESSION['success'] = 'Supplier updated successfully.';
        } else {
            $supplierCode = 'SUP' . date('Ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (
                    supplier_code, company_name, contact_person, email, phone, alternative_phone,
                    address, tax_number, payment_terms, lead_time_days, notes, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $supplierCode,
                $companyName,
                trim((string) ($_POST['contact_person'] ?? '')) ?: null,
                trim((string) ($_POST['email'] ?? '')) ?: null,
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) ($_POST['alternative_phone'] ?? '')) ?: null,
                trim((string) ($_POST['address'] ?? '')) ?: null,
                trim((string) ($_POST['tax_number'] ?? '')) ?: null,
                trim((string) ($_POST['payment_terms'] ?? '')) ?: null,
                (int) ($_POST['lead_time_days'] ?? 7),
                trim((string) ($_POST['notes'] ?? '')) ?: null,
                $_POST['status'] ?? 'active',
                $user_id
            ]);
            $_SESSION['success'] = 'Supplier added successfully.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Supplier save error: ' . $e->getMessage();
    }
}

if (($_POST['action'] ?? '') === 'delete_supplier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $usageStmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM inventory_items WHERE supplier_id = ?) +
                (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?) AS usage_count
        ");
        $usageStmt->execute([$supplierId, $supplierId]);
        $usageCount = (int) $usageStmt->fetchColumn();

        if ($usageCount > 0) {
            $stmt = $pdo->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$supplierId]);
            $_SESSION['success'] = 'Supplier is in use, so it was marked inactive instead of being deleted.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$supplierId]);
            $_SESSION['success'] = 'Supplier deleted successfully.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Supplier delete error: ' . $e->getMessage();
    }
}

// Handle new inventory item
if (($_POST['action'] ?? '') === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Generate item code if not provided
        $item_code = $_POST['item_code'] ?: 'ITM' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO inventory_items (
                item_code, item_name, category, sub_category, description, 
                unit_price, quantity_in_stock, reorder_level, reorder_quantity,
                supplier_id, location, specifications, warranty_info, expiry_date,
                approval_status, requested_by, requested_at, payment_status,
                requested_payment_method, requested_payment_amount, payee_name, payee_phone,
                bank_name, bank_account_name, bank_account_number, bank_branch, mpesa_number, payment_narration
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $requestedPaymentMethod = trim((string) ($_POST['requested_payment_method'] ?? 'bank_transfer'));
        $requestedPaymentAmount = (float) ($_POST['requested_payment_amount'] ?? 0);
        if ($requestedPaymentAmount <= 0) {
            $requestedPaymentAmount = ((float) ($_POST['unit_price'] ?? 0)) * ((int) ($_POST['quantity_in_stock'] ?? 0));
        }
        $supplierId = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
        $supplierRow = null;
        if ($supplierId) {
            $supplierStmt = $pdo->prepare("SELECT company_name, phone FROM suppliers WHERE id = ?");
            $supplierStmt->execute([$supplierId]);
            $supplierRow = $supplierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $payeeName = trim((string) ($_POST['payee_name'] ?? ''));
        $payeePhone = trim((string) ($_POST['payee_phone'] ?? ''));
        if ($payeeName === '' && $supplierRow) {
            $payeeName = (string) ($supplierRow['company_name'] ?? '');
        }
        if ($payeePhone === '' && $supplierRow) {
            $payeePhone = (string) ($supplierRow['phone'] ?? '');
        }
        
        $result = $stmt->execute([
            $item_code,
            $_POST['item_name'],
            $_POST['category'],
            $_POST['sub_category'] ?? null,
            $_POST['description'] ?? null,
            $_POST['unit_price'] ?? 0,
            $_POST['quantity_in_stock'] ?? 0,
            $_POST['reorder_level'] ?? 10,
            $_POST['reorder_quantity'] ?? 20,
            $supplierId,
            $_POST['location'] ?? null,
            $_POST['specifications'] ?: null,
            $_POST['warranty_info'] ?? null,
            $_POST['expiry_date'] ?: null,
            $user_id,
            $requestedPaymentMethod,
            $requestedPaymentAmount,
            $payeeName !== '' ? $payeeName : null,
            $payeePhone !== '' ? $payeePhone : null,
            $_POST['bank_name'] ?: null,
            $_POST['bank_account_name'] ?: null,
            $_POST['bank_account_number'] ?: null,
            $_POST['bank_branch'] ?: null,
            $_POST['mpesa_number'] ?: null,
            $_POST['payment_narration'] ?: null
        ]);
        
        if ($result) {
            $item_id = $pdo->lastInsertId();
            
            // Record stock movement
            if ($_POST['quantity_in_stock'] > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, movement_type, quantity, previous_quantity, new_quantity,
                        unit_price, total_amount, notes, performed_by
                    ) VALUES (?, 'purchase', ?, 0, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $item_id,
                    $_POST['quantity_in_stock'],
                    $_POST['quantity_in_stock'],
                    $_POST['unit_price'],
                    $_POST['unit_price'] * $_POST['quantity_in_stock'],
                    'Initial stock entry',
                    $user_id
                ]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = 'Inventory item added successfully and is pending approval.';
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error adding item: ' . $e->getMessage();
    }
}

// Handle stock update
if (($_POST['action'] ?? '') === 'update_stock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Get current quantity
        $stmt = $pdo->prepare("SELECT quantity_in_stock, unit_price FROM inventory_items WHERE id = ?");
        $stmt->execute([$_POST['item_id']]);
        $item = $stmt->fetch();
        
        $new_quantity = $_POST['quantity'];
        $previous_quantity = $item['quantity_in_stock'];
        $restock_delta = max(0, (int) $new_quantity - (int) $previous_quantity);
        $requestedPaymentAmount = (float) ($_POST['requested_payment_amount'] ?? 0);
        if ($requestedPaymentAmount <= 0 && $restock_delta > 0) {
            $requestedPaymentAmount = (float) $item['unit_price'] * $restock_delta;
        }
        
        $stmt = $pdo->prepare("
            UPDATE inventory_items 
            SET quantity_in_stock = ?, last_restock_date = NOW(), approval_status = 'pending',
                requested_by = ?, requested_at = NOW(),
                approved_by = NULL, approved_at = NULL, approval_notes = NULL,
                payment_status = 'pending', payment_reference = NULL, payment_notes = NULL,
                paid_by = NULL, paid_at = NULL, payment_method = NULL,
                requested_payment_method = ?, requested_payment_amount = ?, payee_name = ?,
                payee_phone = ?, bank_name = ?, bank_account_name = ?, bank_account_number = ?,
                bank_branch = ?, mpesa_number = ?, payment_narration = ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $new_quantity,
            $user_id,
            $_POST['requested_payment_method'] ?? 'bank_transfer',
            $requestedPaymentAmount,
            $_POST['payee_name'] ?: null,
            $_POST['payee_phone'] ?: null,
            $_POST['bank_name'] ?: null,
            $_POST['bank_account_name'] ?: null,
            $_POST['bank_account_number'] ?: null,
            $_POST['bank_branch'] ?: null,
            $_POST['mpesa_number'] ?: null,
            $_POST['payment_narration'] ?: null,
            $_POST['item_id']
        ]);
        
        if ($result) {
            // Record stock movement
            $movement_type = $new_quantity > $previous_quantity ? 'purchase' : 'adjustment';
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, movement_type, quantity, previous_quantity, new_quantity,
                    unit_price, total_amount, notes, performed_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['item_id'],
                $movement_type,
                abs($new_quantity - $previous_quantity),
                $previous_quantity,
                $new_quantity,
                $item['unit_price'],
                $item['unit_price'] * abs($new_quantity - $previous_quantity),
                $_POST['notes'] ?? 'Stock adjustment',
                $user_id
            ]);
            
            $pdo->commit();
            $_SESSION['success'] = 'Stock update submitted and is awaiting admin approval.';
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error updating stock: ' . $e->getMessage();
    }
}

// Handle stock count
if (($_POST['action'] ?? '') === 'stock_count' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Create stock count record
        $count_code = 'CNT' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("
            INSERT INTO stock_counts (count_code, count_date, count_type, notes, created_by)
            VALUES (?, CURDATE(), ?, ?, ?)
        ");
        $stmt->execute([$count_code, $_POST['count_type'], $_POST['notes'] ?? null, $user_id]);
        $count_id = $pdo->lastInsertId();
        
        // Process count items
        foreach ($_POST['items'] as $item_id => $physical_qty) {
            // Get system quantity
            $stmt = $pdo->prepare("SELECT quantity_in_stock, unit_price FROM inventory_items WHERE id = ?");
            $stmt->execute([$item_id]);
            $item = $stmt->fetch();
            
            $system_qty = $item['quantity_in_stock'];
            $variance = $physical_qty - $system_qty;
            $variance_value = $variance * $item['unit_price'];
            
            // Insert count item
            $stmt = $pdo->prepare("
                INSERT INTO stock_count_items (
                    count_id, item_id, system_quantity, physical_quantity, variance,
                    unit_price, variance_value, notes, counted_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $count_id,
                $item_id,
                $system_qty,
                $physical_qty,
                $variance,
                $item['unit_price'],
                $variance_value,
                $_POST['item_notes'][$item_id] ?? null,
                $user_id
            ]);
            
            // Update inventory if variance exists
            if ($variance != 0) {
                $stmt = $pdo->prepare("
                    UPDATE inventory_items 
                    SET quantity_in_stock = ?, last_count_date = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$physical_qty, $item_id]);
                
                // Record stock movement
                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, movement_type, quantity, previous_quantity, new_quantity,
                        unit_price, total_amount, reference_type, reference_id, notes, performed_by
                    ) VALUES (?, 'adjustment', ?, ?, ?, ?, ?, 'stock_count', ?, ?, ?)
                ");
                $stmt->execute([
                    $item_id,
                    abs($variance),
                    $system_qty,
                    $physical_qty,
                    $item['unit_price'],
                    $variance_value,
                    $count_id,
                    'Stock count adjustment',
                    $user_id
                ]);
            }
        }
        
        // Mark count as completed
        $stmt = $pdo->prepare("
            UPDATE stock_counts 
            SET status = 'completed', completed_by = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $count_id]);
        
        $pdo->commit();
        $_SESSION['success'] = 'Stock count completed successfully.';
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error during stock count: ' . $e->getMessage();
    }
}

// Handle purchase order
if (($_POST['action'] ?? '') === 'create_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_categories (category_name, description, icon, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim((string) ($_POST['category_name'] ?? '')),
            trim((string) ($_POST['description'] ?? '')) ?: null,
            trim((string) ($_POST['icon'] ?? 'fa-tags')) ?: 'fa-tags',
            (int) ($_POST['sort_order'] ?? 0),
            isset($_POST['is_active']) ? 1 : 0
        ]);
        $_SESSION['success'] = 'Inventory category added successfully.';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error creating category: ' . $e->getMessage();
    }
}

// Handle purchase order
if (($_POST['action'] ?? '') === 'create_po' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Generate PO number
        $po_number = 'PO' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Calculate totals
        $subtotal = 0;
        foreach ($_POST['items'] as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }
        $tax = $subtotal * ($_POST['tax_rate'] ?? 0) / 100;
        $total = $subtotal + $tax + ($_POST['shipping'] ?? 0);
        
        // Create PO
        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders (
                po_number, supplier_id, order_date, expected_delivery,
                subtotal, tax_amount, shipping_cost, total_amount,
                notes, created_by, approval_status
            ) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $po_number,
            $_POST['supplier_id'],
            $_POST['expected_delivery'] ?? null,
            $subtotal,
            $tax,
            $_POST['shipping'] ?? 0,
            $total,
            $_POST['notes'] ?? null,
            $user_id
        ]);
        
        $po_id = $pdo->lastInsertId();
        
        // Add PO items
        $stmt = $pdo->prepare("
            INSERT INTO purchase_order_items (
                po_id, item_id, quantity_ordered, unit_price, total_price, notes
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($_POST['items'] as $item_data) {
            $stmt->execute([
                $po_id,
                $item_data['item_id'],
                $item_data['quantity'],
                $item_data['unit_price'],
                $item_data['quantity'] * $item_data['unit_price'],
                $item_data['notes'] ?? null
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Purchase order created successfully and pending approval.';
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error creating purchase order: ' . $e->getMessage();
    }
}

// Handle receive purchase order
if (($_POST['action'] ?? '') === 'receive_po' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Update PO
        $stmt = $pdo->prepare("
            UPDATE purchase_orders 
            SET status = 'received', received_by = ?, received_at = NOW(),
                delivery_date = CURDATE()
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $_POST['po_id']]);
        
        // Process received items
        foreach ($_POST['received_items'] as $item_id => $received_qty) {
            // Update PO item
            $stmt = $pdo->prepare("
                UPDATE purchase_order_items 
                SET quantity_received = ?, status = 'received'
                WHERE po_id = ? AND item_id = ?
            ");
            $stmt->execute([$received_qty, $_POST['po_id'], $item_id]);
            
            // Get item details
            $stmt = $pdo->prepare("
                SELECT poi.unit_price, i.quantity_in_stock 
                FROM purchase_order_items poi
                JOIN inventory_items i ON poi.item_id = i.id
                WHERE poi.po_id = ? AND poi.item_id = ?
            ");
            $stmt->execute([$_POST['po_id'], $item_id]);
            $item = $stmt->fetch();
            
            // Update inventory
            $new_qty = $item['quantity_in_stock'] + $received_qty;
            $stmt = $pdo->prepare("
                UPDATE inventory_items 
                SET quantity_in_stock = ?, last_restock_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_qty, $item_id]);
            
            // Record stock movement
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, movement_type, quantity, previous_quantity, new_quantity,
                    unit_price, total_amount, reference_type, reference_id, performed_by
                ) VALUES (?, 'purchase', ?, ?, ?, ?, ?, 'purchase_order', ?, ?)
            ");
            $stmt->execute([
                $item_id,
                $received_qty,
                $item['quantity_in_stock'],
                $new_qty,
                $item['unit_price'],
                $received_qty * $item['unit_price'],
                $_POST['po_id'],
                $user_id
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Purchase order received and inventory updated.';
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error receiving purchase order: ' . $e->getMessage();
    }
}

// Handle damage/loss reporting
if (($_POST['action'] ?? '') === 'report_damage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Get current quantity
        $stmt = $pdo->prepare("SELECT quantity_in_stock, unit_price FROM inventory_items WHERE id = ?");
        $stmt->execute([$_POST['item_id']]);
        $item = $stmt->fetch();
        
        $damaged_qty = $_POST['quantity'];
        $new_qty = $item['quantity_in_stock'] - $damaged_qty;
        
        // Update item status if completely damaged
        $status = ($new_qty == 0) ? 'damaged' : 'active';
        
        $stmt = $pdo->prepare("
            UPDATE inventory_items 
            SET quantity_in_stock = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_qty, $status, $_POST['item_id']]);
        
        // Record stock movement
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements (
                item_id, movement_type, quantity, previous_quantity, new_quantity,
                unit_price, total_amount, notes, performed_by
            ) VALUES (?, 'damage', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['item_id'],
            $damaged_qty,
            $item['quantity_in_stock'],
            $new_qty,
            $item['unit_price'],
            $damaged_qty * $item['unit_price'],
            $_POST['reason'] ?? 'Damaged goods',
            $user_id
        ]);
        
        $pdo->commit();
        $_SESSION['success'] = 'Damage reported successfully.';
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error reporting damage: ' . $e->getMessage();
    }
}

// Get all inventory items with filters
$query = "
    SELECT i.*,
           requester.full_name AS requested_by_name,
           approver.full_name AS approved_by_name,
           s.company_name AS supplier_name
    FROM inventory_items i
    LEFT JOIN users requester ON i.requested_by = requester.id
    LEFT JOIN users approver ON i.approved_by = approver.id
    LEFT JOIN suppliers s ON i.supplier_id = s.id
    WHERE 1=1
";
$params = [];

if (!empty($_GET['status'])) {
    $query .= " AND i.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['category'])) {
    $query .= " AND i.category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['approval_status'])) {
    $query .= " AND i.approval_status = ?";
    $params[] = $_GET['approval_status'];
}

if (!empty($_GET['payment_status'])) {
    $query .= " AND i.payment_status = ?";
    $params[] = $_GET['payment_status'];
}

if (!empty($_GET['low_stock'])) {
    $query .= " AND i.quantity_in_stock <= i.reorder_level";
}

if (!empty($_GET['search'])) {
    $query .= " AND (i.item_code LIKE ? OR i.item_name LIKE ? OR i.description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$query .= " ORDER BY i.item_name ASC LIMIT 500";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll();

// Get suppliers
$suppliers = $pdo->query("
    SELECT id, company_name, contact_person, phone, email 
    FROM suppliers 
    WHERE status = 'active' 
    ORDER BY company_name
")->fetchAll();

$all_suppliers = $pdo->query("
    SELECT *
    FROM suppliers
    ORDER BY company_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get pending approvals count
$pending_approvals = $pdo->query("
    SELECT COUNT(*) FROM inventory_items WHERE approval_status = 'pending'
")->fetchColumn();

// Get low stock items
$low_stock = $pdo->query("
    SELECT COUNT(*) FROM inventory_items 
    WHERE quantity_in_stock <= reorder_level AND status = 'active'
")->fetchColumn();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_items,
        COUNT(CASE WHEN quantity_in_stock <= reorder_level THEN 1 END) as items_for_reorder,
        SUM(quantity_in_stock * unit_price) as total_value,
        COUNT(CASE WHEN status='active' THEN 1 END) as active_items,
        COUNT(CASE WHEN approval_status='pending' THEN 1 END) as pending_approvals,
        COUNT(CASE WHEN approval_status='approved' AND payment_status='pending' THEN 1 END) as awaiting_payment,
        COUNT(CASE WHEN status='damaged' THEN 1 END) as damaged_items,
        COUNT(CASE WHEN status='lost' THEN 1 END) as lost_items,
        AVG(unit_price) as avg_price,
        MAX(unit_price) as max_price,
        MIN(unit_price) as min_price
    FROM inventory_items
")->fetch();

// Get categories for filter
$categories = $pdo->query("
    SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

// Get recent stock movements
$recent_movements = $pdo->query("
    SELECT sm.*, i.item_name, u.full_name as performed_by_name
    FROM stock_movements sm
    JOIN inventory_items i ON sm.item_id = i.id
    LEFT JOIN users u ON sm.performed_by = u.id
    ORDER BY sm.performed_at DESC
    LIMIT 20
")->fetchAll();

// Get inventory categories
$inventory_categories = $pdo->query("
    SELECT * FROM inventory_categories WHERE is_active = 1 ORDER BY sort_order
")->fetchAll();

$page_title = "Inventory Management - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --success-dark: #3aa8d8;
            --info: #4895ef;
            --warning: #f8961e;
            --warning-dark: #e07c1a;
            --danger: #f94144;
            --danger-dark: #d93235;
            --purple: #7209b7;
            --purple-light: #9b59b6;
            --dark: #2b2d42;
            --dark-light: #34495e;
            --gray: #6c757d;
            --gray-light: #95a5a6;
            --light: #f8f9fa;
            --white: #ffffff;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-5: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
            --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
            --border-radius-sm: 8px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 24px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            transition: var(--transition);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-1);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Page Header */
        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--gradient-1);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .btn-primary {
            background: var(--gradient-1);
            color: white;
        }

        .btn-success {
            background: var(--gradient-3);
            color: white;
        }

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-danger {
            background: var(--gradient-2);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.reorder { border-left-color: var(--warning); }
        .stat-card.value { border-left-color: var(--success); }
        .stat-card.damaged { border-left-color: var(--danger); }
        .stat-card.pending { border-left-color: var(--purple); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Alert Banner */
        .alert-banner {
            background: linear-gradient(135deg, var(--warning), #e07c1a);
            border-radius: var(--border-radius-lg);
            padding: 1rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .alert-banner .btn {
            background: white;
            color: var(--warning);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--light);
            text-decoration: none;
            color: var(--dark);
            display: block;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .action-icon {
            width: 48px;
            height: 48px;
            background: var(--gradient-1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            color: white;
            font-size: 1.2rem;
        }

        .action-title {
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        /* Data Table */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
        }

        .status-discontinued {
            background: rgba(108, 117, 125, 0.3);
            color: var(--gray-dark);
        }

        .status-damaged {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-lost {
            background: rgba(0, 0, 0, 0.15);
            color: var(--dark);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-paid {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        /* Stock Level Indicators */
        .stock-level {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stock-bar {
            width: 60px;
            height: 6px;
            background: var(--light);
            border-radius: 3px;
            overflow: hidden;
        }

        .stock-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--warning), var(--danger));
            border-radius: 3px;
        }

        .stock-low {
            color: var(--danger);
        }

        .stock-normal {
            color: var(--success);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.22s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius-xl);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: var(--shadow-xl);
            transform: translateY(28px) scale(0.98);
            opacity: 0;
            transition: transform 0.26s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.22s ease;
        }

        .modal.active .modal-content {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-wrap: wrap;
            position: sticky;
            bottom: 0;
            background: white;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow-x: auto;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(249, 65, 68, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }

            .modal {
                padding: 0.65rem;
                align-items: center;
            }

            .modal-content {
                width: 100%;
                max-width: 100%;
                max-height: 92vh;
                border-radius: 20px;
                transform: translateY(48px);
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            .modal-header {
                gap: 0.75rem;
            }

            .modal-header h3 {
                font-size: 1.05rem;
                line-height: 1.35;
            }

            .modal-footer {
                justify-content: stretch;
            }

            .modal-footer .btn {
                width: 100%;
            }

            .table-responsive table {
                min-width: 760px;
            }

            #poDetailsContent > div:first-child {
                grid-template-columns: 1fr !important;
            }
        }

        @media (max-width: 520px) {
            .modal {
                padding: 0.5rem;
            }

            .modal-content {
                max-height: 94vh;
                border-radius: 18px;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 0.9rem;
            }

            .form-control {
                padding: 0.7rem;
                font-size: 0.92rem;
            }

            .modal-header h3 {
                font-size: 1rem;
            }

            .amount-cell,
            .po-line-total {
                white-space: nowrap;
            }
        }

        .swal2-container {
            z-index: 3200 !important;
        }

        .swal2-popup.inventory-swal-popup {
            border-radius: 22px !important;
            padding: 1.1rem !important;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28) !important;
        }

        .swal2-popup.inventory-swal-popup .swal2-html-container {
            margin: 1rem 0 0 !important;
        }

        @media (max-width: 768px) {
            .swal2-container {
                padding: 0.75rem !important;
            }

            .swal2-popup.inventory-swal-popup {
                width: 100% !important;
                max-width: 100% !important;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease-out;
        }

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.15s; }
        .stagger-item:nth-child(3) { animation-delay: 0.2s; }
        .stagger-item:nth-child(4) { animation-delay: 0.25s; }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <div>
                <h1><i class="fas fa-boxes"></i> Inventory Management System</h1>
                <p>Complete inventory control with approval workflows and stock tracking</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openAddItemModal()">
                    <i class="fas fa-plus"></i> New Item
                </button>
                <button class="btn btn-success" onclick="openPurchaseOrderModal()">
                    <i class="fas fa-shopping-cart"></i> Purchase Order
                </button>
                <button class="btn btn-outline" onclick="openStockCountModal()">
                    <i class="fas fa-calculator"></i> Stock Count
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Alert Banner for Pending Approvals -->
        <?php if ($pending_approvals > 0): ?>
        <div class="alert-banner animate">
            <div>
                <i class="fas fa-clock"></i>
                <strong><?php echo $pending_approvals; ?> items pending approval</strong> awaiting your review
            </div>
            <a href="?approval_status=pending" class="btn btn-sm">Review Now</a>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions animate">
            <div class="action-card" onclick="openAddItemModal()">
                <div class="action-icon"><i class="fas fa-plus"></i></div>
                <div class="action-title">Add Item</div>
            </div>
            <div class="action-card" onclick="openPurchaseOrderModal()">
                <div class="action-icon"><i class="fas fa-truck"></i></div>
                <div class="action-title">Purchase Order</div>
            </div>
            <div class="action-card" onclick="openStockCountModal()">
                <div class="action-icon"><i class="fas fa-calculator"></i></div>
                <div class="action-title">Stock Count</div>
            </div>
            <div class="action-card" onclick="window.location.href='?low_stock=1'">
                <div class="action-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="action-title">Low Stock (<?php echo $low_stock; ?>)</div>
            </div>
            <div class="action-card" onclick="openSupplierModal()">
                <div class="action-icon"><i class="fas fa-building"></i></div>
                <div class="action-title">Suppliers</div>
            </div>
            <div class="action-card" onclick="window.location.href='reports.php?type=inventory'">
                <div class="action-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="action-title">Reports</div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total stagger-item">
                <div class="stat-number"><?php echo number_format($stats['total_items']); ?></div>
                <div class="stat-label">Total Items</div>
                <div class="stat-detail"><?php echo $stats['active_items']; ?> active</div>
            </div>
            <div class="stat-card reorder stagger-item">
                <div class="stat-number"><?php echo $stats['items_for_reorder']; ?></div>
                <div class="stat-label">Need Reorder</div>
                <div class="stat-detail">Below reorder level</div>
            </div>
            <div class="stat-card value stagger-item">
                <div class="stat-number">KES <?php echo number_format($stats['total_value'], 2); ?></div>
                <div class="stat-label">Inventory Value</div>
                <div class="stat-detail">Avg: KES <?php echo number_format($stats['avg_price'], 2); ?></div>
            </div>
            <div class="stat-card pending stagger-item">
                <div class="stat-number"><?php echo $stats['pending_approvals']; ?></div>
                <div class="stat-label">Pending Approval</div>
                <div class="stat-detail">Awaiting review</div>
            </div>
            <div class="stat-card damaged stagger-item">
                <div class="stat-number"><?php echo $stats['damaged_items'] + $stats['lost_items']; ?></div>
                <div class="stat-label">Damaged/Lost</div>
                <div class="stat-detail"><?php echo $stats['damaged_items']; ?> damaged, <?php echo $stats['lost_items']; ?> lost</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs animate">
            <div class="tab active" onclick="switchTab('inventory')">Inventory Items</div>
            <div class="tab" onclick="switchTab('movements')">Stock Movements</div>
            <div class="tab" onclick="switchTab('purchase')">Purchase Orders</div>
            <div class="tab" onclick="switchTab('categories')">Categories</div>
        </div>

        <!-- Inventory Items Tab -->
        <div id="inventoryTab" class="tab-content active">
            <!-- Filters -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3><i class="fas fa-filter"></i> Filter Inventory</h3>
                    <span class="badge"><?php echo count($inventory_items); ?> items</span>
                </div>
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Item name or code..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" class="form-control">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($_GET['category'] ?? '') === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($_GET['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="damaged" <?php echo ($_GET['status'] ?? '') === 'damaged' ? 'selected' : ''; ?>>Damaged</option>
                                <option value="lost" <?php echo ($_GET['status'] ?? '') === 'lost' ? 'selected' : ''; ?>>Lost</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Approval</label>
                            <select name="approval_status" class="form-control">
                                <option value="">All</option>
                                <option value="pending" <?php echo ($_GET['approval_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo ($_GET['approval_status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo ($_GET['approval_status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment</label>
                            <select name="payment_status" class="form-control">
                                <option value="">All</option>
                                <option value="pending" <?php echo ($_GET['payment_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="paid" <?php echo ($_GET['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-search"></i> Apply
                                </button>
                                <a href="inventory.php" class="btn btn-outline btn-sm">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                                <input type="hidden" name="low_stock" value="<?php echo $_GET['low_stock'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Inventory Table -->
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-box"></i> Inventory Items</h3>
                    <span class="badge">Total Value: KES <?php echo number_format($stats['total_value'], 2); ?></span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Unit Price</th>
                                <th>Stock Level</th>
                                <th>Status</th>
                                <th>Approval</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inventory_items)): ?>
                                <?php foreach ($inventory_items as $item): 
                                    $stock_percentage = min(100, ($item['quantity_in_stock'] / max(1, $item['reorder_level'] * 2)) * 100);
                                    $is_low = $item['quantity_in_stock'] <= $item['reorder_level'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                        <?php if (!empty($item['sub_category'] ?? '')): ?>
                                        <br><small><?php echo htmlspecialchars($item['sub_category'] ?? ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td class="amount-cell">KES <?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td>
                                        <div class="stock-level">
                                            <span class="<?php echo $is_low ? 'stock-low' : 'stock-normal'; ?>">
                                                <i class="fas fa-<?php echo $is_low ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                                                <?php echo $item['quantity_in_stock']; ?> / <?php echo $item['reorder_level']; ?>
                                            </span>
                                            <div class="stock-bar">
                                                <div class="stock-fill" style="width: <?php echo $stock_percentage; ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $item['status']; ?>">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($item['approval_status'] ?? 'approved'); ?>">
                                            <?php echo ucfirst($item['approval_status'] ?? 'approved'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($item['payment_status'] ?? 'pending'); ?>">
                                            <?php echo ucfirst($item['payment_status'] ?? 'pending'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button
                                                class="btn btn-sm btn-primary"
                                                onclick='viewItemDetails(<?php echo json_encode([
                                                    "id" => $item["id"] ?? null,
                                                    "item_code" => $item["item_code"] ?? "",
                                                    "item_name" => $item["item_name"] ?? "",
                                                    "category" => $item["category"] ?? "",
                                                    "sub_category" => $item["sub_category"] ?? "",
                                                    "description" => $item["description"] ?? "",
                                                    "unit_price" => $item["unit_price"] ?? 0,
                                                    "quantity_in_stock" => $item["quantity_in_stock"] ?? 0,
                                                    "reorder_level" => $item["reorder_level"] ?? 0,
                                                    "reorder_quantity" => $item["reorder_quantity"] ?? 0,
                                                    "status" => $item["status"] ?? "",
                                                    "approval_status" => $item["approval_status"] ?? "",
                                                    "payment_status" => $item["payment_status"] ?? "",
                                                    "requested_by_name" => $item["requested_by_name"] ?? "",
                                                    "approved_by_name" => $item["approved_by_name"] ?? "",
                                                    "supplier_name" => $item["supplier_name"] ?? "",
                                                    "location" => $item["location"] ?? "",
                                                    "bank_name" => $item["bank_name"] ?? "",
                                                    "bank_account_name" => $item["bank_account_name"] ?? "",
                                                    "bank_account_number" => $item["bank_account_number"] ?? "",
                                                    "mpesa_number" => $item["mpesa_number"] ?? "",
                                                    "payee_name" => $item["payee_name"] ?? "",
                                                    "requested_payment_method" => $item["requested_payment_method"] ?? "",
                                                    "requested_payment_amount" => $item["requested_payment_amount"] ?? 0,
                                                    "payment_narration" => $item["payment_narration"] ?? "",
                                                    "approval_notes" => $item["approval_notes"] ?? "",
                                                    "created_at" => $item["created_at"] ?? "",
                                                    "updated_at" => $item["updated_at"] ?? ""
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                title="View Details"
                                            >
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick="openUpdateStockModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>', <?php echo $item['quantity_in_stock']; ?>)" title="Update Stock">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="openDamageModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>')" title="Report Damage">
                                                <i class="fas fa-exclamation-triangle"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem; color: var(--gray);">
                                    <i class="fas fa-box-open fa-3x" style="margin-bottom: 1rem; opacity: 0.3;"></i>
                                    <h3>No Inventory Items Found</h3>
                                    <p>Add your first item to get started</p>
                                    <button class="btn btn-primary" onclick="openAddItemModal()">
                                        <i class="fas fa-plus"></i> Add Item
                                    </button>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Stock Movements Tab -->
        <div id="movementsTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Stock Movements</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Previous</th>
                                <th>New</th>
                                <th>Value</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_movements as $movement): ?>
                            <tr>
                                <td><?php echo date('d M H:i', strtotime($movement['performed_at'])); ?></td>
                                <td><?php echo htmlspecialchars($movement['item_name']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php 
                                        echo $movement['movement_type'] == 'purchase' ? 'approved' : 
                                            ($movement['movement_type'] == 'damage' ? 'damaged' : 
                                            ($movement['movement_type'] == 'adjustment' ? 'pending' : 'info')); 
                                    ?>">
                                        <?php echo ucfirst($movement['movement_type']); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo $movement['quantity']; ?></strong></td>
                                <td><?php echo $movement['previous_quantity']; ?></td>
                                <td><?php echo $movement['new_quantity']; ?></td>
                                <td>KES <?php echo number_format($movement['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($movement['performed_by_name'] ?? 'System'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Purchase Orders Tab -->
        <div id="purchaseTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-shopping-cart"></i> Purchase Orders</h3>
                    <button class="btn btn-sm btn-success" onclick="openPurchaseOrderModal()">
                        <i class="fas fa-plus"></i> New PO
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Order Date</th>
                                <th>Expected</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Approval</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pos = $pdo->query("
                                SELECT po.*, s.company_name 
                                FROM purchase_orders po
                                LEFT JOIN suppliers s ON po.supplier_id = s.id
                                ORDER BY po.created_at DESC
                                LIMIT 20
                            ")->fetchAll();
                            ?>
                            <?php foreach ($pos as $po): ?>
                            <?php
                                $poItemsStmt = $pdo->prepare("
                                    SELECT poi.*, i.item_name, i.item_code
                                    FROM purchase_order_items poi
                                    JOIN inventory_items i ON poi.item_id = i.id
                                    WHERE poi.po_id = ?
                                    ORDER BY poi.id ASC
                                ");
                                $poItemsStmt->execute([$po['id']]);
                                $poItems = $poItemsStmt->fetchAll(PDO::FETCH_ASSOC);
                                $poPayload = [
                                    'id' => $po['id'],
                                    'po_number' => $po['po_number'],
                                    'company_name' => $po['company_name'],
                                    'order_date' => $po['order_date'],
                                    'expected_delivery' => $po['expected_delivery'],
                                    'delivery_date' => $po['delivery_date'],
                                    'subtotal' => $po['subtotal'],
                                    'tax_amount' => $po['tax_amount'],
                                    'shipping_cost' => $po['shipping_cost'],
                                    'total_amount' => $po['total_amount'],
                                    'status' => $po['status'],
                                    'approval_status' => $po['approval_status'],
                                    'notes' => $po['notes'],
                                    'items' => $poItems
                                ];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($po['company_name']); ?></td>
                                <td><?php echo date('d M Y', strtotime($po['order_date'])); ?></td>
                                <td><?php echo $po['expected_delivery'] ? date('d M Y', strtotime($po['expected_delivery'])) : '-'; ?></td>
                                <td class="amount-cell">KES <?php echo number_format($po['total_amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $po['status']; ?>">
                                        <?php echo ucfirst($po['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $po['approval_status']; ?>">
                                        <?php echo ucfirst($po['approval_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick='viewPODetails(<?php echo json_encode($poPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($po['status'] == 'ordered'): ?>
                                    <button class="btn btn-sm btn-success" onclick='receivePO(<?php echo json_encode($poPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        <i class="fas fa-truck"></i> Receive
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Categories Tab -->
        <div id="categoriesTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-tags"></i> Inventory Categories</h3>
                    <button class="btn btn-sm btn-primary" onclick="openCategoryModal()">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Icon</th>
                                <th>Items Count</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory_categories as $cat): 
                                $item_count = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category = ?");
                                $item_count->execute([$cat['category_name']]);
                                $count = $item_count->fetchColumn();
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cat['description']); ?></td>
                                <td><i class="fas <?php echo $cat['icon']; ?>"></i> <?php echo $cat['icon']; ?></td>
                                <td><?php echo $count; ?> items</td>
                                <td>
                                    <span class="status-badge status-<?php echo $cat['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Item Modal -->
    <div id="addItemModal" class="modal">
        <div class="modal-content" style="max-width: 980px; width: 96%;">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Inventory Item</h3>
                <button class="modal-close" onclick="closeModal('addItemModal')">&times;</button>
            </div>
            <form method="POST" id="addItemForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="modal-callout" style="margin-bottom: 1rem;">
                        Create a new inventory request with stock, supplier, and payment information so admin approval and accountant payment can continue without missing details.
                    </div>
                    <h4 class="modal-section-title"><i class="fas fa-box-open"></i> Item Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Item Code</label>
                            <input type="text" name="item_code" class="form-control" placeholder="Auto-generated if empty">
                        </div>
                        <div class="form-group">
                            <label class="required">Item Name</label>
                            <input type="text" name="item_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Category</label>
                            <select name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach ($inventory_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Sub Category</label>
                            <input type="text" name="sub_category" class="form-control" placeholder="e.g., Textbooks">
                        </div>
                        <div class="form-group">
                            <label class="required">Unit Price (KES)</label>
                            <input type="number" name="unit_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Initial Stock</label>
                            <input type="number" name="quantity_in_stock" class="form-control" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Reorder Level</label>
                            <input type="number" name="reorder_level" class="form-control" value="10" min="0">
                        </div>
                        <div class="form-group">
                            <label>Reorder Quantity</label>
                            <input type="number" name="reorder_quantity" class="form-control" value="20" min="0">
                        </div>
                        <div class="form-group">
                            <label>Supplier</label>
                            <select name="supplier_id" class="form-control">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['company_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="modal-note">
                                Need a new supplier? <button type="button" class="btn btn-sm btn-outline" onclick="openSupplierModal()" style="margin-top:0.5rem;">Manage Suppliers</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g., Shelf A-12">
                        </div>
                        <div class="form-group">
                            <label>Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="required">Preferred Payment Method</label>
                            <select name="requested_payment_method" class="form-control" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Requested Payment Amount (KES)</label>
                            <input type="number" name="requested_payment_amount" class="form-control" step="0.01" min="0" placeholder="Auto-calculated if left empty">
                        </div>
                        <div class="form-group">
                            <label>Payee Name</label>
                            <input type="text" name="payee_name" class="form-control" placeholder="Supplier or recipient name">
                        </div>
                        <div class="form-group">
                            <label>Payee Phone</label>
                            <input type="text" name="payee_phone" class="form-control" placeholder="2547XXXXXXXX">
                        </div>
                        <div class="form-group">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g., KCB Bank">
                        </div>
                        <div class="form-group">
                            <label>Bank Account Name</label>
                            <input type="text" name="bank_account_name" class="form-control" placeholder="Account holder name">
                        </div>
                        <div class="form-group">
                            <label>Bank Account Number</label>
                            <input type="text" name="bank_account_number" class="form-control" placeholder="Account number">
                        </div>
                        <div class="form-group">
                            <label>Bank Branch</label>
                            <input type="text" name="bank_branch" class="form-control" placeholder="Branch or routing details">
                        </div>
                        <div class="form-group">
                            <label>M-Pesa Number</label>
                            <input type="text" name="mpesa_number" class="form-control" placeholder="2547XXXXXXXX">
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group full-width">
                            <h4 class="modal-section-title" style="margin-top: 0.35rem;"><i class="fas fa-money-check-dollar"></i> Payment Request</h4>
                            <div class="modal-note">These details are forwarded to admin and accountant once the item is submitted.</div>
                        </div>
                        <div class="form-group full-width">
                            <label>Payment Narration</label>
                            <textarea name="payment_narration" class="form-control" rows="2" placeholder="What the accountant should include as narration or payment notes"></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label>Specifications (JSON)</label>
                            <textarea name="specifications" class="form-control" rows="2" placeholder='{"color":"Blue","size":"Large"}'></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label>Warranty Info</label>
                            <textarea name="warranty_info" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addItemModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Item
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Stock Modal -->
    <div id="updateStockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Stock Level</h3>
                <button class="modal-close" onclick="closeModal('updateStockModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="item_id" id="updateItemId">
                <div class="modal-body">
                    <div class="form-group">
                        <label id="itemNameLabel" style="font-size: 1.1rem; color: var(--primary);"></label>
                    </div>
                    <div class="form-group">
                        <label class="required">New Stock Quantity</label>
                        <input type="number" name="quantity" id="newQuantity" class="form-control" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Reason for stock update..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="required">Payment Method</label>
                        <select name="requested_payment_method" class="form-control" required>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Requested Payment Amount (KES)</label>
                        <input type="number" name="requested_payment_amount" class="form-control" step="0.01" min="0" placeholder="Auto-calculate if blank">
                    </div>
                    <div class="form-group">
                        <label>Payee Name</label>
                        <input type="text" name="payee_name" class="form-control" placeholder="Supplier or recipient name">
                    </div>
                    <div class="form-group">
                        <label>Payee Phone</label>
                        <input type="text" name="payee_phone" class="form-control" placeholder="2547XXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Bank Account Name</label>
                        <input type="text" name="bank_account_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Bank Account Number</label>
                        <input type="text" name="bank_account_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Bank Branch</label>
                        <input type="text" name="bank_branch" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>M-Pesa Number</label>
                        <input type="text" name="mpesa_number" class="form-control" placeholder="2547XXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Payment Narration</label>
                        <textarea name="payment_narration" class="form-control" rows="2" placeholder="Explain the payment request for accountant"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('updateStockModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Damage Modal -->
    <div id="damageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Report Damaged/Lost Items</h3>
                <button class="modal-close" onclick="closeModal('damageModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="report_damage">
                <input type="hidden" name="item_id" id="damageItemId">
                <div class="modal-body">
                    <div class="form-group">
                        <label id="damageItemName" style="font-size: 1.1rem; color: var(--primary);"></label>
                    </div>
                    <div class="form-group">
                        <label class="required">Quantity</label>
                        <input type="number" name="quantity" id="damageQuantity" class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Reason</label>
                        <textarea name="reason" class="form-control" rows="2" required placeholder="e.g., Damaged during delivery, Lost, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('damageModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-exclamation-triangle"></i> Report Damage
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="purchaseOrderModal" class="modal">
        <div class="modal-content" style="max-width: 980px; width: 96%;">
            <div class="modal-header">
                <h3><i class="fas fa-shopping-cart"></i> Create Purchase Order</h3>
                <button class="modal-close" onclick="closeModal('purchaseOrderModal')">&times;</button>
            </div>
            <form method="POST" id="purchaseOrderForm">
                <input type="hidden" name="action" value="create_po">
                <div class="modal-body">
                    <div class="modal-callout" style="margin-bottom: 1rem;">
                        Build a supplier purchase order, review the running totals, and submit it into the approval workflow from one place.
                    </div>
                    <h4 class="modal-section-title"><i class="fas fa-file-signature"></i> Purchase Order Details</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Supplier</label>
                            <select name="supplier_id" class="form-control" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo (int) $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['company_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="modal-note">
                                Add or update a supplier without leaving inventory.
                                <button type="button" class="btn btn-sm btn-outline" onclick="openSupplierModal()" style="margin-top:0.5rem;">Manage Suppliers</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Expected Delivery</label>
                            <input type="date" name="expected_delivery" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Tax Rate (%)</label>
                            <input type="number" name="tax_rate" id="poTaxRate" class="form-control" min="0" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label>Shipping (KES)</label>
                            <input type="number" name="shipping" id="poShipping" class="form-control" min="0" step="0.01" value="0">
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Purpose, supplier instructions, or approval notes"></textarea>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin:1rem 0 0.75rem;">
                        <h4 class="modal-section-title" style="margin:0;"><i class="fas fa-list-check"></i> PO Items</h4>
                        <button type="button" class="btn btn-sm btn-success" onclick="addPOItemRow()">
                            <i class="fas fa-plus"></i> Add Item Row
                        </button>
                    </div>

                    <div class="table-responsive modal-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                    <th>Notes</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="poItemsBody">
                                <tr class="po-item-row">
                                    <td>
                                        <select name="items[0][item_id]" class="form-control po-item-select" required onchange="syncPOPrice(this)">
                                            <option value="">Select Item</option>
                                            <?php foreach ($inventory_items as $invItem): ?>
                                            <option value="<?php echo (int) $invItem['id']; ?>" data-price="<?php echo htmlspecialchars((string) ($invItem['unit_price'] ?? 0)); ?>">
                                                <?php echo htmlspecialchars(($invItem['item_name'] ?? 'Item') . ' (' . ($invItem['item_code'] ?? '-') . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="items[0][quantity]" class="form-control po-qty" min="1" value="1" required oninput="updatePOTotals()"></td>
                                    <td><input type="number" name="items[0][unit_price]" class="form-control po-price" min="0" step="0.01" value="0" required oninput="updatePOTotals()"></td>
                                    <td class="amount-cell po-line-total">KES 0.00</td>
                                    <td><input type="text" name="items[0][notes]" class="form-control" placeholder="Optional note"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger" onclick="removePOItemRow(this)"><i class="fas fa-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="modal-summary-grid" style="margin-top: 1rem;">
                        <div class="modal-summary-card"><strong>Subtotal</strong><span id="poSubtotal">KES 0.00</span></div>
                        <div class="modal-summary-card"><strong>Tax</strong><span id="poTax">KES 0.00</span></div>
                        <div class="modal-summary-card"><strong>Shipping</strong><span id="poShippingValue">KES 0.00</span></div>
                        <div class="modal-summary-card"><strong>Total</strong><span id="poGrandTotal">KES 0.00</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('purchaseOrderModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Create PO
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="stockCountModal" class="modal">
        <div class="modal-content" style="max-width: 980px; width: 96%;">
            <div class="modal-header">
                <h3><i class="fas fa-calculator"></i> Stock Count</h3>
                <button class="modal-close" onclick="closeModal('stockCountModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="stock_count">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Count Type</label>
                            <select name="count_type" class="form-control" required>
                                <option value="partial">Partial Count</option>
                                <option value="full">Full Count</option>
                                <option value="cycle">Cycle Count</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Summarize what is being counted or reconciled"></textarea>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>System Qty</th>
                                    <th>Physical Qty</th>
                                    <th>Variance</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory_items as $countItem): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($countItem['item_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($countItem['item_code']); ?></small>
                                    </td>
                                    <td><?php echo (int) $countItem['quantity_in_stock']; ?></td>
                                    <td>
                                        <input
                                            type="number"
                                            name="items[<?php echo (int) $countItem['id']; ?>]"
                                            class="form-control stock-count-input"
                                            data-system-qty="<?php echo (int) $countItem['quantity_in_stock']; ?>"
                                            data-variance-target="variance-<?php echo (int) $countItem['id']; ?>"
                                            value="<?php echo (int) $countItem['quantity_in_stock']; ?>"
                                            min="0"
                                            required
                                        >
                                    </td>
                                    <td id="variance-<?php echo (int) $countItem['id']; ?>">0</td>
                                    <td>
                                        <input type="text" name="item_notes[<?php echo (int) $countItem['id']; ?>]" class="form-control" placeholder="Optional note">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('stockCountModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-clipboard-check"></i> Complete Count</button>
                </div>
            </form>
        </div>
    </div>

    <div id="categoryModal" class="modal">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Add Inventory Category</h3>
                <button class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_category">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Category Name</label>
                            <input type="text" name="category_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Icon</label>
                            <input type="text" name="icon" class="form-control" value="fa-tags" placeholder="fa-book">
                        </div>
                        <div class="form-group">
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:0.65rem; padding-top:2rem;">
                            <input type="checkbox" name="is_active" id="category_active" checked>
                            <label for="category_active" style="margin:0;">Active Category</label>
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Describe what belongs in this category"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('categoryModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <div id="supplierModal" class="modal">
        <div class="modal-content" style="max-width: 1080px; width: 96%;">
            <div class="modal-header">
                <h3><i class="fas fa-building"></i> Supplier Management</h3>
                <button class="modal-close" onclick="closeModal('supplierModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-callout" style="margin-bottom: 1rem;">
                    Add, edit, or deactivate suppliers here, and the updated list will be available immediately in inventory and purchase order forms.
                </div>

                <form method="POST" id="supplierForm">
                    <input type="hidden" name="action" value="save_supplier">
                    <input type="hidden" name="supplier_id" id="supplierIdField">
                    <div class="modal-summary-grid" style="margin-bottom: 1rem;">
                        <div class="modal-summary-card"><strong>Mode</strong><span id="supplierFormMode">Add New Supplier</span></div>
                        <div class="modal-summary-card"><strong>Tip</strong>Inactive suppliers stay in history but won’t appear in active dropdowns.</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Company Name</label>
                            <input type="text" name="company_name" id="supplierCompanyName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Contact Person</label>
                            <input type="text" name="contact_person" id="supplierContactPerson" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" id="supplierEmail" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" id="supplierPhone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Alternative Phone</label>
                            <input type="text" name="alternative_phone" id="supplierAltPhone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Tax Number</label>
                            <input type="text" name="tax_number" id="supplierTaxNumber" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Lead Time (Days)</label>
                            <input type="number" name="lead_time_days" id="supplierLeadTime" class="form-control" min="0" value="7">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="supplierStatus" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Address</label>
                            <textarea name="address" id="supplierAddress" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label>Payment Terms</label>
                            <textarea name="payment_terms" id="supplierPaymentTerms" class="form-control" rows="2" placeholder="e.g., 30 days, full payment on delivery"></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" id="supplierNotes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="position: static; padding: 1rem 0 0; border-top: 0; background: transparent;">
                        <button type="button" class="btn btn-outline" onclick="resetSupplierForm()">Reset</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Supplier</button>
                    </div>
                </form>

                <div style="margin-top: 1.25rem;">
                    <h4 class="modal-section-title"><i class="fas fa-address-book"></i> Existing Suppliers</h4>
                    <div class="table-responsive modal-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Supplier</th>
                                    <th>Contact</th>
                                    <th>Terms</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_suppliers as $supplier): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($supplier['company_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($supplier['supplier_code']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($supplier['contact_person'] ?: '-'); ?><br>
                                        <small><?php echo htmlspecialchars($supplier['phone'] ?: ($supplier['email'] ?: '-')); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplier['payment_terms'] ?: 'Not set'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $supplier['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                            <?php echo ucfirst($supplier['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary"
                                                onclick='editSupplier(<?php echo json_encode($supplier, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            >
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmDeleteSupplier('<?php echo htmlspecialchars(addslashes($supplier['company_name'])); ?>');">
                                                <input type="hidden" name="action" value="delete_supplier">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int) $supplier['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('supplierModal')">Close</button>
            </div>
        </div>
    </div>

    <div id="poDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 920px; width: 96%;">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Purchase Order Details</h3>
                <button class="modal-close" onclick="closeModal('poDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="poDetailsContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('poDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <div id="receivePOModal" class="modal">
        <div class="modal-content" style="max-width: 920px; width: 96%;">
            <div class="modal-header">
                <h3><i class="fas fa-truck"></i> Receive Purchase Order</h3>
                <button class="modal-close" onclick="closeModal('receivePOModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="receive_po">
                <input type="hidden" name="po_id" id="receivePoId">
                <div class="modal-body">
                    <div id="receivePOSummary" style="margin-bottom: 1rem;"></div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Ordered</th>
                                    <th>Already Received</th>
                                    <th>Receive Now</th>
                                </tr>
                            </thead>
                            <tbody id="receivePOItemsBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('receivePOModal')">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-box-open"></i> Confirm Receipt</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'inventory') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('inventoryTab').classList.add('active');
            } else if (tabName === 'movements') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('movementsTab').classList.add('active');
            } else if (tabName === 'purchase') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('purchaseTab').classList.add('active');
            } else if (tabName === 'categories') {
                document.querySelectorAll('.tab')[3].classList.add('active');
                document.getElementById('categoriesTab').classList.add('active');
            }
        }

        // Modal Functions
        function openAddItemModal() {
            document.getElementById('addItemModal').classList.add('active');
        }

        function openUpdateStockModal(itemId, itemName, currentQty) {
            document.getElementById('updateItemId').value = itemId;
            document.getElementById('itemNameLabel').innerHTML = '<strong>Item:</strong> ' + itemName;
            document.getElementById('newQuantity').value = currentQty;
            document.getElementById('updateStockModal').classList.add('active');
        }

        function openDamageModal(itemId, itemName) {
            document.getElementById('damageItemId').value = itemId;
            document.getElementById('damageItemName').innerHTML = '<strong>Item:</strong> ' + itemName;
            document.getElementById('damageQuantity').value = 1;
            document.getElementById('damageModal').classList.add('active');
        }

        function openPurchaseOrderModal() {
            document.getElementById('purchaseOrderModal').classList.add('active');
            updatePOTotals();
        }

        function openStockCountModal() {
            document.getElementById('stockCountModal').classList.add('active');
            updateStockCountVariance();
        }

        function openCategoryModal() {
            document.getElementById('categoryModal').classList.add('active');
        }

        function openSupplierModal() {
            document.getElementById('supplierModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function resetSupplierForm() {
            const form = document.getElementById('supplierForm');
            if (!form) return;
            form.reset();
            document.getElementById('supplierIdField').value = '';
            document.getElementById('supplierLeadTime').value = 7;
            document.getElementById('supplierStatus').value = 'active';
            document.getElementById('supplierFormMode').textContent = 'Add New Supplier';
        }

        function editSupplier(supplier) {
            openSupplierModal();
            document.getElementById('supplierIdField').value = supplier.id || '';
            document.getElementById('supplierCompanyName').value = supplier.company_name || '';
            document.getElementById('supplierContactPerson').value = supplier.contact_person || '';
            document.getElementById('supplierEmail').value = supplier.email || '';
            document.getElementById('supplierPhone').value = supplier.phone || '';
            document.getElementById('supplierAltPhone').value = supplier.alternative_phone || '';
            document.getElementById('supplierTaxNumber').value = supplier.tax_number || '';
            document.getElementById('supplierLeadTime').value = supplier.lead_time_days || 7;
            document.getElementById('supplierStatus').value = supplier.status || 'active';
            document.getElementById('supplierAddress').value = supplier.address || '';
            document.getElementById('supplierPaymentTerms').value = supplier.payment_terms || '';
            document.getElementById('supplierNotes').value = supplier.notes || '';
            document.getElementById('supplierFormMode').textContent = 'Edit Supplier';
        }

        function confirmDeleteSupplier(name) {
            return window.confirm('Delete supplier "' + name + '"? If it is already used, it will be marked inactive instead.');
        }

        function addPOItemRow() {
            const body = document.getElementById('poItemsBody');
            const firstRow = body.querySelector('.po-item-row');
            if (!firstRow) return;

            const index = body.querySelectorAll('.po-item-row').length;
            const clone = firstRow.cloneNode(true);
            clone.querySelectorAll('select, input').forEach((field) => {
                const name = field.getAttribute('name') || '';
                field.setAttribute('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                if (field.tagName === 'SELECT') {
                    field.selectedIndex = 0;
                } else if (field.classList.contains('po-qty')) {
                    field.value = 1;
                } else if (field.classList.contains('po-price')) {
                    field.value = 0;
                } else {
                    field.value = '';
                }
            });
            clone.querySelector('.po-line-total').textContent = 'KES 0.00';
            body.appendChild(clone);
            updatePOTotals();
        }

        function removePOItemRow(button) {
            const body = document.getElementById('poItemsBody');
            const rows = body.querySelectorAll('.po-item-row');
            if (rows.length <= 1) {
                const row = rows[0];
                row.querySelectorAll('select, input').forEach((field) => {
                    if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                    } else if (field.classList.contains('po-qty')) {
                        field.value = 1;
                    } else if (field.classList.contains('po-price')) {
                        field.value = 0;
                    } else {
                        field.value = '';
                    }
                });
                row.querySelector('.po-line-total').textContent = 'KES 0.00';
            } else {
                button.closest('.po-item-row').remove();
            }
            updatePOTotals();
        }

        function syncPOPrice(select) {
            const row = select.closest('.po-item-row');
            if (!row) return;
            const priceInput = row.querySelector('.po-price');
            const option = select.options[select.selectedIndex];
            const price = parseFloat(option?.getAttribute('data-price') || '0');
            if (priceInput) {
                priceInput.value = price.toFixed(2);
            }
            updatePOTotals();
        }

        function updatePOTotals() {
            const rows = document.querySelectorAll('#poItemsBody .po-item-row');
            let subtotal = 0;

            rows.forEach((row) => {
                const qty = parseFloat(row.querySelector('.po-qty')?.value || '0');
                const price = parseFloat(row.querySelector('.po-price')?.value || '0');
                const total = qty * price;
                subtotal += total;
                const line = row.querySelector('.po-line-total');
                if (line) {
                    line.textContent = 'KES ' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            });

            const taxRate = parseFloat(document.getElementById('poTaxRate')?.value || '0');
            const shipping = parseFloat(document.getElementById('poShipping')?.value || '0');
            const tax = subtotal * taxRate / 100;
            const grandTotal = subtotal + tax + shipping;

            document.getElementById('poSubtotal').textContent = 'KES ' + subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('poTax').textContent = 'KES ' + tax.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('poShippingValue').textContent = 'KES ' + shipping.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('poGrandTotal').textContent = 'KES ' + grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateStockCountVariance() {
            document.querySelectorAll('.stock-count-input').forEach((input) => {
                const systemQty = parseFloat(input.getAttribute('data-system-qty') || '0');
                const physicalQty = parseFloat(input.value || '0');
                const variance = physicalQty - systemQty;
                const targetId = input.getAttribute('data-variance-target');
                const target = document.getElementById(targetId);
                if (target) {
                    target.textContent = variance > 0 ? '+' + variance : String(variance);
                    target.style.color = variance === 0 ? '' : (variance > 0 ? '#16a34a' : '#dc2626');
                    target.style.fontWeight = variance === 0 ? '500' : '700';
                }
            });
        }

        function viewItemDetails(item) {
            const paymentAmount = Number(item.requested_payment_amount || 0) > 0
                ? Number(item.requested_payment_amount || 0)
                : Number(item.unit_price || 0) * Number(item.quantity_in_stock || 0);

            const safe = (value) => {
                if (value === null || value === undefined || value === '') {
                    return '-';
                }
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };

            Swal.fire({
                title: 'Item Details',
                width: 760,
                customClass: {
                    popup: 'inventory-swal-popup'
                },
                confirmButtonText: 'Close',
                html: `
                    <div style="text-align:left; display:grid; gap:14px;">
                        <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px;">
                            <div><strong>Item Name</strong><br>${safe(item.item_name)}</div>
                            <div><strong>Item Code</strong><br>${safe(item.item_code)}</div>
                            <div><strong>Category</strong><br>${safe(item.category)}</div>
                            <div><strong>Sub Category</strong><br>${safe(item.sub_category)}</div>
                            <div><strong>Unit Price</strong><br>KES ${Number(item.unit_price || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
                            <div><strong>Stock</strong><br>${safe(item.quantity_in_stock)} units</div>
                            <div><strong>Reorder Level</strong><br>${safe(item.reorder_level)}</div>
                            <div><strong>Status</strong><br>${safe(item.status)}</div>
                            <div><strong>Approval</strong><br>${safe(item.approval_status)}</div>
                            <div><strong>Payment</strong><br>${safe(item.payment_status)}</div>
                            <div><strong>Requested By</strong><br>${safe(item.requested_by_name)}</div>
                            <div><strong>Approved By</strong><br>${safe(item.approved_by_name)}</div>
                            <div><strong>Supplier</strong><br>${safe(item.supplier_name)}</div>
                            <div><strong>Location</strong><br>${safe(item.location)}</div>
                            <div><strong>Preferred Payment</strong><br>${safe(item.requested_payment_method)}</div>
                            <div><strong>Requested Amount</strong><br>KES ${paymentAmount.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
                            <div><strong>Payee Name</strong><br>${safe(item.payee_name)}</div>
                            <div><strong>M-Pesa Number</strong><br>${safe(item.mpesa_number)}</div>
                            <div><strong>Bank Name</strong><br>${safe(item.bank_name)}</div>
                            <div><strong>Bank Account</strong><br>${safe(item.bank_account_name)} ${item.bank_account_number ? '(' + safe(item.bank_account_number) + ')' : ''}</div>
                        </div>
                        <div><strong>Description</strong><br>${safe(item.description)}</div>
                        <div><strong>Payment Narration</strong><br>${safe(item.payment_narration)}</div>
                        <div><strong>Approval Notes</strong><br>${safe(item.approval_notes)}</div>
                    </div>
                `
            });
        }

        function viewPODetails(po) {
            const safe = (value) => {
                if (value === null || value === undefined || value === '') return '-';
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };

            const itemsHtml = (po.items || []).map((item) => `
                <tr>
                    <td><strong>${safe(item.item_name)}</strong><br><small>${safe(item.item_code)}</small></td>
                    <td>${Number(item.quantity_ordered || 0)}</td>
                    <td>${Number(item.quantity_received || 0)}</td>
                    <td>KES ${Number(item.unit_price || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td>KES ${Number(item.total_price || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td>${safe(item.status)}</td>
                </tr>
            `).join('');

            document.getElementById('poDetailsContent').innerHTML = `
                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; margin-bottom:1rem;">
                    <div><strong>PO Number</strong><br>${safe(po.po_number)}</div>
                    <div><strong>Supplier</strong><br>${safe(po.company_name)}</div>
                    <div><strong>Order Date</strong><br>${safe(po.order_date)}</div>
                    <div><strong>Expected Delivery</strong><br>${safe(po.expected_delivery)}</div>
                    <div><strong>Status</strong><br>${safe(po.status)}</div>
                    <div><strong>Approval</strong><br>${safe(po.approval_status)}</div>
                    <div><strong>Subtotal</strong><br>KES ${Number(po.subtotal || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                    <div><strong>Total</strong><br>KES ${Number(po.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                </div>
                <div style="margin-bottom:1rem;"><strong>Notes</strong><br>${safe(po.notes)}</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Item</th><th>Ordered</th><th>Received</th><th>Unit Price</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody>${itemsHtml || '<tr><td colspan="6" style="text-align:center; padding:1rem;">No PO items found</td></tr>'}</tbody>
                    </table>
                </div>
            `;
            document.getElementById('poDetailsModal').classList.add('active');
        }

        function receivePO(po) {
            document.getElementById('receivePoId').value = po.id || '';
            document.getElementById('receivePOSummary').innerHTML = `
                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0.75rem;">
                    <div><strong>PO Number</strong><br>${po.po_number || '-'}</div>
                    <div><strong>Supplier</strong><br>${po.company_name || '-'}</div>
                </div>
            `;

            const body = document.getElementById('receivePOItemsBody');
            body.innerHTML = '';
            (po.items || []).forEach((item) => {
                const remaining = Math.max(0, Number(item.quantity_ordered || 0) - Number(item.quantity_received || 0));
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><strong>${item.item_name || '-'}</strong><br><small>${item.item_code || '-'}</small></td>
                    <td>${Number(item.quantity_ordered || 0)}</td>
                    <td>${Number(item.quantity_received || 0)}</td>
                    <td><input type="number" name="received_items[${item.item_id}]" class="form-control" min="0" max="${remaining}" value="${remaining}" required></td>
                `;
                body.appendChild(row);
            });

            document.getElementById('receivePOModal').classList.add('active');
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        document.getElementById('poTaxRate')?.addEventListener('input', updatePOTotals);
        document.getElementById('poShipping')?.addEventListener('input', updatePOTotals);
        document.querySelectorAll('.stock-count-input').forEach((input) => {
            input.addEventListener('input', updateStockCountVariance);
        });
    </script>
</body>
</html>
