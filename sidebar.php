<?php
// sidebar.php - Sidebar navigation
$current_role = $_SESSION['user_role'];
?>

<style>
    .sidebar {
        width: 280px;
        background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
        color: white;
        height: calc(100vh - 70px);
        position: fixed;
        top: 70px;
        left: 0;
        overflow-y: auto;
        transition: all 0.3s ease;
        z-index: 999;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }
    
    .sidebar.collapsed {
        width: 70px;
    }
    
    .sidebar-header {
        padding: 1.5rem;
        border-bottom: 1px solid #405365;
        text-align: center;
    }
    
    .sidebar-menu {
        padding: 1rem 0;
    }
    
    .menu-section {
        margin-bottom: 1.5rem;
    }
    
    .menu-title {
        padding: 0.5rem 1.5rem;
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #bdc3c7;
        font-weight: 600;
        letter-spacing: 1px;
    }
    
    .menu-item {
        padding: 0.8rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        color: #ecf0f1;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .menu-item:hover {
        background: rgba(255,255,255,0.1);
        border-left-color: #3498db;
        color: white;
    }
    
    .menu-item.active {
        background: rgba(52, 152, 219, 0.2);
        border-left-color: #3498db;
        color: white;
    }
    
    .menu-item i {
        width: 20px;
        text-align: center;
        font-size: 1.1rem;
    }
    
    .menu-text {
        flex: 1;
    }
    
    .badge {
        background: #e74c3c;
        color: white;
        padding: 0.2rem 0.5rem;
        border-radius: 10px;
        font-size: 0.7rem;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; transform: scale(1.1); }
    }
    
    .badge[style*="background: #e74c3c"] {
        animation: pulse 2s infinite;
    }
    
    .submenu {
        background: rgba(0,0,0,0.1);
        display: none;
    }
    
    .submenu .menu-item {
        padding-left: 3rem;
    }
    
    .menu-item.has-submenu::after {
        content: '\f107';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        transition: transform 0.3s ease;
    }
    
    .menu-item.has-submenu.active::after {
        transform: rotate(180deg);
    }
    
    /* Collapsed sidebar styles */
    .sidebar.collapsed .menu-text,
    .sidebar.collapsed .menu-title,
    .sidebar.collapsed .badge {
        display: none;
    }
    
    .sidebar.collapsed .menu-item {
        justify-content: center;
        padding: 1rem;
    }
    
    .sidebar.collapsed .menu-item.has-submenu::after {
        display: none;
    }
    
    @media (max-width: 1024px) {
        .sidebar {
            transform: translateX(-100%);
            width: min(86vw, 320px);
            border-top-right-radius: 20px;
            border-bottom-right-radius: 20px;
            box-shadow: 10px 0 32px rgba(15, 23, 42, 0.24);
        }
        
        .sidebar.mobile-open {
            transform: translateX(0);
        }

        .sidebar-header {
            position: sticky;
            top: 0;
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
            z-index: 1;
        }

        .menu-item {
            padding: 0.95rem 1.25rem;
            min-height: 48px;
        }
    }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Main Navigation</h3>
    </div>
    
    <div class="sidebar-menu">
        <!-- Dashboard Section -->
        <div class="menu-section">
            <div class="menu-title">Main</div>
            <a href="dashboard.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span class="menu-text">Dashboard</span>
            </a>
        </div>
        
        <?php if ($current_role == 'admin'): ?>
        <!-- Admin Menu -->
        <div class="menu-section">
            <div class="menu-title">Administration</div>
            
            <a href="students.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-text">Students</span>
                <span class="badge"><?php echo getStudentCount(); ?></span>
            </a>
            
            <a href="staff.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'staff.php' ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-teacher"></i>
                <span class="menu-text">Staff Management</span>
            </a>
            
            <a href="classes.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'classes.php' ? 'active' : ''; ?>">
                <i class="fas fa-door-open"></i>
                <span class="menu-text">Classes & Subjects</span>
            </a>
            
            <a href="attendance.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-check"></i>
                <span class="menu-text">Attendance</span>
            </a>

            <a href="academic_calendar.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'academic_calendar.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-days"></i>
                <span class="menu-text">Academic Calendar</span>
            </a>

            <a href="timetable.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'timetable.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span class="menu-text">Timetable</span>
            </a>

            <a href="visitor_logs.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'visitor_logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-shield"></i>
                <span class="menu-text">Visitor Logs</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Examinations</div>
            
            <a href="exams.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'exams.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span class="menu-text">Exams Management</span>
            </a>
            
            <a href="exam_schedules.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'exam_schedules.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i>
                <span class="menu-text">Exam Schedules</span>
            </a>
            
            <a href="marks_entry.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'marks_entry.php' ? 'active' : ''; ?>">
                <i class="fas fa-pen-fancy"></i>
                <span class="menu-text">Marks Entry</span>
            </a>
            
            <a href="exam_analysis.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'exam_analysis.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                <span class="menu-text">Exam Analysis</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Financial</div>
            
            <?php 
                $financeBase = ($current_role === 'accountant') ? './' : './';
                $financePrefix = ($current_role === 'accountant') ? '' : '';
            ?>
            
            <a href="fee_structure_approvals.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'fee_structure_approvals.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span class="menu-text">Fee Structure Approvals</span>
            </a>
            
            <?php 
                // Get pending expenses count for admin notification
                try {
                    $pending_count = $pdo->query("SELECT COUNT(*) as count FROM expenses WHERE status = 'pending'")->fetch()['count'] ?? 0;
                } catch (Exception $e) {
                    $pending_count = 0;
                }
            ?>
            
            <a href="expense_approvals.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'expense_approvals.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-square"></i>
                <span class="menu-text">Expense Approvals</span>
                <?php if ($pending_count > 0): ?>
                <span class="badge" style="background: #e74c3c; animation: pulse 2s infinite;">
                    <?php echo $pending_count; ?>
                </span>
                <?php endif; ?>
            </a>

            <a href="library_fines_approvals.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'library_fines_approvals.php' ? 'active' : ''; ?>">
                <i class="fas fa-book-medical"></i>
                <span class="menu-text">Library Fine Approvals</span>
            </a>

            <a href="school_funds.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'school_funds.php' ? 'active' : ''; ?>">
                <i class="fas fa-building-columns"></i>
                <span class="menu-text">School Funds</span>
            </a>

            <?php
                try {
                    $pending_payroll_runs = $pdo->query("SELECT COUNT(*) FROM payroll_runs WHERE status = 'submitted'")->fetchColumn() ?? 0;
                } catch (Exception $e) {
                    $pending_payroll_runs = 0;
                }
            ?>

            <a href="payroll_approvals.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'payroll_approvals.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-signature"></i>
                <span class="menu-text">Payroll Approvals</span>
                <?php if ($pending_payroll_runs > 0): ?>
                <span class="badge" style="background: #e74c3c; animation: pulse 2s infinite;">
                    <?php echo $pending_payroll_runs; ?>
                </span>
                <?php endif; ?>
            </a>
            
            <a href="mpesa_transactions.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'mpesa_transactions.php' ? 'active' : ''; ?>">
                <i class="fas fa-mobile-alt"></i>
                <span class="menu-text">M-Pesa Transactions</span>
                <span class="badge" style="background: #27ae60; font-size: 0.65rem; padding: 0.15rem 0.4rem; margin-left: 0.25rem;">NEW</span>
            </a>
            
            <a href="financial_dashboard.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'financial_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                <span class="menu-text">Financial Dashboard</span>
            </a>
            
            <a href="inventory.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''; ?>">
                <i class="fas fa-warehouse"></i>
                <span class="menu-text">Inventory Overview</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Library</div>
            
            <a href="library.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'library.php' ? 'active' : ''; ?>">
                <i class="fas fa-book"></i>
                <span class="menu-text">Library Management</span>
            </a>
        </div>
        
        <?php elseif ($current_role == 'teacher'): ?>
        <!-- Teacher Menu -->
        <div class="menu-section">
            <div class="menu-title">Teaching</div>
            
            <a href="my_students.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span class="menu-text">My Students</span>
            </a>
            
            <a href="attendance.php" class="menu-item">
                <i class="fas fa-clipboard-check"></i>
                <span class="menu-text">Take Attendance</span>
            </a>
            
         <a href="timetable.php" class="menu-item">
                <i class="fas fa-calendar-alt"></i>
                <span class="menu-text">Timetable</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Examinations</div>
            
            <a href="exam_marks_entry.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'exam_marks_entry.php' ? 'active' : ''; ?>">
                <i class="fas fa-pen-fancy"></i>
                <span class="menu-text">Exam Marks Entry</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Content</div>
            
            <a href="assignments.php" class="menu-item">
                <i class="fas fa-tasks"></i>
                <span class="menu-text">Assignments</span>
            </a>
        </div>

        
        <?php elseif ($current_role == 'accountant'): ?>
        <!-- Accountant Menu -->
        <div class="menu-section">
            <div class="menu-title">Financial</div>
            
            <a href="fee_structures_manage.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'fee_structures_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i>
                <span class="menu-text">Fee Structures</span>
            </a>

            <a href="mpesa_analysis_dashboard.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'mpesa_analysis_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span class="menu-text">M-Pesa Analysis</span>
            </a>
            
            <a href="invoices.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice"></i>
                <span class="menu-text">Invoices</span>
            </a>
            
            <a href="payments.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span class="menu-text">Payments</span>
            </a>
            
            <a href="expenses.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : ''; ?>">
                <i class="fas fa-receipt"></i>
                <span class="menu-text">Expenses</span>
            </a>

            <a href="school_funds.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'school_funds.php' ? 'active' : ''; ?>">
                <i class="fas fa-building-columns"></i>
                <span class="menu-text">School Funds</span>
            </a>

            <a href="fee_assignments.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'fee_assignments.php' ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i>
                <span class="menu-text">Fee Assignments</span>
            </a>
            
            <a href="student_balances.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'student_balances.php' ? 'active' : ''; ?>">
                <i class="fas fa-balance-scale"></i>
                <span class="menu-text">Student Balances</span>
            </a>
            
            <a href="library_fines_invoices.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'library_fines_invoices.php' ? 'active' : ''; ?>">
                <i class="fas fa-book-open"></i>
                <span class="menu-text">Library Fines Invoices</span>
            </a>

            <a href="inventory_payments.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'inventory_payments.php' ? 'active' : ''; ?>">
                <i class="fas fa-cash-register"></i>
                <span class="menu-text">Inventory Payments</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Payroll</div>

            <a href="payroll_prepare.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'payroll_prepare.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-check-dollar"></i>
                <span class="menu-text">Payroll Preparation</span>
            </a>

            <a href="payroll_prepare.php#recent-runs" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'payroll_prepare.php' ? 'active' : ''; ?>">
                <i class="fas fa-clock-rotate-left"></i>
                <span class="menu-text">Payroll Runs</span>
            </a>
        </div>
        
        <?php elseif ($current_role == 'librarian'): ?>
        <!-- Librarian Menu -->
        <div class="menu-section">
            <div class="menu-title">Library</div>
            
            <a href="books.php" class="menu-item">
                <i class="fas fa-book"></i>
                <span class="menu-text">Book Management</span>
            </a>
            
            <a href="circulations.php" class="menu-item">
                <i class="fas fa-hand-holding"></i>
                <span class="menu-text">Circulation</span>
            </a>
            
            <a href="catalog.php" class="menu-item">
                <i class="fas fa-undo-alt"></i>
                <span class="menu-text">Book Catalog</span>
            </a>

            <a href="fines.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'fines.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-circle-exclamation"></i>
                <span class="menu-text">Library Charges</span>
            </a>

            <a href="inventory.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''; ?>">
                <i class="fas fa-boxes-stacked"></i>
                <span class="menu-text">Inventory</span>
            </a>
            
            <a href="overdue.php" class="menu-item">
                <i class="fas fa-exclamation-triangle"></i>
                <span class="menu-text">Overdue Books</span>
                <span class="badge"><?php echo getOverdueCount(); ?></span>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Support & Documentation -->
        <div class="menu-section">
            <div class="menu-title">Tools & Support</div>
            
            <a href="system_settings.php" class="menu-item">
                <i class="fas fa-cogs"></i>
                <span class="menu-text">M-Pesa Configuration</span>
            </a>
        </div>
        
        <!-- Common Menu Items -->
        <div class="menu-section">
            <div class="menu-title">System</div>
            
            <a href="profile.php" class="menu-item">
                <i class="fas fa-user-cog"></i>
                <span class="menu-text">Profile Settings</span>
            </a>
            
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-line"></i>
                <span class="menu-text">Reports</span>
            </a>
            
            <?php if ($current_role == 'admin'): ?>
            <a href="system_settings.php" class="menu-item">
                <i class="fas fa-cogs"></i>
                <span class="menu-text">System Settings</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Submenu functionality
    document.querySelectorAll('.menu-item.has-submenu').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const submenu = this.nextElementSibling;
            
            // Close other open submenus
            document.querySelectorAll('.submenu').forEach(sub => {
                if (sub !== submenu) {
                    sub.style.display = 'none';
                    sub.previousElementSibling.classList.remove('active');
                }
            });
            
            // Toggle current submenu
            if (submenu.style.display === 'block') {
                submenu.style.display = 'none';
                this.classList.remove('active');
            } else {
                submenu.style.display = 'block';
                this.classList.add('active');
            }
        });
    });
    
    // Auto-collapse sidebar on mobile when clicking a menu item
    document.querySelectorAll('.menu-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && !this.classList.contains('has-submenu')) {
                const href = this.getAttribute('href');
                const isNavigatingLink = href && href !== '#' && !e.defaultPrevented;
                const closeSidebar = () => {
                    document.getElementById('sidebar')?.classList.remove('mobile-open');
                    document.querySelector('.sidebar-backdrop')?.classList.remove('active');
                    document.body.classList.remove('sidebar-mobile-open');
                };

                if (isNavigatingLink) {
                    setTimeout(closeSidebar, 120);
                } else {
                    closeSidebar();
                }
            }
        });
    });
    

</script>

<?php
// Helper functions for dynamic counts
function getStudentCount() {
    global $pdo;
    try {
        return $pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    } catch (Exception $e) {
        return '0';
    }
}

function getOverdueCount() {
    global $pdo;
    try {
        return $pdo->query("SELECT COUNT(*) FROM book_issues WHERE status='Overdue'")->fetchColumn();
    } catch (Exception $e) {
        return '0';
    }
}
?>
