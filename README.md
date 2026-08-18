### **Key Changes: 1.2.1**

1. **Replaced the search input** – Now uses `id="dashboard-task-search"` with an `oninput` handler that calls `filterDashboardTasks()`.
2. **Added `filterDashboardTasks()`** – Reads the search query, filters `dashboardActiveDeals` (the list of current user's active tasks) by title or description, and re‑renders the task list.
3. **Stored `dashboardActiveDeals`** – Set in `renderDashboard()` so the filter function always works on the latest data.
4. **Removed the conflicting `kanban-search` ID** – The dashboard now has its own dedicated search, independent of the main Kanban board.
5. **Preserved all existing functionality** – Pagination, scrolling, activity list, and announcements remain unchanged.

The search now allows users to quickly filter their active tasks by typing any part of the task title or description.


### **How it works Reload Button**

- The button is disabled immediately after being clicked.
- Its text changes to *⟳ Reloading…* to indicate progress.
- The refresh operation runs asynchronously.
- Once the `reloadAllData()` promise settles (success or failure), we wait **3.5 seconds** and then re‑enable the button, restoring its original label.
- During the disabled period, further clicks are ignored (the `if (!btn || btn.disabled) return;` guard).

This provides a smooth user experience, prevents abuse, and keeps the UI responsive.
To remove the sidebar badge counters, we need to update only two files:

index.php – remove the <span class="nav-badge"> elements from the sidebar navigation items.

js/modules/navigation.js – empty the updateBadges() function so it no longer tries to update those elements.

## 1.1.7

folder structure

```
/var/www/crm/                      (web root)
├── index.php                      (redirect to /dashboard/)
├── data/                          (SQLite database – inside root, outside dashboard)
│   ├── tms_database.sq3
│   └── .htaccess                  (deny all access)
└── dashboard/                     (all application files)
    ├── index.php                  (main app shell)
    ├── api.php
    ├── auth.php
    ├── login.html
    ├── luckySheet.html
    ├── socket_notifier.php
    ├── client_ip.php
    ├── css/
    │   └── index.min.css
    ├── js/
    │   ├── core/
    │   ├── services/
    │   └── modules/
    ├── uploads/                   (for file attachments – inside dashboard)
    │   └── tasks/
    └── socket/                    (Node.js server, if any)
```

Given your specified paths (`/var/www/crm` as the web root, `/var/www/crm/dashboard` for all application files, and `/var/www/crm/data/tms_database.sq3` for the SQLite database), here is the tailored migration plan.

---

## 1. New Folder Structure

```
/var/www/crm/                      (web root)
├── index.php                      (redirect to /dashboard/)
├── data/                          (SQLite database – inside root, outside dashboard)
│   ├── tms_database.sq3
│   └── .htaccess                  (deny all access)
└── dashboard/                     (all application files)
    ├── index.php                  (main app shell)
    ├── api.php
    ├── auth.php
    ├── login.html
    ├── luckySheet.html
    ├── socket_notifier.php
    ├── client_ip.php
    ├── css/
    │   └── index.min.css
    ├── js/
    │   ├── core/
    │   ├── services/
    │   └── modules/
    ├── uploads/                   (for file attachments – inside dashboard)
    │   └── tasks/
    └── socket/                    (Node.js server, if any)
```

**Key points:**

- The `data` folder is **inside** the web root but **outside** the `dashboard` subfolder. To prevent direct HTTP access, place an `.htaccess` file inside `data/` with `Require all denied`.
- All PHP, HTML, JS, and CSS files now reside in `dashboard/`.
- Uploads remain in `dashboard/uploads/` (you can keep it there or move it to the root – we’ll keep it inside `dashboard` for simplicity).

---

## 2. File Modifications

### 2.1 Root `index.php` (new)

Create `/var/www/crm/index.php`:

```php
<?php
header('Location: dashboard/index.php');
exit;
```

### 2.2 `dashboard/api.php` and `dashboard/auth.php`

Update the `DATA_DIR` constant to point to the new location **relative to the current file**:

```php
// In both api.php and auth.php
define('DATA_DIR', __DIR__ . '/../data');  // resolves to /var/www/crm/data
```

**Also set `UPLOAD_DIR`** (only in `api.php`):

```php
define('UPLOAD_DIR', __DIR__ . '/uploads');  // stays inside dashboard/
```

**Remove or update `.htaccess` creation** for the `data` folder if you prefer to place it manually (optional). The code in `ensureDirectory()` and `ensureHtaccess()` will still work; it will create `.htaccess` inside the new `data` location.

### 2.3 `dashboard/auth.php` – Redirects after login/logout

Update the redirect paths to point to the dashboard:

```php
// On successful login (inside the login action)
header('Location: dashboard/index.php');
exit;

// On logout
header('Location: ../login.html');  // or '/dashboard/login.html' – be consistent
```

Since `auth.php` is now inside `dashboard/`, a logout redirect to `login.html` should use `../login.html` or an absolute path.

**Better:** Use absolute paths from the root:

```php
header('Location: /dashboard/login.html');
```

### 2.4 `dashboard/index.php` – Asset Paths

Add a `<base>` tag inside the `<head>` to make all relative paths point to the `dashboard/` folder:

```html
<head>
    <base href="/dashboard/">
    <!-- rest of head -->
</head>
```

This way, all `<link>`, `<script>`, and other relative URLs (e.g., `css/index.min.css`, `js/core/api-client.js`) will resolve correctly without changing every path.

**Alternatively**, you can change every asset path to root‑relative (e.g., `/dashboard/css/index.min.css`). The `<base>` tag is simpler.

**Update the `logout()` function** inside the HTML (it already points to `auth.php?action=logout`, which is in the same directory – unchanged).

### 2.5 `dashboard/login.html`

The form action is `auth.php?action=login` – since both files are in the same folder, no change needed.

The fetch to `auth.php?action=check` also remains relative.

### 2.6 JavaScript Files (all in `dashboard/js/`)

- `js/core/api-client.js` – `API_ENDPOINT` remains `'api.php'` (relative to the HTML, which is in `dashboard/`).
- `js/modules/session.js` – uses `auth.php?action=check` – no change.
- `js/modules/socket-handler.js` – if your Socket.IO server path changes, update `SOCKET_PATH` accordingly; otherwise, keep as is.
- Other files that use `API_ENDPOINT` (e.g., `attachments.js`, `employee-directory.js`) remain unchanged.

### 2.7 `dashboard/luckySheet.html`

The file uses `api.php?action=serveFile` – since it’s in the same folder, no change.

### 2.8 `dashboard/socket_notifier.php`

If it uses `__DIR__` to locate something, it’s fine; no changes needed.

---

## 3. Database and Directory Permissions

- Create the `data` folder:

```bash
mkdir -p /var/www/crm/data
chmod 755 /var/www/crm/data
chown www-data:www-data /var/www/crm/data   # adjust to your web server user
```

- Place an `.htaccess` inside `data/` to deny access:

```
Require all denied
```

- Ensure the `uploads` directory is writable:

```bash
mkdir -p /var/www/crm/dashboard/uploads/tasks/files
mkdir -p /var/www/crm/dashboard/uploads/tasks/images
chmod -R 755 /var/www/crm/dashboard/uploads
chown -R www-data:www-data /var/www/crm/dashboard/uploads
```

---

## 4. Additional Considerations

- **Existing data:** If you already have a SQLite database file, move it from the old location to `/var/www/crm/data/tms_database.sq3`. Make sure the file is writable by the web server.
- **Existing uploads:** If you had uploads in the old `uploads/` folder, move them to `/var/www/crm/dashboard/uploads/` or adjust the `UPLOAD_DIR` constant to point to the old location if you want to keep them elsewhere.
- **.htaccess rules:** If you have any rewrite rules in the root `.htaccess` that reference `api.php` or other files, update them to point to the `dashboard/` subfolder, or remove them if not needed.

---

## 5. Testing Checklist

- [ ]  Move all application files to `/var/www/crm/dashboard/`.
- [ ]  Create root `index.php` with redirect.
- [ ]  Update `DATA_DIR` in `api.php` and `auth.php` to `__DIR__ . '/../data'`.
- [ ]  Update `UPLOAD_DIR` in `api.php` (if keeping inside `dashboard/`, use `__DIR__ . '/uploads'`).
- [ ]  Add `<base href="/dashboard/">` to `dashboard/index.php`.
- [ ]  Update redirects in `auth.php` to absolute paths (`/dashboard/index.php` and `/dashboard/login.html`).
- [ ]  Create `data/` folder and set permissions.
- [ ]  Move the existing SQLite file to the new location.
- [ ]  Move any existing upload files to the new uploads folder (if needed).
- [ ]  Test login, logout, all CRUD operations, file uploads, and real‑time updates.

---

By following this plan, you will have the database securely placed inside the CRM root but outside the web‑accessible `dashboard` folder, while all application files live neatly in a subdirectory. The `<base>` tag ensures all asset references work without rewriting every path.