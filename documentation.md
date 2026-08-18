# TMS (Task Management System) - Technical Documentation

**Version:** 1.0.6  
**Last Updated:** August 2026  
**Backend:** PHP 8.x + SQLite  
**Frontend:** Vanilla JavaScript (Classic Scripts)  
**Real-time:** Socket.IO (Node.js)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [System Architecture](#2-system-architecture)
3. [Backend Components](#3-backend-components)
4. [Frontend Modules](#4-frontend-modules)
5. [Core Workflows](#5-core-workflows)
6. [Database Schema](#6-database-schema)
7. [Role-Based Access Control (RBAC)](#7-role-based-access-control-rbac)
8. [Real-Time Features](#8-real-time-features)
9. [Security Features](#9-security-features)
10. [Setup & Deployment](#10-setup--deployment)
11. [Troubleshooting Guide](#11-troubleshooting-guide)

---

## 1. Executive Summary

The **Task Management System (TMS)** is a full-featured enterprise task management platform built for small to medium organizations. It combines:

- **Kanban-style task boards** with customizable stages
- **Employee directory** with real-time login status
- **Role-based access control** with inheritance
- **Login monitoring** and security auditing
- **Real-time updates** via Socket.IO
- **Offline-first architecture** using localStorage
- **File attachments** with preview support (PDF, Excel, Images)

### Key Differentiators

- **SQLite persistence** for easy deployment on shared hosting
- **Offline-capable UI** that works even when the server is unreachable
- **Granular stage permissions** (Grab, Drop, Edit, Comment, Revision, Upload)
- **Per-employee task lifecycle tracking** (Active → Completed)
- **No page refresh required** for collaborative work

---

## 2. System Architecture

### High-Level Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         Browser Client                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐         │
│  │  API     │  │  RBAC    │  │  Socket  │  │  UI      │         │
│  │ Client   │  │ Engine   │  │ Handler  │  │ Modules  │         │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘         │
│       │             │             │             │               │
│       └─────────────┴─────────────┴─────────────┘               │
│                         │                                       │
└─────────────────────────┼───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Web Server (Apache/Nginx)                    │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │              PHP 8.x (api.php, auth.php)                │  │
│  │  ┌─────────────────────────────────────────────────┐   │  │
│  │  │              SQLite Database                    │   │  │
│  │  │   (data/tms_database.sq3)                      │   │  │
│  │  └─────────────────────────────────────────────────┘   │  │
│  └─────────────────────────────────────────────────────────┘  │
│                          │                                   │
│                          ▼                                   │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │          Node.js Socket.IO Server (port 3000)          │  │
│  │              Real-time event broadcast                 │  │
│  └─────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### Technology Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Backend** | PHP 8.x | API endpoints, authentication, file handling |
| **Database** | SQLite 3 | Persistent storage (WAL mode enabled) |
| **Real-time** | Node.js + Socket.IO | Live updates between connected clients |
| **Frontend** | Vanilla JS | All UI rendering, no framework dependencies |
| **Styling** | CSS Custom Properties | Dark/light theme support |
| **File Preview** | SheetJS (XLSX) | Client-side spreadsheet rendering |
| **Native PDF** | Browser PDF Viewer | PDF preview without third-party services |

---

## 3. Backend Components

### 3.1 `api.php` - Main API Endpoint

**Purpose:** REST API for all CRUD operations, file uploads, and data synchronization.

#### Key Endpoints

| Action | Method | Description |
|--------|--------|-------------|
| `load` | GET | Fetch full database snapshot |
| `save` | POST | Save full database snapshot (base64-encoded JSON) |
| `uploadFile` | POST | Upload file attachment (multipart/form-data) |
| `deleteFile` | POST | Delete file attachment |
| `updateFile` | POST | Rename file attachment |
| `serveFile` | GET | Serve file with correct Content-Type |
| `getLoginLogs` | GET | Fetch filtered login logs |
| `getLoginStats` | GET | Fetch login statistics |
| `clearLoginLogs` | GET | Delete logs older than N days |
| `getEmployeeDirectory` | GET | Fetch employee directory with login status |
| `getTaskFiles` | GET | Get files for a specific task |

#### Database Layer (`SQLite`)

```php
// All data stored as JSON in a key-value table
CREATE TABLE deals (
    id TEXT PRIMARY KEY,
    data TEXT  -- JSON object
);

// Counters for auto-increment IDs
CREATE TABLE counters (
    name TEXT PRIMARY KEY,
    val INTEGER
);

// Activity logs use a separate table
CREATE TABLE activity (
    id TEXT PRIMARY KEY,
    data TEXT
);
```

#### Broadcast Mapping

```php
// Entity tables → Socket.IO event prefixes
const BROADCAST_ENTITY_TABLES = [
    'deals'       => 'deal',
    'contacts'    => 'contact',
    'notes'       => 'note',
    'tasks'       => 'announcement',
    'departments' => 'department',
    'companies'   => 'company',
    'roles'       => 'role',
    'users'       => 'user',
];

// Append-only log tables
const BROADCAST_LOG_TABLES = [
    'activity'     => 'activity:new',
    'taskActivity' => 'task-activity:new',
];
```

### 3.2 `auth.php` - Authentication Handler

**Purpose:** Login/logout processing, session management, and login monitoring.

#### Authentication Flow

1. User submits credentials via `login.html` POST to `auth.php?action=login`
2. Check username against SQLite `users` table
3. Verify password (plaintext for simplicity; SHA-256 recommended)
4. Check rate limiting (5 attempts per 15 minutes)
5. Log attempt to `login_logs` table
6. Create PHP session with user data
7. Redirect to `index.php`

#### Session Data Structure

```php
$_SESSION['user'] = [
    'id' => string,
    'username' => string,
    'role' => string,       // Role ID
    'roleName' => string,
    'employeeId' => string,
    'name' => string,
    'email' => string,
    'status' => string,
];
```

### 3.3 `socket_notifier.php` - Real-Time Bridge

**Purpose:** PHP → Node.js Socket.IO communication.

#### Filtered Events (Only Login/Logout)

```php
$allowedEvents = ['login:success', 'login:failed', 'logout'];
```

All other events (deal:created, note:updated, etc.) are **silently ignored** to reduce server load.

### 3.4 File Upload System

| Constant | Value |
|----------|-------|
| `MAX_UPLOAD_SIZE` | 100 MB |
| `ALLOWED_DOCUMENTS` | pdf, doc, docx, xls, xlsx, csv, ppt, pptx, txt |
| `ALLOWED_IMAGES` | jpg, jpeg, png, gif, webp, svg |

**File Storage Paths:**
```
uploads/
├── tasks/
│   ├── files/     # Documents (PDF, Office, etc.)
│   └── images/    # Images (JPG, PNG, etc.)
```

---

## 4. Frontend Modules

### 4.1 Core Layer (`js/core/`)

| File | Responsibility |
|------|---------------|
| `api-client.js` | Data layer, localStorage cache, server sync |
| `permissions.js` | Stage-based RBAC engine |
| `page-permissions.js` | Sidebar/navigation RBAC |
| `notification-manager.js` | Centralized toast/notification management |

#### `api-client.js` - Data Layer

```javascript
// Global data arrays (mirrored to localStorage)
let contacts = load('contacts');
let deals = load('deals');
let tasks = load('tasks');        // Announcements
let notes = load('notes');
let activity = load('activity');
let taskActivity = load('taskActivity');
let departments = load('departments');
let companies = load('companies');
let users = load('users');
let files = load('files');
let loginLogs = load('login_logs');

// Auto-increment counters
let counters = loadCounters();

// Core functions
function saveAll() → writes to localStorage + background server sync
function load(key) → synchronous localStorage read
function reloadAllData() → fetch fresh from server
function pushToServer() → POST to api.php?action=save
```

### 4.2 Service Layer (`js/services/`)

| File | Responsibility |
|------|---------------|
| `roles.js` | Role data with SYSTEM_ROLE_ID constant |
| `stages.js` | Stage data with default To Do/In Progress |
| `auth.js` | Login monitoring API functions |

### 4.3 UI Modules (`js/modules/`)

| File | Responsibility |
|------|---------------|
| `session.js` | User session management, `enterApp()` |
| `navigation.js` | Page routing, sidebar, modals, toast |
| `dashboard.js` | Stats grid, recent activity, upcoming tasks |
| `kanban.js` | **Core:** Kanban board with drag & drop, filters |
| `deals.js` | Task CRUD, read-only viewer, move dropdown |
| `notes.js` | Comments & revisions (type: 'comment'/'revision') |
| `attachments.js` | File upload, delete, rename |
| `contacts.js` | Employee CRUD with duplicate checks |
| `employee-directory.js` | Employee list with online/offline status |
| `login-monitoring.js` | Login log table with pagination |
| `task-activity.js` | Full audit trail with pagination |
| `task-activity-writer.js` | Task lifecycle logging |
| `activity.js` | Recent activity feed with pagination |
| `roles.js` | Role CRUD with inheritance |
| `page-permissions.js` | Page access matrix |
| `profile.js` | Self-service password change |

### 4.4 Module Communication Pattern

All modules share the global scope (classic scripts). Functions are exposed as `window.functionName` for cross-module calls.

```javascript
// Example: kanban.js exports
window.getCurrentEmployeeId = getCurrentEmployeeId;
window.renderKanban = renderKanban;
window.updateDealCard = updateDealCard;
window.appendDealCard = appendDealCard;
window.removeDealCard = removeDealCard;
```

### 4.5 Real-Time Layer

| File | Responsibility |
|------|---------------|
| `realtime-sync.js` | Coalesced full dataset refresh |
| `socket-handler.js` | Socket.IO event routing |
| `realtime.js` | Server-Sent Events (fallback) |

---

## 5. Core Workflows

### 5.1 User Login Flow

```
┌────────────┐     ┌────────────┐     ┌────────────┐     ┌────────────┐
│  login.html │────▶│  auth.php  │────▶│  SQLite    │────▶│  index.php │
│  (Form)     │     │  (POST)    │     │  (users)   │     │  (Session) │
└────────────┘     └─────┬──────┘     └────────────┘     └─────┬──────┘
                         │                                      │
                         ▼                                      ▼
                 ┌────────────┐                          ┌────────────┐
                 │ login_logs │                          │  Browser   │
                 │  (Audit)   │                          │  (UI)      │
                 └────────────┘                          └────────────┘
```

**Key Logging Points:**
- Every login attempt → `login_logs` table
- Success/failure broadcast → Socket.IO (`login:success` / `login:failed`)
- Recent Activity entry → `activity` table

### 5.2 Task Lifecycle

```
┌──────────────────────────────────────────────────────────────────────┐
│                         TASK LIFECYCLE                              │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐      │
│  │ CREATED  │───▶│  MOVED   │───▶│  MOVED   │───▶│COMPLETED │      │
│  │ (Todo)   │    │(Progress)│    │ (Review) │    │  (Done)  │      │
│  └──────────┘    └──────────┘    └──────────┘    └──────────┘      │
│       │               │               │               │             │
│       ▼               ▼               ▼               ▼             │
│  ┌──────────────────────────────────────────────────────────┐      │
│  │              TASK ACTIVITY LOG                            │      │
│  │  - Created by John Doe in To Do                          │      │
│  │  - Moved by Jane Smith: To Do → In Progress (2h)        │      │
│  │  - Comment added by John Doe                            │      │
│  │  - Revision requested by Jane Smith                     │      │
│  │  - Moved by Admin: In Progress → Done (4h)             │      │
│  │  - Completed by John Doe                               │      │
│  └──────────────────────────────────────────────────────────┘      │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────┐      │
│  │         EMPLOYEE LIFE CYCLE (Per-Assignee)               │      │
│  │                                                          │      │
│  │  Active Window (Visible + Grab)                         │      │
│  │    ↓                                                    │      │
│  │  Completed (Moved to Stage Not Visible to Role)         │      │
│  │    ↓                                                    │      │
│  │  Re-opened (Moved back to Visible Stage)               │      │
│  └──────────────────────────────────────────────────────────┘      │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.3 Stage Permissions Evaluation

```javascript
// permissions.js - Core RBAC Engine

function hasStagePermission(stage, roleId, permission) {
    // 1. System Administrator → always true
    if (isSystemAdmin(roleId)) return true;
    
    // 2. Legacy stage (no permissions map) → everyone allowed
    const map = getStagePermissionsMap(stage);
    if (!Object.keys(map).length) return true;
    
    // 3. Walk inheritance chain (self → parent → ...)
    const chain = getRoleInheritanceChain(roleId);
    for (const r of chain) {
        const entry = map[r];
        if (entry && typeof entry[permission] === 'boolean') {
            return entry[permission];
        }
    }
    
    // 4. Default: denied
    return false;
}
```

**Permission Types:**
- `grab` - Move task OUT of this stage
- `drop` - Move task INTO this stage
- `edit` - Edit/delete task details
- `comment` - Add comments
- `revision` - Request revisions
- `upload` - Upload files
- `stageEdit` - Rename/recolor/delete stage
- `reorder` - Drag stage column

---

## 6. Database Schema

### 6.1 Tables

All data stored as JSON in `data` column (except counters table).

```sql
-- Entity tables (key-value)
CREATE TABLE contacts (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE deals (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE tasks (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE notes (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE activity (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE taskActivity (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE departments (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE companies (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE roles (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE stages (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE users (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE files (id TEXT PRIMARY KEY, data TEXT);
CREATE TABLE login_logs (id TEXT PRIMARY KEY, data TEXT);

-- Counters (auto-increment IDs)
CREATE TABLE counters (name TEXT PRIMARY KEY, val INTEGER);
```

### 6.2 Data Models

#### Contact (Employee)

```javascript
{
    id: '1',
    fname: 'John',
    lname: 'Doe',
    mname: 'Michael',
    email: 'john@example.com',
    phone: '+1 555-0000',
    company: 'comp1',
    department: 'dep1',
    role: 'role_employee',
    status: 'active',
    createdAt: '2026-08-01T00:00:00.000Z'
}
```

#### Deal (Task)

```javascript
{
    id: 'task_abc123',
    title: 'Q3 Financial Report',
    desc: 'Prepare and review quarterly financial statements',
    due: '2026-09-30',
    priority: 'high', // low | medium | high
    stage: 'inprogress',
    department: 'dep1',
    contactIds: ['1', '2'], // Assigned employees
    createdAt: '2026-08-01T00:00:00.000Z',
    archived: false,
    archivedAt: null,
    employeeActive: {
        '1': '2026-08-15T10:00:00.000Z' // Active window start
    },
    completions: {
        '1': { role: 'role_employee', roleName: 'Employee', completedAt: '2026-08-16T15:00:00.000Z' }
    }
}
```

#### Note (Comment/Revision)

```javascript
{
    id: 'note_xyz789',
    title: 'Need clarification on Q3 numbers',
    content: 'Please review the revenue figures for Q3...',
    type: 'comment', // 'comment' | 'revision'
    contactId: '1',  // Linked employee
    dealId: 'task_abc123',
    authorId: '2',   // Who wrote this note
    authorFname: 'Jane',
    authorLname: 'Smith',
    done: false,
    createdAt: '2026-08-15T14:00:00.000Z',
    updatedAt: '2026-08-15T14:00:00.000Z'
}
```

#### Login Log

```javascript
{
    id: 'log_1692000000_abcdef123456',
    userId: '1',
    username: 'johndoe',
    ipAddress: '192.168.1.100',
    userAgent: 'Mozilla/5.0 ...',
    loginTime: '2026-08-15T10:00:00.000Z',
    logoutTime: '2026-08-15T17:00:00.000Z',
    status: 'success', // 'success' | 'failed'
    failureReason: null, // 'Invalid password', etc.
    sessionId: 'sess_xyz123',
    deviceType: 'Desktop',
    browser: 'Chrome',
    os: 'Windows',
    country: 'Philippines',
    city: 'Manila',
    referer: 'http://tms.ghcoor.com/login.html',
    requestMethod: 'POST',
    additionalData: {},
    createdAt: '2026-08-15T10:00:00.000Z'
}
```

---

## 7. Role-Based Access Control (RBAC)

### 7.1 Core Concepts

| Concept | Description |
|---------|-------------|
| **System Administrator** | Fixed role (`role_system_admin`). Bypasses ALL permissions. |
| **Role Inheritance** | Roles can inherit permissions from a parent role |
| **Stage Visibility** | Which roles can SEE a stage column |
| **Stage Permissions** | Which actions a role can perform ON a stage |
| **Page Access** | Which sidebar pages a role can navigate to |

### 7.2 Permission Resolution Order

```
1. System Administrator? → Full Access
2. Explicit permission on role's own entry?
   → Yes: Use that value
   → No: Check parent role (inheritsFrom)
3. Parent explicit? → Use that value
4. Continue up chain until root
5. Default: false (for stage permissions) / true (for visibility)
```

### 7.3 Page Access Configuration

```javascript
// page-permissions.js
const NAV_PAGES = [
    'deals', 'employee-directory', 'contacts',
    'departments', 'companies', 'roles', 'users',
    'tasks', 'notes', 'task-activity', 'login-monitoring'
];

// Always visible
const ALWAYS_VISIBLE_PAGES = ['dashboard', 'profile'];

// HasPageAccess resolver
function hasPageAccess(pageKey, roleId) {
    if (ALWAYS_VISIBLE_PAGES.includes(pageKey)) return true;
    if (isSystemAdmin(roleId)) return true;
    
    const chain = getRoleInheritanceChain(roleId);
    for (const r of chain) {
        const role = getRoleById(r);
        const entry = role?.pageAccess?.[pageKey];
        if (typeof entry === 'boolean') return entry;
    }
    return false; // Locked down by default
}
```

### 7.4 Permission Matrix Editor

The **Page Access** screen provides a visual matrix:
- Rows: All roles (System Administrator rows are disabled)
- Columns: All restricted pages (Dashboard/Profile are always visible)
- Checkboxes: Grant or revoke page access
- Save: Writes `role.pageAccess` object

---

## 8. Real-Time Features

### 8.1 Architecture

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   Browser    │     │   Node.js    │     │   PHP        │
│   Client 1   │◀───▶│   Socket.IO  │◀───▶│   Backend    │
│              │     │   (Port 3000)│     │   (api.php)  │
└──────────────┘     └──────────────┘     └──────────────┘
        │                    │                     │
        │                    │                     │
        ▼                    ▼                     ▼
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   Browser    │     │   Server     │     │   SQLite     │
│   Client 2   │     │   Broadcast  │     │   Database   │
└──────────────┘     └──────────────┘     └──────────────┘
```

### 8.2 Event Flow

1. **User performs action** (e.g., moves a task)
2. **API writes to SQLite** (transaction committed)
3. **API calls `notifySocketServer()`** with event data
4. **PHP posts to Node.js** (socket_notifier.php)
5. **Node.js broadcasts** to all connected clients
6. **Client socket-handler** receives event
7. **UI updates** without page refresh

### 8.3 Event Types

| Event | Description |
|-------|-------------|
| `deal:created` | New task added |
| `deal:updated` | Task details changed |
| `deal:deleted` | Task removed |
| `deal:stage-changed` | Task moved between stages |
| `activity:new` | New activity log entry |
| `note:created` | Comment or revision added |
| `note:updated` | Note edited |
| `note:deleted` | Note removed |
| `contact:created` | New employee added |
| `contact:updated` | Employee details changed |
| `contact:deleted` | Employee removed |
| `login:success` | User logged in |
| `login:failed` | Failed login attempt |
| `logout` | User logged out |
| `file:uploaded` | File attached to task |
| `file:deleted` | File removed |

### 8.4 Socket Handler Filtering

Events triggered by the **same user** are skipped to prevent "echo" notifications:

```javascript
// socket-handler.js
function wasTriggeredByCurrentUser(data) {
    return data.originatorId === CURRENT_USER_ID ||
           data.originatorContactId === CURRENT_CONTACT_ID;
}

// Example: Skip own events
socket.on('deal:created', (data) => {
    if (wasTriggeredByCurrentUser(data)) {
        console.debug('Skipping own event');
        return;
    }
    // Update UI for other users
});
```

---

## 9. Security Features

### 9.1 Authentication

| Feature | Implementation |
|---------|---------------|
| Session Management | PHP sessions with cookie |
| Rate Limiting | 5 failed attempts per 15 minutes |
| Account Lockout | Rate limit triggers "too many attempts" |
| Password Storage | Plaintext (SHA-256 recommended) |
| Session Timeout | 8 hours (configurable) |

### 9.2 Access Control

| Layer | Control |
|-------|---------|
| **File Access** | `api.php?action=serveFile` requires session cookie |
| **API Endpoints** | All actions require valid session |
| **UI Pages** | `hasPageAccess()` checks before navigation |
| **Stage Actions** | `hasStagePermission()` checks every operation |
| **File Deletion** | System Administrator only |

### 9.3 Input Validation

| Field | Validation |
|-------|-----------|
| File Upload | Extension whitelist, size limit, MIME type check |
| Task Title | Required, duplicate check |
| Employee Email | Required, duplicate check, format validation |
| Employee Name | Required, duplicate check (full name) |
| Username | Required, duplicate check |
| Role Name | Required, duplicate check |

### 9.4 Database Security

- SQLite file permissions: `0644`
- Data directory: `.htaccess` denies all requests
- Prepared statements for all queries
- WAL mode for concurrency

### 9.5 File Upload Security

- Restricted to `uploads/` directory
- Filename sanitization (`preg_replace('/[^A-Za-z0-9_\-]+/', '-')`)
- Unique filenames (prevent overwrites)
- MIME type mapping (explicit, not browser-supplied)

---

## 10. Setup & Deployment

### 10.1 System Requirements

| Component | Minimum |
|-----------|---------|
| **Web Server** | Apache 2.4+ / Nginx |
| **PHP** | 8.0+ (PDO SQLite enabled) |
| **Node.js** | 16.x+ (for Socket.IO) |
| **Browser** | Modern (Chrome/Firefox/Edge) |
| **Disk Space** | 100 MB + uploads |

### 10.2 Quick Start

#### Step 1: Deploy Files

```bash
# Clone or extract to web root
cp -R tms/ /var/www/html/tms/
cd /var/www/html/tms/

# Create required directories
mkdir -p data uploads/tasks/files uploads/tasks/images

# Set permissions
chmod 755 data uploads
chmod 644 data/tms_database.sq3  # (created on first run)
```

#### Step 2: Configure Socket.IO

```bash
# Install Node.js dependencies
cd socket/
npm install

# Start Socket.IO server (use PM2 for production)
npm start
# or
node server.js
```

#### Step 3: Database Setup

The database is **auto-created** on first request. The system seeds default data:

- System Administrator role
- Admin user (username: `admin`, password: `admin123`)
- Default stages: To Do, In Progress
- Default departments: Engineering
- Default company: TMS Global

#### Step 4: Configure Environment

```bash
# Optional: Socket.IO URL override
export SOCKET_SERVER_URL_OVERRIDE="http://your-domain.com:3000/update"
```

### 10.3 Production Configuration

#### Apache `.htaccess` (Recommended)

```apache
# Protect sensitive directories
<Directory "data">
    Require all denied
</Directory>

<Directory "uploads">
    Options -Indexes
</Directory>

# URL rewriting (optional)
RewriteEngine On
RewriteRule ^api$ api.php [L]
```

#### PHP Configuration (`php.ini`)

```ini
upload_max_filesize = 100M
post_max_size = 100M
memory_limit = 256M
max_execution_time = 300
```

#### PM2 for Socket.IO (Production)

```bash
# Install PM2
npm install -g pm2

# Start with PM2
pm2 start server.js --name tms-socket

# Auto-start on reboot
pm2 startup
pm2 save
```

### 10.4 First Login

1. Navigate to `https://your-domain.com/tms/login.html`
2. Default credentials:
   - **Username:** `admin`
   - **Password:** `admin123`
3. **IMPORTANT:** Change the admin password immediately

---

## 11. Troubleshooting Guide

### 11.1 Common Issues & Solutions

#### "Database file does not exist"

```
Error: Unable to create database file: /path/to/data/tms_database.sq3
```

**Solution:**
```bash
# Check directory permissions
chmod 755 data/
# Ensure PHP can write to the directory
ls -la data/
```

#### File Upload Fails (Empty POST)

```
Error: This file is too large for the server to accept
```

**Solution:**
```php
# Increase PHP limits in php.ini
upload_max_filesize = 100M
post_max_size = 100M
```

#### Socket.IO Connection Refused

```
socket-handler: connect_error - Connection refused
```

**Solution:**
1. Check Node.js is running: `ps aux | grep node`
2. Check port 3000 is open: `netstat -tulpn | grep 3000`
3. Verify SOCKET_SERVER_URL_OVERRIDE in environment
4. Check firewall: `ufw allow 3000`

#### Login Redirects to Login Page

**Solution:**
1. Check session configuration in `php.ini`
2. Verify `session_start()` is not failing
3. Clear browser cookies
4. Check `data/` directory permissions

#### Office Online Viewer Fails for Spreadsheets

**Solution:**
- The system now uses **SheetJS** for client-side rendering
- Ensure `luckySheet.html` is accessible
- Check file extension is passed correctly in URL: `&ext=xlsx`

### 11.2 Debugging

#### Enable PHP Error Logging

```php
// Add to api.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

#### Enable Socket.IO Debugging

```javascript
// In socket-handler.js
const SOCKET_OPTIONS = {
    transports: ['websocket'],
    reconnection: true,
    debug: true  // Enable debug logging
};
```

#### Check Database Integrity

```sql
-- Manual query via SQLite CLI
sqlite3 data/tms_database.sq3

-- Verify tables
.tables

-- Check a specific table
SELECT id, json_extract(data, '$.title') FROM deals LIMIT 5;

-- Check counts
SELECT COUNT(*) FROM deals;
```

### 11.3 Common Error Messages

| Message | Cause | Solution |
|---------|-------|----------|
| `Invalid or missing payload` | POST body corrupted/truncated | Check `post_max_size` in php.ini |
| `You don't have access to that page` | User lacks page permission | Grant access via Page Access screen |
| `This employee already has a user account` | Employee linked to another user | Unlink existing user first |
| `A task with this title already exists` | Duplicate task title | Use a unique title |
| `An employee with this email already exists` | Duplicate email | Update existing employee |

---

## Appendix A: API Reference

### A.1 Load Data

```
GET api.php?action=load

Response:
{
    "ok": true,
    "db": {
        "contacts": [...],
        "deals": [...],
        "tasks": [...],
        ...
    }
}
```

### A.2 Save Data

```
POST api.php
Content-Type: multipart/form-data

payload: base64(JSON.stringify({ db: {...} }))

Response:
{
    "ok": true,
    "saved": {
        "contacts": true,
        "deals": true,
        ...
    }
}
```

### A.3 Upload File

```
POST api.php
Content-Type: multipart/form-data

action: uploadFile
taskId: task_abc123
file: (binary)
title: "Q3 Report.pdf"
uploadedBy: "1"
actorName: "John Doe"
actorRole: "Manager"

Response:
{
    "ok": true,
    "file": {
        "id": "file_123",
        "title": "Q3 Report.pdf",
        "path": "uploads/tasks/files/file-abc123.pdf",
        ...
    }
}
```

### A.4 Get Login Logs

```
GET api.php?action=getLoginLogs&limit=100&status=success

Response:
{
    "ok": true,
    "logs": [...],
    "count": 15
}
```

### A.5 Get Login Stats

```
GET api.php?action=getLoginStats

Response:
{
    "ok": true,
    "stats": {
        "total": 150,
        "failed": 12,
        "uniqueUsers": 25,
        "last24Hours": 8,
        "activeSessions": 3,
        "successRate": 92.0,
        "topUsers": [...]
    }
}
```

---

## Appendix B: Folder Structure

```
tms/
├── api.php                 # Main API endpoint
├── auth.php                # Authentication handler
├── client_ip.php           # IP detection helper
├── index.php               # Application shell (PHP session)
├── login.html              # Login page
├── luckySheet.html         # Document viewer
├── migration.php           # Database migration script
├── socket_notifier.php     # PHP → Node.js bridge
├── data/                   # SQLite database directory
│   ├── tms_database.sq3    # Main database file
│   └── .htaccess           # Deny all access
├── uploads/                # File upload directory
│   └── tasks/
│       ├── files/          # Documents
│       └── images/         # Images
├── css/                    # Stylesheets
│   └── index.min.css
├── js/
│   ├── core/               # Core library
│   │   ├── api-client.js   # Data layer
│   │   ├── page-permissions.js  # Page RBAC
│   │   ├── permissions.js  # Stage RBAC
│   │   └── notification-manager.js
│   ├── services/           # Service layer
│   │   ├── auth.js         # Login monitoring API
│   │   ├── roles.js        # Role data
│   │   └── stages.js       # Stage data
│   └── modules/            # UI modules
│       ├── activity.js
│       ├── announcements.js
│       ├── attachments.js
│       ├── companies.js
│       ├── contacts.js
│       ├── dashboard.js
│       ├── deals.js
│       ├── departments.js
│       ├── employee-directory.js
│       ├── helpers.js
│       ├── init.js
│       ├── kanban.js
│       ├── login-monitoring-init.js
│       ├── login-monitoring.js
│       ├── modals.js
│       ├── navigation.js
│       ├── notes.js
│       ├── profile.js
│       ├── realtime-sync.js
│       ├── realtime.js
│       ├── roles.js
│       ├── seed.js
│       ├── session.js
│       ├── socket-handler.js
│       ├── task-activity-writer.js
│       ├── task-activity.js
│       ├── theme.js
│       └── users.js
├── socket/                 # Socket.IO server
│   ├── server.js           # Node.js server
│   ├── package.json
│   └── node_modules/
└── .htaccess               # Apache configuration
```

---

## Appendix C: Change Log

### v1.0.6 (August 2026)
- **Added:** Login monitoring page with employee/department/company filters
- **Added:** Real-time login/logout toasts with status indicators
- **Added:** Employee Directory page with online/offline status
- **Added:** Role inheritance for stage permissions
- **Added:** Page-level RBAC (Page Access screen)
- **Fixed:** Activity pagination "Prev" button
- **Fixed:** Socket.IO localhost WebSocket fallback
- **Improved:** Database migration for login_logs table

### v1.0.5 (July 2026)
- **Added:** Task activity audit trail
- **Added:** Employee lifecycle tracking (Active/Completed)
- **Added:** SheetJS for client-side spreadsheet preview
- **Fixed:** Duplicate activity entries in real-time

### v1.0.4 (June 2026)
- **Added:** Stage permission matrix (Grab/Drop/Edit/Comment/Revision/Upload)
- **Added:** File attachments with rename/delete
- **Added:** Notifications for file uploads
- **Fixed:** Office Online Viewer authentication issue

---

## Appendix D: Contributing Guidelines

### Code Style

- **PHP:** PSR-12 compliant
- **JavaScript:** No framework, classic script pattern
- **CSS:** Custom properties for theming
- **Documentation:** Inline comments for complex logic

### Testing Checklist

- [ ] UI renders without errors on first load
- [ ] All CRUD operations persist to SQLite
- [ ] Real-time updates work across multiple browsers
- [ ] File uploads handle edge cases (large files, invalid types)
- [ ] Login monitoring captures all attempts
- [ ] RBAC restricts unauthorized actions
- [ ] Offline mode works (localStorage fallback)

---

## Appendix E: License & Credits

**License:** Proprietary – All Rights Reserved
**Copyright:** © 2026 TMS Global, Inc.
**Author:** Development Team

**Third-Party Libraries:**
- **Socket.IO** – Real-time communication
- **SheetJS (XLSX)** – Client-side spreadsheet parsing
- **Fonts:** Sora, DM Mono (Google Fonts)

---

*Documentation generated on August 10, 2026*