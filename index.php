<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.html');
    exit;
}

$user = $_SESSION['user'];
?>

<html>
<head>
    <meta charset="UTF-8" />
    <meta name="google-site-verification" content="WHDP6LjaDIPWlesHblmHq6Ybgylg5BNHk60gbEK3VZ8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5, user-scalable=yes" />
    <title>Task Management System | TMS V1.2.1</title>
    <!-- Preconnect to Google Fonts domains (speeds up the fetch) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<!-- Load font stylesheet asynchronously (non‑blocking) -->
  <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap">
</noscript>
    <link rel="stylesheet" href="/../css/index.min.css" />
</head>
<body>
    <!-- Inject user data from session -->
    <script>
       window.__tmsUser = <?php echo json_encode($user) ?>;
    </script>

    <div id="app-shell" style="display:none;">
        <!-- SIDEBAR OVERLAY -->
        <div id="sidebar-overlay" onclick="closeSidebar()"></div>

        <!-- SIDEBAR -->
        <nav id="sidebar">
            <div class="sidebar-logo">
                <div class="logo-icon">⬡</div>
                <div>
                    <div class="logo-text">TMS</div>
                    <div class="logo-sub">Task Management</div>
                </div>
            </div>
            <div class="sidebar-nav">
                <div class="nav-section-label" style="margin-top:8px">Account</div>
                <div class="nav-item" data-page="profile" onclick="navigate('profile')">
                    <span class="nav-icon">👤</span> My Profile
                </div>

                <div class="nav-section-label">Workspace</div>
                <div class="nav-item active" data-page="dashboard" onclick="navigate('dashboard')">
                    <span class="nav-icon">⊞</span> Dashboard
                </div>
                <div class="nav-item" data-page="deals" onclick="navigate('deals')">
                    <span class="nav-icon">📋</span> Task
                </div>

                <div class="nav-section-label" style="margin-top:8px">Company Settings</div>
                <div class="nav-item" data-page="contacts" onclick="navigate('contacts')">
                    <span class="nav-icon">👥</span> Add Employee
                </div>

                <div class="nav-item" data-page="employee-directory" onclick="navigate('employee-directory')">
                    <span class="nav-icon">👤</span> Employee
                </div>

                <div class="nav-item" data-page="departments" onclick="navigate('departments')">
                    <span class="nav-icon">🏢</span> Departments
                </div>
                <div class="nav-item" data-page="companies" onclick="navigate('companies')">
                    <span class="nav-icon">🏬</span> Companies
                </div>

                <div class="nav-section-label" style="margin-top:8px">System Settings</div>
                <div class="nav-item" data-page="roles" onclick="navigate('roles')" id="nav-roles">
                    <span class="nav-icon">🛡️</span> Roles
                </div>
                <div class="nav-item" data-page="users" onclick="navigate('users')" id="nav-users">
                    <span class="nav-icon">🔑</span> Users
                </div>

                <div class="nav-section-label" style="margin-top:8px">System Directory</div>
                <div class="nav-item" data-page="tasks" onclick="navigate('tasks')">
                    <span class="nav-icon">📢</span> Announcement
                </div>
                <div class="nav-item" data-page="notes" onclick="navigate('notes')">
                    <span class="nav-icon">◫</span> Notes
                </div>
                <div class="nav-item" data-page="task-activity" onclick="navigate('task-activity')">
                    <span class="nav-icon">🕓</span> Task Activity
                </div>

                <div class="nav-section-label" style="margin-top:8px">Security & Monitoring</div>
                <div class="nav-item" data-page="page-access" onclick="navigate('page-access')" id="nav-page-access">
                    <span class="nav-icon">🧭</span> Page Access
                </div>
                <div class="nav-item" data-page="login-monitoring" onclick="navigate('login-monitoring')" id="nav-login-monitoring">
                    <span class="nav-icon">🔐</span> Login Monitoring
                </div>
            </div>

            <div class="sidebar-footer">
                <span id="sidebar-footer-email"><?= htmlspecialchars($user['email'] ?? '') ?></span>
                Task Management System · TMS V 1.2.1
            </div>
        </nav>

        <!-- ── MAIN ── -->
        <div id="main">
           <!-- TOPBAR -->
<div class="topbar">
    <button class="hamburger" onclick="toggleSidebar()">☰</button>
    <div class="topbar-title-wrap" style="flex:1; min-width:0;">
        <div class="topbar-title" id="topbar-title"></div>
        <span class="topbar-sub" id="sidebar-user-name" style="color:var(--text2);font-weight:600;"></span>
        <div class="topbar-sub" id="topbar-sub"></div>
    </div>
    <div style="display:flex;align-items:center;gap:14px;" id="topbar-actions">
        <div class="live-indicator" id="live-indicator">
            <span class="pulse-dot"></span>
            <span>Live</span>
        </div>
        <!-- Reload Data button with ID -->
        <button class="btn btn-danger" id="reload-data-btn" onclick="reloadData()" style="font-size:0.8rem;">⟳ Reload Data</button>
        <button class="btn btn-ghost" onclick="logout()" style="font-size:0.8rem;">Logout</button>
    </div>
    <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
        <span class="toggle-knob" id="theme-toggle-knob">🌙</span>
    </button>
