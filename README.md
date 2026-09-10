# Empower Investment Club Management System

> PHP 8+ · MySQL · Bootstrap 5 · Vanilla JS · MVC Architecture

---

## Setup

### 1. Clone / copy into XAMPP
Place the project folder inside `C:\xampp\htdocs\`:
```
C:\xampp\htdocs\empower\
```

### 2. Create the database
1. Start Apache and MySQL in XAMPP.
2. Open **phpMyAdmin** (`http://localhost/phpmyadmin`).
3. Import `database/schema.sql`.

### 3. Seed the admin password
Run the seed script **once** from the XAMPP shell or browser:
```
php C:\xampp\htdocs\empower\database\seed.php
```
Default credentials after seeding:
| Field    | Value                 |
|----------|-----------------------|
| Email    | admin@empower.local   |
| Password | Admin@1234            |

> **Change this password immediately after first login.**

### 4. Configure the app
Edit `app/config/config.php` — update `APP_URL` if your folder name differs.
Edit `app/config/database.php` — update `DB_USER` / `DB_PASS` if needed.

### 5. Open in browser
```
http://localhost/empower/
```

---

## Project Structure

```
empower/
├── app/
│   ├── config/          # config.php, database.php
│   ├── controllers/     # AuthController, DashboardController, …
│   ├── models/          # UserModel, …
│   └── views/
│       ├── auth/        # login.php
│       ├── dashboard/   # index.php
│       ├── layouts/     # main.php, navbar.php, sidebar.php, footer.php
│       └── errors/      # 404.php
├── core/
│   ├── Autoloader.php
│   ├── Controller.php   # Base controller
│   ├── Database.php     # PDO singleton
│   ├── Model.php        # Base model
│   └── Session.php      # Session helper
├── database/
│   ├── schema.sql
│   └── seed.php
├── public/
│   ├── css/app.css
│   └── js/app.js
├── .htaccess
└── index.php            # Front controller
```

---

## Security Notes
- Passwords are hashed with **bcrypt** (cost 12).
- Sessions are regenerated on login.
- Session timeout: 1 hour of inactivity.
- Direct access to `app/`, `core/`, and `database/` is blocked via `.htaccess`.
- All user inputs are sanitised before use.
