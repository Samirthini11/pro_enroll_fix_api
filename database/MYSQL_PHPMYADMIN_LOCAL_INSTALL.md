# MySQL + phpMyAdmin — local installation (Windows)

Step-by-step guide to run **MySQL 8** and **phpMyAdmin** on your PC for the **Pro-Enroll API** (`pro_enroll` database).

**Target OS:** Windows 10 / 11  
**Project paths:** `D:\krishna\pro_enroll_api`  
**Default API DB settings:** see [`.env.example`](../.env.example)

| Setting | Local default |
|---------|----------------|
| Host | `127.0.0.1` |
| Port | `3306` |
| Database | `pro_enroll` |
| User | `root` |
| Password | *(empty on fresh XAMPP, or what you set)* |

---

## Choose an install method

| Method | Best for | Includes |
|--------|----------|----------|
| **A. XAMPP** (recommended) | PHP + MySQL + phpMyAdmin in one installer | Apache, PHP, MySQL, phpMyAdmin |
| **B. MySQL Installer + phpMyAdmin** | You already have PHP elsewhere | MySQL only, then phpMyAdmin in Apache |

Most developers on Windows use **Option A** because the Pro-Enroll API also needs PHP.

---

## Option A — XAMPP (recommended)

### 1. Download

1. Open [https://www.apachefriends.org/download.html](https://www.apachefriends.org/download.html)
2. Download **XAMPP for Windows** (PHP 8.2+ build if available).
3. Run the installer **as Administrator**.

### 2. Install

1. Install to e.g. `C:\xampp` (avoid paths with spaces if possible).
2. In the component list, ensure these are checked:
   - **Apache**
   - **MySQL**
   - **PHP**
   - **phpMyAdmin**
3. Finish the installer. You can skip Bitnami extras.

### 3. Start services

**Control Panel**

1. Open **XAMPP Control Panel** (Start menu → XAMPP).
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

Both should show a green “Running” state.

**Command line (optional)**

```powershell
cd C:\xampp
.\mysql_start.bat
```

Apache is only required for phpMyAdmin in the browser (not for `php -S` API server).

### 4. Open phpMyAdmin

1. In the browser go to: **http://localhost/phpmyadmin**
2. Default login (fresh XAMPP):
   - **Username:** `root`
   - **Password:** *(leave empty)*

If login fails, see [Troubleshooting](#troubleshooting) below.

### 5. Create the `pro_enroll` database

**Using phpMyAdmin**

1. Click **Databases** in the top menu.
2. Database name: `pro_enroll`
3. Collation: `utf8mb4_unicode_ci`
4. Click **Create**.

**Using SQL tab**

```sql
CREATE DATABASE IF NOT EXISTS pro_enroll
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 6. Import table structure

**phpMyAdmin**

1. Select database **`pro_enroll`** in the left sidebar.
2. Open the **Import** tab.
3. **Choose file:** `D:\krishna\pro_enroll_api\database\schema.sql`
4. Format: SQL  
5. Click **Import** at the bottom.

You should see tables: `professionals`, `otp_requests`, `professional_skills`.

**Command line (alternative)**

```powershell
cd D:\krishna\pro_enroll_api
C:\xampp\mysql\bin\mysql.exe -u root -p < database\schema.sql
```

Press Enter when password is empty, or type your root password.

### 7. Point the API at MySQL

```powershell
cd D:\krishna\pro_enroll_api
copy .env.example .env
notepad .env
```

Set:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pro_enroll
DB_USER=root
DB_PASS=
```

If you set a root password in XAMPP, put it in `DB_PASS`.

### 8. Verify connection

Start the API:

```powershell
cd D:\krishna\pro_enroll_api
php -S localhost:8080 -t public
```

In phpMyAdmin → **pro_enroll** → **professionals** → **Browse**, rows may appear after you sign in through the app with `USE_API=true`.

Table reference: [MYSQL_TABLE_STRUCTURE.md](./MYSQL_TABLE_STRUCTURE.md)

---

## Option B — MySQL Installer + phpMyAdmin

Use this if PHP is already installed separately and you do not want XAMPP.

### 1. Install MySQL 8

1. Download **MySQL Installer** from [https://dev.mysql.com/downloads/installer/](https://dev.mysql.com/downloads/installer/)
2. Choose **MySQL Server 8.0** (and optionally **MySQL Workbench**).
3. Setup type: **Developer Default** or **Server only**.
4. Set a **root password** — remember it for `.env` and phpMyAdmin.
5. Windows service name: usually **MySQL80** — set **Start at boot** if you like.
6. Port: **3306** (default).

Confirm MySQL is running:

```powershell
Get-Service MySQL80
```

Status should be **Running**.

### 2. Install phpMyAdmin

phpMyAdmin is a PHP app; it needs a web server.

**Simple approach:** install **XAMPP** anyway but only use Apache + phpMyAdmin, and point MySQL to your existing MySQL80 service (advanced), **or** use **MySQL Workbench** instead of phpMyAdmin for GUI.

**Typical approach with XAMPP MySQL disabled:**

1. Install XAMPP; start **Apache** only.
2. Copy phpMyAdmin is already under `C:\xampp\phpMyAdmin`.
3. Edit `C:\xampp\phpMyAdmin\config.inc.php`:

```php
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['user'] = 'root';
$cfg['Servers'][$i]['password'] = 'your_mysql_root_password';
```

4. Open http://localhost/phpmyadmin

Then follow **Option A** steps 5–8 (create DB, import `schema.sql`, configure `.env`).

### 3. Add MySQL to PATH (optional)

```powershell
[Environment]::SetEnvironmentVariable(
  "Path",
  $env:Path + ";C:\Program Files\MySQL\MySQL Server 8.0\bin",
  "User"
)
```

Restart PowerShell, then:

```powershell
mysql -u root -p -e "SHOW DATABASES;"
```

---

## Set a root password (recommended)

Empty root password is fine for **local dev only**. For a slightly safer setup:

**phpMyAdmin**

1. **User accounts** → **root** → **localhost** → **Change password**
2. Update `DB_PASS` in `pro_enroll_api\.env`

**SQL**

```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'YourLocalPassword';
FLUSH PRIVILEGES;
```

---

## Create a dedicated app user (optional)

Instead of `root` in production-like local setups:

```sql
CREATE USER 'pro_enroll'@'localhost' IDENTIFIED BY 'local_dev_password';
GRANT ALL PRIVILEGES ON pro_enroll.* TO 'pro_enroll'@'localhost';
FLUSH PRIVILEGES;
```

`.env`:

```env
DB_USER=pro_enroll
DB_PASS=local_dev_password
```

---

## Troubleshooting

### MySQL will not start in XAMPP (port 3306 in use)

Another MySQL or MariaDB may be running.

```powershell
netstat -ano | findstr :3306
```

- Stop **MySQL80** service if you installed standalone MySQL and only want XAMPP:

```powershell
Stop-Service MySQL80
```

- Or change XAMPP MySQL port in `C:\xampp\mysql\bin\my.ini` (e.g. `3307`) and set `DB_PORT=3307` in `.env`.

### phpMyAdmin: “Cannot connect: invalid settings”

- Confirm **MySQL** is running in XAMPP.
- Check username `root` and password in `config.inc.php` match your server.
- Try host `127.0.0.1` instead of `localhost` if socket errors appear on Windows.

### phpMyAdmin: “Access denied for user 'root'@'localhost'”

Password was set but phpMyAdmin still uses empty password — update `config.inc.php` or reset password via MySQL command line.

### API: database connection errors

1. MySQL service running.
2. Database `pro_enroll` exists.
3. `.env` values match phpMyAdmin login.
4. PHP **pdo_mysql** enabled — in XAMPP, check `C:\xampp\php\php.ini`:

```ini
extension=pdo_mysql
extension=mysqli
```

(Uncomment by removing `;` at the start of the line, then restart Apache if using it.)

### Import `schema.sql` fails on “database already exists”

Safe to ignore, or import only the `CREATE TABLE` sections into existing `pro_enroll`.

### `mysql` command not found

Use full path:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -p
```

---

## Daily workflow checklist

| Step | Action |
|------|--------|
| 1 | Start **MySQL** (XAMPP or Windows service) |
| 2 | Open **http://localhost/phpmyadmin** when you need GUI |
| 3 | Run API: `php -S localhost:8080 -t public` in `pro_enroll_api` |
| 4 | Flutter: `--dart-define=USE_API=true --dart-define=API_BASE_URL=http://localhost:8080` |

---

## Security notes

- Use **empty root password** or weak passwords **only on your local machine**.
- Do not expose port **3306** to the internet on your router.
- Never commit `.env` with real passwords to git.
- Production should use strong passwords, least-privilege DB users, and no phpMyAdmin on public URLs.

---

## Related docs

| Document | Description |
|----------|-------------|
| [schema.sql](./schema.sql) | Executable DDL |
| [MYSQL_TABLE_STRUCTURE.md](./MYSQL_TABLE_STRUCTURE.md) | Table and column reference |
| [../README.md](../README.md) | API setup and run |