</div>
            <!-- Dashboard -->
            <div class="page active" id="page-dashboard">
                <div class="page-body">
                    <div class="stats-grid" id="stats-grid"></div>
                    <div class="dashboard-grid">
                        <div class="card">
                            <div class="card-header">
                                <span>Recent Activity</span>
                                <span class="text-muted" style="font-size:0.75rem;font-weight:400;" id="activity-count"></span>
                            </div>
                            <div class="activity-pagination" id="activity-pagination" style="display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border);margin-top:8px;flex-wrap:wrap;gap:8px;">
                                <!-- Pagination will be rendered here -->
                            </div>
                            <br>
                            <div class="card-body">
                                <ul class="activity-list" id="activity-list">
                                    <li class="empty-state" style="padding:40px 20px;">
                                        <span class="es-icon">◌</span>
                                        <p>No activity yet. Start by adding employees or tasks.</p>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <span>Upcoming Announcements</span>
                            </div>
                            <div class="card-body" id="upcoming-tasks"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employee Directory -->
            <div class="page" id="page-employee-directory">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
                            <div class="toolbar" style="flex:1;flex-wrap:wrap;gap:8px;">
                                <div class="search-wrap" style="min-width:150px;flex:1;">
                                    <span class="search-icon">🔍</span>
                                    <input type="text" id="ed-search" placeholder="Search employees..." oninput="filterEmployeeDirectory()">
                                </div>
                                <select class="filter-select" id="ed-department-filter" onchange="filterEmployeeDirectory()">
                                    <option value="">All Departments</option>
                                </select>
                                <select class="filter-select" id="ed-company-filter" onchange="filterEmployeeDirectory()">
                                    <option value="">All Companies</option>
                                </select>
                                <select class="filter-select" id="ed-role-filter" onchange="filterEmployeeDirectory()">
                                    <option value="">All Roles</option>
                                </select>
                                <select class="filter-select" id="ed-status-filter" onchange="filterEmployeeDirectory()">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">In-Active</option>
                                </select>
                            </div>
                            <button class="btn btn-ghost" onclick="resetEmployeeDirectoryFilters()">🔄 Reset Filters</button>
                        </div>
                    </div>

                    <div class="stats-grid" id="ed-stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">👤</div>
                            <div class="stat-info">
                                <h4>Total Employees</h4>
                                <span class="stat-number" id="ed-total-count">0</span>
                            </div>
                        </div>
                        <div class="stat-card" style="--card-accent:var(--green);">
                            <div class="stat-icon">✅</div>
                            <div class="stat-info">
                                <h4>Active</h4>
                                <span class="stat-number" id="ed-active-count">0</span>
                            </div>
                        </div>
                        <div class="stat-card" style="--card-accent:var(--red);">
                            <div class="stat-icon">⛔</div>
                            <div class="stat-info">
                                <h4>In-Active</h4>
                                <span class="stat-number" id="ed-inactive-count">0</span>
                            </div>
                        </div>
                        <div class="stat-card" style="--card-accent:var(--accent);">
                            <div class="stat-icon">🟢</div>
                            <div class="stat-info">
                                <h4>Online Now</h4>
                                <span class="stat-number" id="ed-online-count">0</span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header" style="justify-content:space-between;">
                            <span>Employee Directory</span>
                            <span class="text-muted" style="font-size:0.75rem;" id="ed-count-label">0 employees</span>
                        </div>
                        <div class="table-wrap" style="max-height:600px;overflow-y:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Company</th>
                                        <th>Role</th>
                                        <th>Last Active</th>
                                        <th>Duration</th>
                                        <th>Last Login</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="ed-tbody">
                                    <tr>
                                        <td colspan="8"><div class="empty-state"><span class="es-icon">👤</span><h3>No employees found</h3><p>Employees will appear here as they are added.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contacts -->
            <div class="page" id="page-contacts">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
                            <div class="toolbar" style="flex:1; flex-wrap:wrap; gap:8px;">
                                <div class="search-wrap" style="min-width:150px; flex:1;">
                                    <span class="search-icon">🔍</span>
                                    <input type="text" id="contact-search" placeholder="Search employees…" oninput="renderContacts()">
                                </div>
                                <select class="filter-select" id="contact-filter-status" onchange="renderContacts()">
                                    <option value="">All Statuses</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">In-Active</option>
                                </select>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span class="text-muted" style="font-size:0.75rem;" id="contact-count-label">0 employees</span>
                                <button class="btn btn-primary" onclick="openContactModal()">+ Add Employee</button>
                                <button class="btn btn-primary" onclick="exportContactsReport()">📊 Export Report</button>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Company</th>
                                        <th>Department</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Added</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="contacts-tbody">
                                    <tr>
                                        <td colspan="9"><div class="empty-state"><span class="es-icon">👤</span><h3>No employees yet</h3><p>Click "Add Employee" to create your first one.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Deals (Kanban) -->
            <div class="page" id="page-deals">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
                            <div class="toolbar" style="flex:1; flex-wrap:wrap; gap:8px;">
                                <div class="search-wrap" style="min-width:150px; flex:1;">
                                    <span class="search-icon">🔍</span>
                                    <input type="text" id="kanban-search" placeholder="Search tasks…" oninput="renderKanban()">
                                </div>
                                <select class="filter-select" id="kanban-filter-status" onchange="renderKanban()">
                                    <option value="">All Tasks</option>
                                    <option value="overdue">Overdue</option>
                                    <option value="completed">Completed</option>
                                    <option value="high">High Priority</option>
                                </select>
                            </div>
                            <button class="btn btn-primary" id="deals-add-btn" onclick="openDealModal()">+ Add Task</button>
                        </div>
                        <div class="pipeline-summary" id="pipeline-summary" style="margin-top:8px;"></div>
                    </div>
                    <div class="kanban-board" id="kanban-board"></div>
                </div>
            </div>

            <!-- Tasks (Announcements) -->
            <div class="page" id="page-tasks">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div class="toolbar">
                            <div class="search-wrap">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="task-search" placeholder="Search announcements…" oninput="renderTasks()">
                            </div>
                            <select class="filter-select" id="task-filter" onchange="renderTasks()">
                                <option value="">All Announcements</option>
                                <option value="todo">Unpublished</option>
                                <option value="done">Published</option>
                                <option value="high">High Priority</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" id="tasks-add-btn" onclick="openTaskModal()">+ Add Announcement</button>
                    </div>
                    <div class="task-list" id="task-list"></div>
                </div>
            </div>

            <!-- Notes -->
            <div class="page" id="page-notes">
                <div class="page-body" style="padding-bottom:0;">
                    <div class="section-toolbar" style="margin-bottom:14px;">
                        <div class="toolbar">
                            <div class="search-wrap">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="note-search" placeholder="Search notes…" oninput="renderNotes()">
                            </div>
                        </div>
                        <button class="btn btn-primary" onclick="openNoteModal()">+ Add Note</button>
                    </div>
                    <div class="notes-layout">
                        <div class="notes-list-pane">
                            <div class="notes-list-header">
                                <span>All Notes</span>
                                <span class="text-muted" style="font-size:0.75rem;" id="notes-count">0</span>
                            </div>
                            <div id="notes-list"></div>
                        </div>
                        <div class="notes-detail-pane" id="note-detail">
                            <div class="empty-state" style="height:100%;">
                                <span class="es-icon">◫</span>
                                <h3>Select a note</h3>
                                <p>Choose a note from the left to view its content.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Activity -->
            <div class="page" id="page-task-activity">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
                            <div class="toolbar" style="flex:1; flex-wrap:wrap; gap:8px;">
                                <div class="search-wrap" style="min-width:150px; flex:1;">
                                    <span class="search-icon">🔍</span>
                                    <input type="text" id="ta-search-title" placeholder="Search by task title…" oninput="renderTaskActivity(1)">
                                </div>
                                <div class="search-wrap" style="min-width:150px; flex:1;">
                                    <span class="search-icon">🔍</span>
                                    <input type="text" id="ta-search-employee" placeholder="Search by employee name…" oninput="renderTaskActivity(1)">
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span class="text-muted" style="font-size:0.75rem;" id="ta-count">0 records</span>
                                <button class="btn btn-primary" onclick="exportTaskActivityReport()">📊 Export Report</button>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header" style="justify-content:flex-end; gap:8px;" id="ta-pagination"></div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date &amp; Time</th>
                                        <th>Task</th>
                                        <th>Employee</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody id="ta-tbody">
                                    <tr>
                                        <td colspan="5"><div class="empty-state"><span class="es-icon">🕓</span><h3>No activity yet</h3><p>Task activity will appear here once tasks are created or moved.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Departments -->
            <div class="page" id="page-departments">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div class="toolbar">
                            <div class="search-wrap">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="department-search" placeholder="Search departments…" oninput="renderDepartments()">
                            </div>
                            <select class="filter-select" id="department-filter-status" onchange="renderDepartments()">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">In-Active</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="openDepartmentModal()">+ Add Department</button>
                    </div>
                    <div class="card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Company</th>
                                        <th>Manager</th>
                                        <th>Employees</th>
                                        <th>Status</th>
                                        <th>Added</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="departments-tbody">
                                    <tr>
                                        <td colspan="8"><div class="empty-state"><span class="es-icon">🏢</span><h3>No departments yet</h3><p>Click "Add Department" to create your first one.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Roles -->
            <div class="page" id="page-roles">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div class="toolbar">
                            <div class="search-wrap">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="role-search" placeholder="Search roles…" oninput="renderRoles()">
                            </div>
                            <select class="filter-select" id="role-filter-status" onchange="renderRoles()">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">In-Active</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="openRoleModal()">+ Add Role</button>
                    </div>
                    <div class="card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Role Name</th>
                                        <th>Description</th>
                                        <th>Employees</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="roles-tbody">
                                    <tr>
                                        <td colspan="6"><div class="empty-state"><span class="es-icon">🛡️</span><h3>No roles yet</h3><p>Click "Add Role" to create your first one.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users -->
            <div class="page" id="page-users">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div class="toolbar">
                            <div class="search-wrap">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="user-search" placeholder="Search users…" oninput="renderUsers()">
                            </div>
                            <select class="filter-select" id="user-filter-status" onchange="renderUsers()">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">In-Active</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="openUserModal()">+ Register User</button>
                    </div>
                    <div class="card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Employee</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="users-tbody">
                                    <tr>
                                        <td colspan="6"><div class="empty-state"><span class="es-icon">🔑</span><h3>No users yet</h3><p>Click "Register User" to create your first one.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page Access -->
            <div class="page" id="page-page-access">
                <div class="page-body">
                    <div class="section-toolbar">
                        <p style="font-size:0.85rem;color:var(--text3);margin:0;max-width:640px;">Dashboard and My Profile are always visible to everyone. Everything else here can be shown or hidden per role — including inherited roles. System Administrator always sees every page.</p>
                        <button class="btn btn-primary" onclick="savePageAccess()">Save Changes</button>
                    </div>
                    <div class="card">
                        <div class="table-wrap">
                            <table class="permission-matrix">
                                <thead id="page-access-thead"></thead>
                                <tbody id="page-access-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Companies -->
            <div class="page" id="page-companies">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div class="toolbar">
                            <div class="search-wrap">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="company-search" placeholder="Search companies…" oninput="renderCompanies()">
                            </div>
                            <select class="filter-select" id="company-filter-status" onchange="renderCompanies()">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">In-Active</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="openCompanyModal()">+ Add Company</button>
                    </div>
                    <div class="card">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Industry</th>
                                        <th>Phone</th>
                                        <th>Website</th>
                                        <th>Employees</th>
                                        <th>Status</th>
                                        <th>Added</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="companies-tbody">
                                    <tr>
                                        <td colspan="8"><div class="empty-state"><span class="es-icon">🏬</span><h3>No companies yet</h3><p>Click "Add Company" to create your first one.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Profile -->
            <div class="page" id="page-profile">
                <div class="page-body">
                    <!-- Profile header -->
                    <div class="card" style="margin-bottom:16px;">
                        <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                            <div id="profile-avatar" style="width:64px;height:64px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.4rem;flex-shrink:0;">?</div>
                            <div style="flex:1;min-width:200px;">
                                <div id="profile-fullname" style="font-weight:700;font-size:1.15rem;">—</div>
                                <div style="font-size:0.85rem;color:var(--text3);margin-top:2px;">
                                    <span id="profile-username">—</span>
                                    <span style="margin:0 6px;">·</span>
                                    <span id="profile-role">—</span>
                                </div>
                                <div style="margin-top:8px;" id="profile-status-badge"></div>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-grid">
                        <!-- Employee Details -->
                        <div class="card">
                            <div class="card-header"><span>Employee Details</span></div>
                            <div class="card-body" style="padding:18px 20px;">
                                <div id="profile-employee-details" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:0.85rem;"></div>
                            </div>
                        </div>
                        <!-- Change Password -->
                        <div class="card">
                            <div class="card-header"><span>Change Password</span></div>
                            <div class="card-body" style="padding:18px 20px;">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Current Password *</label>
                                        <input type="password" id="profile-current-password" placeholder="Enter current password">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">New Password *</label>
                                        <input type="password" id="profile-new-password" placeholder="Enter new password">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Confirm New Password *</label>
                                        <input type="password" id="profile-confirm-password" placeholder="Re-enter new password">
                                    </div>
                                </div>
                                <div style="margin-top:14px;text-align:right;">
                                    <button class="btn btn-primary" onclick="changeMyPassword()">Update Password</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-grid" style="margin-top:16px;">
                        <!-- My Active Tasks -->
                        <div class="card">
                            <div class="card-header">
                                <span>My Active Tasks</span>
                                <span class="text-muted" style="font-size:0.75rem;font-weight:400;" id="profile-tasks-count"></span>
                            </div>
                            <div class="card-body" style="padding:16px;">
                                <div id="profile-tasks-list" class="task-list"></div>
                            </div>
                        </div>
                        <!-- Pending Notes -->
                        <div class="card">
                            <div class="card-header">
                                <span>Pending Comments / Revisions</span>
                                <span class="text-muted" style="font-size:0.75rem;font-weight:400;" id="profile-notes-count"></span>
                            </div>
                            <div class="card-body" style="padding:8px 0;">
                                <div id="profile-notes-list"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card" style="margin-top:16px;">
                        <div class="card-header">
                            <span>My Recent Activity</span>
                            <span class="text-muted" style="font-size:0.75rem;font-weight:400;" id="profile-activity-count"></span>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list" id="profile-activity-list"></ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── LOGIN MONITORING PAGE ── -->
            <div class="page" id="page-login-monitoring">
                <div class="page-body">
                    <div class="section-toolbar">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
                            <div class="toolbar" style="flex:1;flex-wrap:wrap;gap:8px;">
                                <div class="search-wrap" style="min-width:150px;flex:1;">
                                    <span class="search-icon">🔍</span>
                                    <input type="text" id="lm-search" placeholder="Search logs..." oninput="filterLoginLogs()">
                                </div>
                                <select class="filter-select" id="lm-status-filter" onchange="filterLoginLogs()">
                                    <option value="">All Status</option>
                                    <option value="success">✅ Success</option>
                                    <option value="failed">❌ Failed</option>
                                </select>
                                <select class="filter-select" id="lm-employee-filter" onchange="filterLoginLogs()">
                                    <option value="">All Employees</option>
                                </select>
                                <select class="filter-select" id="lm-department-filter" onchange="filterLoginLogs()">
                                    <option value="">All Departments</option>
                                </select>
                                <select class="filter-select" id="lm-company-filter" onchange="filterLoginLogs()">
                                    <option value="">All Companies</option>
                                </select>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <input type="date" id="lm-date-from" class="filter-select" style="width:auto;" onchange="filterLoginLogs()">
                                <span style="color:var(--text3);align-self:center;">to</span>
                                <input type="date" id="lm-date-to" class="filter-select" style="width:auto;" onchange="filterLoginLogs()">
                            </div>
                            <button class="btn btn-primary" onclick="exportLoginReport()">📊 Export Report</button>
                            <button class="btn btn-ghost" onclick="clearLoginLogsUI()" title="Clear logs older than 30 days">🗑️ Clean Old</button>
                        </div>
                    </div>

                    <div class="stats-grid" id="lm-stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">📊</div>
                            <div class="stat-info">
                                <h4>Total Logins</h4>
                                <span class="stat-number" id="lm-total">0</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">✅</div>
                            <div class="stat-info">
                                <h4>Success Rate</h4>
                                <span class="stat-number" id="lm-success-rate">0%</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">⚠️</div>
                            <div class="stat-info">
                                <h4>Failed Attempts</h4>
                                <span class="stat-number" id="lm-failed">0</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">👤</div>
                            <div class="stat-info">
                                <h4>Unique Users</h4>
                                <span class="stat-number" id="lm-unique-users">0</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">📅</div>
                            <div class="stat-info">
                                <h4>Last 24 Hours</h4>
                                <span class="stat-number" id="lm-last24">0</span>
                            </div>
                        </div>
                    </div>
                    <div id="lm-pagination" style="display:flex;left-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px;">
                        <!-- Pagination will be rendered here -->
                    </div>
                    <div class="card">
                        <div class="card-header" style="justify-content:space-between;">
                            <span>Login Logs</span>
                            <span class="text-muted" style="font-size:0.75rem;" id="lm-count">0 records</span>
                        </div>
                        <div class="table-wrap" style="max-height:600px;overflow-y:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Login Time</th>
                                        <th>Status</th>
                                        <th>IP Address</th>
                                        <th>Location</th>
                                        <th>Device</th>
                                        <th>Browser</th>
                                        <th>Duration</th>
                                    </tr>
                                </thead>
                                <tbody id="lm-tbody">
                                    <tr>
                                        <td colspan="8"><div class="empty-state"><span class="es-icon">🔐</span><h3>No login logs found</h3><p>Login activity will appear here as users log in.</p></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- end #main -->

        <!-- ===== MODALS ===== -->

        <!-- Contact Modal -->
        <div class="modal-overlay" id="contact-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="contact-modal-title">Add Employee</span>
                    <button class="icon-btn" onclick="closeModal('contact-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">First Name *</label>
                                <input type="text" id="c-fname" placeholder="John">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name *</label>
                                <input type="text" id="c-lname" placeholder="Doe">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Middle Name</label>
                                <input type="text" id="c-mname" placeholder="Doe">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Role</label>
                                <select id="c-role"><option value="">— Select —</option></select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" id="c-email" placeholder="john@example.com">
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Phone</label>
                                <input type="tel" id="c-phone" placeholder="+1 555 000 0000">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Company</label>
                                <select id="c-company"><option value="">— Select —</option></select>
                            </div>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <select id="c-department" onchange="syncContactCompanyFromDepartment()"><option value="">— Select —</option></select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select id="c-status"><option value="active">Active</option><option value="inactive">In-Active</option></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('contact-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveContact()">Save Employee</button>
                </div>
            </div>
        </div>

        <!-- Task Modal (Deal) -->
        <div class="modal-overlay" id="deal-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="deal-modal-title">Add Task</span>
                    <button class="icon-btn" onclick="closeModal('deal-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Task Title *</label>
                            <input type="text" id="d-title" placeholder="Prepare quarterly report">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea id="d-desc" placeholder="Task details…" style="min-height:70px;"></textarea>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Due Date *</label>
                                <input type="date" id="d-due">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Priority</label>
                                <select id="d-priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select id="d-department" onchange="onDepartmentChangeForDeal()"><option value="">— Select —</option></select>
                            <small style="color:var(--text3);">Auto-assigns all employees in this department to the task.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Assign To</label>
                            <div id="d-contact-list" style="display:flex;flex-direction:column;gap:6px;"></div>
                            <button type="button" class="btn btn-ghost" style="margin-top:8px;width:100%;" onclick="addDealContactRow()">+ Add Assign To</button>
                            <small style="color:var(--text3);">Department employees are auto-assigned. Use "+ Add Assign To" to add others.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select id="d-stage"></select>
                        </div>
                        <div class="form-group" id="deal-modal-attachments-group" style="display:none;">
                            <label class="form-label">Files &amp; Attachments</label>
                            <div id="deal-modal-attachments-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;"></div>
                            <button type="button" class="btn btn-ghost" id="deal-modal-upload-btn" style="width:100%;" onclick="openUploadFromDealModal()">⬆ Upload File</button>
                        </div>
                        <div class="form-group" id="deal-modal-attachments-hint" style="display:none;">
                            <small style="color:var(--text3);">Save this task first, then reopen it to attach files.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('deal-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveDeal()">Save Task</button>
                </div>
            </div>
        </div>

        <!-- Stage Modal -->
        <div class="modal-overlay" id="stage-modal">
            <div class="modal" style="max-width:380px;">
                <div class="modal-header">
                    <span id="stage-modal-title">Add Stage</span>
                    <button class="icon-btn" onclick="closeModal('stage-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Stage Name *</label>
                        <input type="text" id="stage-name" placeholder="e.g. QA Testing">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <div class="stage-color-picker" id="stage-color-picker"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Visible To Roles</label>
                        <div id="stage-role-list" style="display:flex;flex-direction:column;gap:6px;"></div>
                        <button type="button" class="btn btn-ghost" style="margin-top:8px;width:100%;" onclick="addStageRoleRow()">+ Add Role</button>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                        <input type="checkbox" id="stage-final" style="width:16px;height:16px;">
                        <label class="form-label" for="stage-final" style="margin:0;cursor:pointer;">Treat tasks here as completed</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" id="stage-delete-btn" style="margin-right:auto;display:none;" onclick="deleteStageFromModal()">Delete Stage</button>
                    <button class="btn btn-ghost" onclick="closeModal('stage-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveStage()">Save Stage</button>
                </div>
            </div>
        </div>

        <!-- Announcement Modal -->
        <div class="modal-overlay" id="task-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="task-modal-title">Add Announcement</span>
                    <button class="icon-btn" onclick="closeModal('task-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Announcement Title *</label>
                            <input type="text" id="t-title" placeholder="Company-wide update">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Message</label>
                            <textarea id="t-desc" placeholder="Announcement details…" style="min-height:70px;"></textarea>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Due Date *</label>
                                <input type="date" id="t-due">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Priority</label>
                                <select id="t-priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Posted By</label>
                                <input type="text" id="t-assignee" placeholder="e.g. HR Department">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Related Employee</label>
                                <select id="t-contact"><option value="">— None —</option></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('task-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveTask()">Save Announcement</button>
                </div>
            </div>
        </div>

        <!-- Deal Notes Modal -->
        <div class="modal-overlay" id="deal-notes-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="deal-notes-modal-title">Notes</span>
                    <button class="icon-btn" onclick="closeDealNotesModal()">✕</button>
                </div>
                <div class="modal-body">
                    <div class="deal-notes-tabs">
                        <div class="deal-notes-tab active" data-tab="comments" onclick="switchDealNotesTab('comments')">💬 Comments</div>
                        <div class="deal-notes-tab" data-tab="revisions" onclick="switchDealNotesTab('revisions')">📝 Revisions</div>
                    </div>
                    <div class="deal-notes-modal-list" id="deal-notes-modal-list-comments"></div>
                    <div class="deal-notes-modal-list" id="deal-notes-modal-list-revisions" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeDealNotesModal()">Close</button>
                    <button class="btn btn-ghost" id="deal-notes-add-comment-btn" onclick="openNoteModal(null, currentDealNotesId, 'comment')">+ Comment</button>
                    <button class="btn btn-primary" id="deal-notes-add-revision-btn" onclick="openNoteModal(null, currentDealNotesId, 'revision')">+ Revision</button>
                </div>
            </div>
        </div>

        <!-- Note Modal -->
        <div class="modal-overlay" id="note-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="note-modal-title">Add Note</span>
                    <button class="icon-btn" onclick="closeModal('note-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="note-form-grid">
                        <div class="form-group">
                            <label class="form-label">Title *</label>
                            <input type="text" id="n-title" placeholder="Meeting recap">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select id="n-type">
                                <option value="comment">💬 Comment</option>
                                <option value="revision">📝 Request Revision</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Content *</label>
                            <textarea id="n-content" placeholder="Write your note here…" style="min-height:120px;"></textarea>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Linked Employee</label>
                                <select id="n-contact"><option value="">— None —</option></select>
                                <small style="color:var(--text3);">Filtered by task assignees.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" id="n-deal-label">Linked Task</label>
                                <select id="n-deal" onchange="if (typeof renderNoteModalStageActions === 'function') renderNoteModalStageActions(this.value);"><option value="">— None —</option></select>
                                <span class="form-hint" id="n-deal-lock-hint" style="display:none;">🔒 Locked to the task this note was added from.</span>
                            </div>
                        </div>
                        <div id="note-modal-stage-actions"></div>
                        <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                            <input type="checkbox" id="n-done" style="width:16px;height:16px;">
                            <label class="form-label" for="n-done" style="margin:0;cursor:pointer;">Mark as Done (confirms this comment/revision is resolved)</label>
                        </div>
                        <div class="form-group" style="font-size:0.75rem;color:var(--text3);padding:4px 0;">
                            <span>✍️ Author: <span id="n-author-display">Current User</span></span>
                            <span style="margin-left:12px;">📅 Created: <span id="n-date-display">Now</span></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('note-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveNote()">Save Note</button>
                </div>
            </div>
        </div>

        <!-- Department Modal -->
        <div class="modal-overlay" id="department-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="department-modal-title">Add Department</span>
                    <button class="icon-btn" onclick="closeModal('department-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Department Name *</label>
                            <input type="text" id="dep-name" placeholder="Engineering">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea id="dep-desc" placeholder="What this department handles…" style="min-height:70px;"></textarea>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Company</label>
                                <select id="dep-company"><option value="">— None —</option></select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Manager</label>
                                <select id="dep-manager"><option value="">— None —</option></select>
                            </div>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select id="dep-status"><option value="active">Active</option><option value="inactive">In-Active</option></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('department-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveDepartment()">Save Department</button>
                </div>
            </div>
        </div>

        <!-- Role Modal -->
        <div class="modal-overlay" id="role-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="role-modal-title">Add Role</span>
                    <button class="icon-btn" onclick="closeModal('role-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Role Name *</label>
                            <input type="text" id="role-name" placeholder="e.g. Team Lead">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea id="role-desc" placeholder="What this role is responsible for…" style="min-height:70px;"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select id="role-status"><option value="active">Active</option><option value="inactive">In-Active</option></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Inherits Permissions From</label>
                            <select id="role-inherits-from"><option value="">— None —</option></select>
                            <small style="color:var(--text3);">Optional. This role automatically gets any stage permission its parent has, unless overridden per-stage.</small>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="role-can-add-stage" style="width:auto;">
                                <span class="form-label" style="margin:0;">Can Add Stages (Task page)</span>
                            </label>
                            <small style="color:var(--text3);">Lets this role create new Kanban stages on the Task page. Off by default — unlike per-stage permissions, a role with no explicit setting cannot add stages until granted here.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('role-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveRole()">Save Role</button>
                </div>
            </div>
        </div>

        <!-- Stage Permission Modal -->
        <div class="modal-overlay" id="stage-permission-modal">
            <div class="modal modal-wide">
                <div class="modal-header">
                    <span id="stage-permission-modal-title">Stage Permissions</span>
                    <button class="icon-btn" onclick="closeModal('stage-permission-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <p style="font-size:0.85rem;color:var(--text3);margin-bottom:10px;">Every role always sees this stage. These checkboxes control what each role can DO while a task sits here. "Edit" governs editing task cards in this stage; "Stage Edit" separately governs renaming/recoloring/deleting the stage itself.</p>
                    <table class="permission-matrix">
                        <thead id="stage-permission-thead">
                            <tr><th>Role</th><th>Grab</th><th>Drop</th><th>Edit</th><th>Comment</th><th>Revision</th><th>Upload</th><th>Stage Edit</th></tr>
                        </thead>
                        <tbody id="stage-permission-tbody"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('stage-permission-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveStagePermissions()">Save Permissions</button>
                </div>
            </div>
        </div>

        <!-- Read-Only Task View Modal -->
        <div class="modal-overlay" id="deal-view-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="deal-view-modal-title">View Task</span>
                    <button class="icon-btn" onclick="closeModal('deal-view-modal')">✕</button>
                </div>
                <div class="modal-body" id="deal-view-modal-body"></div>
                <div class="modal-footer">
                    <button class="btn btn-primary" onclick="closeModal('deal-view-modal')">Close</button>
                </div>
            </div>
        </div>

        <!-- Deal Files Modal -->
        <div class="modal-overlay" id="deal-files-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="deal-files-modal-title">Files</span>
                    <button class="icon-btn" onclick="closeModal('deal-files-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="deal-notes-tabs">
                        <div class="deal-notes-tab active" data-tab="file" onclick="switchFilesTab('file')">📄 Files</div>
                        <div class="deal-notes-tab" data-tab="image" onclick="switchFilesTab('image')">🖼️ Images</div>
                    </div>
                    <div id="deal-files-modal-list-file"></div>
                    <div id="deal-files-modal-list-image" style="display:none;"></div>
                    <div id="deal-files-modal-stage-actions"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" id="deal-files-upload-btn" onclick="closeModal('deal-files-modal'); openFileUploadModal(currentDealFilesId);">⬆ Upload</button>
                    <button class="btn btn-ghost" onclick="closeModal('deal-files-modal')">Close</button>
                </div>
            </div>
        </div>

        <!-- Upload Files Modal -->
        <div class="modal-overlay" id="file-upload-modal">
            <div class="modal">
                <div class="modal-header">
                    <span>Upload Files</span>
                    <button class="icon-btn" onclick="closeModal('file-upload-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Title *</label>
                            <input type="text" id="f-title" placeholder="e.g. Signed Contract">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select id="f-type" onchange="onFileUploadTypeChange()">
                                <option value="file">📄 Files</option>
                                <option value="image">🖼️ Image</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Upload</label>
                            <input type="file" id="f-upload">
                            <small style="color:var(--text3);">Documents: PDF, Word, Excel, CSV, PowerPoint. Images: JPG, PNG, GIF, WEBP, SVG. Max 10MB.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Linked Employee</label>
                            <input type="text" id="f-contact" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Linked Task</label>
                            <input type="text" id="f-deal" disabled>
                        </div>
                    </div>
                    <div id="file-upload-modal-stage-actions"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('file-upload-modal')">Cancel</button>
                    <button class="btn btn-primary" id="file-upload-save-btn" onclick="saveFileUpload()">Save</button>
                </div>
            </div>
        </div>

        <!-- User Modal -->
        <div class="modal-overlay" id="user-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="user-modal-title">Register User</span>
                    <button class="icon-btn" onclick="closeModal('user-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Employee</label>
                            <select id="user-employee"></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" id="user-username" placeholder="e.g. jdoe">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" id="user-password" placeholder="Enter password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">User Role *</label>
                            <select id="user-role"></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select id="user-status"><option value="active">Active</option><option value="inactive">In-Active</option></select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('user-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveUser()">Save User</button>
                </div>
            </div>
        </div>

        <!-- Company Modal -->
        <div class="modal-overlay" id="company-modal">
            <div class="modal">
                <div class="modal-header">
                    <span id="company-modal-title">Add Company</span>
                    <button class="icon-btn" onclick="closeModal('company-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Company Name *</label>
                            <input type="text" id="comp-name" placeholder="Acme Corp">
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Industry</label>
                                <input type="text" id="comp-industry" placeholder="Technology">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone</label>
                                <input type="tel" id="comp-phone" placeholder="+1 555 000 0000">
                            </div>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" id="comp-email" placeholder="contact@acme.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Website</label>
                                <input type="text" id="comp-website" placeholder="acme.com">
                            </div>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Address</label>
                                <input type="text" id="comp-address" placeholder="123 Main St, City">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select id="comp-status"><option value="active">Active</option><option value="inactive">In-Active</option></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('company-modal')">Cancel</button>
                    <button class="btn btn-primary" onclick="saveCompany()">Save Company</button>
                </div>
            </div>
        </div>

        <!-- View Announcement Modal -->
        <div class="modal-overlay" id="view-announcement-modal">
            <div class="modal">
                <div class="modal-header">
                    <span>Announcement</span>
                    <button class="icon-btn" onclick="closeModal('view-announcement-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <div id="view-announcement-body"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('view-announcement-modal')">Close</button>
                </div>
            </div>
        </div>

        <!-- Confirm Delete Modal -->
        <div class="modal-overlay" id="confirm-modal">
            <div class="modal" style="max-width:380px;">
                <div class="modal-header">
                    <span>Confirm Delete</span>
                    <button class="icon-btn" onclick="closeModal('confirm-modal')">✕</button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text2);font-size:0.9rem;" id="confirm-text">Are you sure you want to delete this item?</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="closeModal('confirm-modal')">Cancel</button>
                    <button class="btn btn-danger" id="confirm-ok">Delete</button>
                </div>
            </div>
        </div>

        <!-- Toast Container -->
        <div id="toast-container"></div>

    </div><!-- end #app-shell -->

    <!-- ===== SCRIPTS ===== -->
  <script src="../js/core/api-client.js?v=live-20260731-02"></script>
<script src="../js/services/roles.js"></script>
<script src="../js/services/stages.js"></script>
<script src="../js/services/auth.js"></script>

<script src="../js/modules/theme.js"></script>
<script src="../js/modules/session.js"></script>
<script src="../js/modules/navigation.js"></script>
<script src="../js/modules/helpers.js"></script>
<script src="../js/modules/activity.js"></script>
<script src="../js/modules/task-activity-writer.js"></script>
<script src="../js/modules/task-activity.js"></script>
<script src="../js/modules/seed.js"></script>
<script src="../js/modules/profile.js"></script>
<script src="../js/modules/dashboard.js"></script>
<script src="../js/modules/employee-directory.js"></script>
<script src="../js/modules/contacts.js"></script>
<script src="../js/core/permissions.js"></script>
<script src="../js/modules/notes.js"></script>
<script src="../js/modules/kanban.js?v=20260810-01"></script>
<script src="../js/modules/modals.js"></script>
<script src="../js/modules/deals.js"></script>
<script src="../js/modules/attachments.js?v=20260803-03"></script>
<script src="../js/modules/announcements.js"></script>
<script src="../js/modules/departments.js"></script>
<script src="../js/modules/roles.js"></script>
<script src="../js/core/page-permissions.js"></script>
<script src="../js/modules/users.js"></script>
<script src="../js/modules/companies.js"></script>
<script src="../js/modules/login-monitoring.js"></script>
<script src="../js/modules/init.js"></script>

<!-- ===== REAL-TIME (SOCKET.IO) ===== -->
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
<script src="../js/modules/realtime-sync.js?v=20260804-01"></script>
<script src="../js/modules/socket-handler.js?v=20260804-01"></script>


    <script>
        // ── login monitoring integration, logout, etc. ──
        (function() {
            'use strict';

            function renderLoginMonitoringIfActive() {
                var lmPage = document.getElementById('page-login-monitoring');
                if (lmPage && lmPage.classList.contains('active') && typeof renderLoginMonitoring === 'function') {
                    renderLoginMonitoring();
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                var page = document.getElementById('page-task-activity');
                if (page && page.classList.contains('active') && typeof renderTaskActivity === 'function') {
                    renderTaskActivity(1);
                }
                renderLoginMonitoringIfActive();
            });

            if (typeof reloadAllData === 'function') {
                var originalReload = window.reloadAllData;
                window.reloadAllData = function() {
                    return originalReload.apply(this, arguments).then(function() {
                        var p = document.getElementById('page-task-activity');
                        if (p && p.classList.contains('active') && typeof renderTaskActivity === 'function') {
                            renderTaskActivity(1);
                        }
                        renderLoginMonitoringIfActive();
                    });
                };
            }

            if (typeof navigate === 'function') {
                var originalNavigate = window.navigate;
                window.navigate = function(page) {
                    originalNavigate(page);
                    if (page === 'task-activity') {
                        setTimeout(function() {
                            if (typeof renderTaskActivity === 'function') {
                                renderTaskActivity(1);
                            }
                        }, 50);
                    }
                    if (page === 'login-monitoring') {
                        setTimeout(function() {
                            renderLoginMonitoringIfActive();
                        }, 50);
                    }
                };
            }

            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    var lmPage = document.getElementById('page-login-monitoring');
                    if (lmPage && lmPage.classList.contains('active')) {
                        if (typeof refreshLoginData === 'function') {
                            refreshLoginData().then(function() {
                                if (typeof filterLoginLogs === 'function') {
                                    filterLoginLogs();
                                }
                            });
                        }
                    }
                }
            });

            console.log('✅ Login monitoring integration complete');
        })();

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'auth.php?action=logout';
            }
        }
    </script>
</body>
</html>