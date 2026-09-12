# Empower Investment Club — Production Deployment Guide
**Version:** 1.0.0  
**Date:** 2026-09-12  
**Status:** ✅ Code is now production-ready

---

## ✅ FIXES COMPLETED

All 6 deployment blockers have been fixed:

1. ✅ **APP_URL** now reads from environment variable
2. ✅ **Database credentials** work cross-platform (env vars + fallback)
3. ✅ **SMTP credentials** work cross-platform (env vars + fallback)
4. ✅ **Push notification config** work cross-platform (env vars + fallback)
5. ✅ **HTTPS redirect** added to `.htaccess` (commented out for dev)
6. ✅ **Security headers** added (HSTS, X-Frame-Options, etc.)
7. ✅ **Session security** enabled in production mode

---

## 🚀 DEPLOYMENT STEPS

### Step 1: Choose Your Hosting

**Recommended Option: VPS Hosting**
- **Cost:** UGX 150,000 - 300,000/month
- **Specs:** 2 CPU, 4GB RAM, 40GB SSD
- **Providers:**
  - DigitalOcean ($6/month droplet)
  - Vultr (good African routing)
  - Linode/Akamai
  - Local: Sasahost Uganda, Truehost VPS

**Budget Option: Shared Hosting**
- **Cost:** UGX 50,000 - 150,000/month
- **Requirements:** PHP 8.1+, MySQL 5.7+, SSL certificate
- **Providers:** Truehost Uganda, Web4Africa, HostAfrica

---

### Step 2: Server Setup (VPS Only — Skip if Shared Hosting)

```bash
# Connect to your server
ssh root@your-server-ip

# Update system
sudo apt update && sudo apt upgrade -y

# Install LAMP Stack
sudo apt install apache2 mysql-server php8.1 php8.1-cli php8.1-fpm \
  php8.1-mysql php8.1-mbstring php8.1-xml php8.1-curl php8.1-zip \
  php8.1-gd php8.1-bcmath php8.1-intl -y

# Enable Apache modules
sudo a2enmod rewrite headers ssl

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Configure Firewall
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS
sudo ufw enable
```

---

### Step 3: SSL Certificate Setup

```bash
# Install Certbot (Let's Encrypt)
sudo apt install certbot python3-certbot-apache -y

# Get SSL certificate (replace yourdomain.com)
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com

# Test auto-renewal
sudo certbot renew --dry-run
```

---

### Step 4: Database Setup

```bash
# Secure MySQL installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p
```

```sql
-- Create database
CREATE DATABASE empower_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create application user with limited privileges
CREATE USER 'empower_app'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD_HERE';

-- Grant only necessary permissions (NO DDL rights)
GRANT SELECT, INSERT, UPDATE, DELETE ON empower_db.* TO 'empower_app'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;

-- Exit MySQL
EXIT;
```

**IMPORTANT:** Save the password you just created — you'll need it for environment variables.

---

### Step 5: Set Environment Variables

Create `/etc/environment` or use cPanel's environment variables section:

```bash
# Edit environment file
sudo nano /etc/environment

# Add these variables (replace with your actual values):
APP_ENV=production
APP_URL=https://yourdomain.com

DB_HOST=localhost
DB_NAME=empower_db
DB_USER=empower_app
DB_PASS=YOUR_SECURE_PASSWORD_HERE

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-gmail-app-password
SMTP_FROM=noreply@yourdomain.com
SMTP_FROM_NAME="Empower Investment Club"

# Optional: Push Notifications (generate keys first)
VAPID_PUBLIC_KEY=your-public-key
VAPID_PRIVATE_KEY=your-private-key
```

**Save and reload:**
```bash
source /etc/environment
```

**For cPanel hosting:** Use the "Environment Variables" section in your control panel.

---

### Step 6: Upload Application Files

**Option A: Git Clone (Recommended)**
```bash
# Navigate to web root
cd /var/www/html

# Clone repository
sudo git clone https://github.com/yourusername/empower.git
cd empower

# Install dependencies
composer install --no-dev --optimize-autoloader
```

**Option B: FTP/SFTP Upload**
- Upload all files from `C:\xampp\htdocs\Empower` to your server's web root
- Exclude: `/vendor`, `/backups`, `/results`, `/.history`, `/.claude`
- Then SSH in and run: `composer install --no-dev --optimize-autoloader`

---

### Step 7: Set File Permissions

```bash
# Navigate to project directory
cd /var/www/html/empower

# Set ownership to web server user
sudo chown -R www-data:www-data .

# Set directory permissions
sudo find . -type d -exec chmod 755 {} \;

# Set file permissions
sudo find . -type f -exec chmod 644 {} \;

# Make backups directory writable
sudo chmod -R 775 backups
```

---

### Step 8: Configure Apache Virtual Host

```bash
# Create virtual host configuration
sudo nano /etc/apache2/sites-available/empower.conf
```

Add this configuration:

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    DocumentRoot /var/www/html/empower
    
    <Directory /var/www/html/empower>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/empower_error.log
    CustomLog ${APACHE_LOG_DIR}/empower_access.log combined
    
    # Redirect HTTP to HTTPS (after SSL is set up)
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =yourdomain.com [OR]
    RewriteCond %{SERVER_NAME} =www.yourdomain.com
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    DocumentRoot /var/www/html/empower
    
    <Directory /var/www/html/empower>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/empower_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/empower_ssl_access.log combined
    
    # SSL Configuration (certbot will add these automatically)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>
</IfModule>
```

**Enable site and restart Apache:**
```bash
sudo a2ensite empower.conf
sudo a2dissite 000-default.conf  # Disable default site
sudo systemctl restart apache2
```

---

### Step 9: Enable HTTPS Redirect in Code

Now that SSL is working, uncomment the HTTPS redirect in `.htaccess`:

```bash
cd /var/www/html/empower
sudo nano .htaccess
```

Find these lines and **remove the `#` comments**:
```apache
# RewriteCond %{HTTPS} off
# RewriteCond %{HTTP_HOST} !^localhost [NC]
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Should become:
```apache
RewriteCond %{HTTPS} off
RewriteCond %{HTTP_HOST} !^localhost [NC]
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

### Step 10: Import Database

**From your XAMPP backup:**
```bash
# On your local machine, create a fresh backup
cd C:\xampp\htdocs\Empower
# Use the existing backup download feature in Settings > Database

# Upload the SQL file to your server
scp empower_backup_20260912.sql root@your-server-ip:/tmp/

# On the server, import it
mysql -u empower_app -p empower_db < /tmp/empower_backup_20260912.sql

# Verify import
mysql -u empower_app -p empower_db -e "SHOW TABLES;"
```

---

### Step 11: Configure Daily Automated Backups

```bash
# Create backup script
sudo nano /usr/local/bin/empower-backup.sh
```

Add this script:
```bash
#!/bin/bash
BACKUP_DIR="/var/backups/empower"
DATE=$(date +%Y%m%d_%H%M%S)
FILENAME="empower_db_${DATE}.sql"

# Create backup directory if it doesn't exist
mkdir -p $BACKUP_DIR

# Create backup
mysqldump -u empower_app -pYOUR_PASSWORD_HERE empower_db > $BACKUP_DIR/$FILENAME

# Compress backup
gzip $BACKUP_DIR/$FILENAME

# Delete backups older than 30 days
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete

echo "Backup completed: ${FILENAME}.gz"
```

**Make it executable:**
```bash
sudo chmod +x /usr/local/bin/empower-backup.sh
```

**Add to cron (runs daily at 2 AM):**
```bash
sudo crontab -e

# Add this line:
0 2 * * * /usr/local/bin/empower-backup.sh >> /var/log/empower-backup.log 2>&1
```

---

### Step 12: Testing Checklist

Before announcing to users, test everything:

**Authentication & Users:**
- [ ] Login as admin
- [ ] Login as treasurer
- [ ] Login as member (portal)
- [ ] Password reset works
- [ ] Logout works

**Member Management:**
- [ ] Register new member
- [ ] Edit member
- [ ] View member
- [ ] Member status change

**Savings:**
- [ ] Record deposit
- [ ] Record withdrawal
- [ ] View savings account
- [ ] Print statement

**Loans:**
- [ ] Create loan application
- [ ] Submit for approval
- [ ] Approve loan (chairman)
- [ ] Disburse loan (loans officer)
- [ ] Record repayment
- [ ] View loan schedule

**Fees:**
- [ ] Charge registration fee
- [ ] Mark fee paid
- [ ] View fee charges

**Accounting:**
- [ ] Record expense
- [ ] Post expense to GL
- [ ] Record other income
- [ ] Post income to GL
- [ ] View trial balance
- [ ] Generate income statement
- [ ] Generate balance sheet

**Email & Notifications:**
- [ ] Test email sending (statement email)
- [ ] Check notification bell works
- [ ] Test push notifications (if enabled)

**Reports:**
- [ ] Member list export
- [ ] Loan aging report
- [ ] Savings report
- [ ] Financial reports

**Security:**
- [ ] HTTP redirects to HTTPS ✓
- [ ] Session works (login persists)
- [ ] CSRF protection works (try resubmit)
- [ ] Can't access /app/ directly
- [ ] Can't access /backups/ directly

---

### Step 13: Go Live!

**Final checks:**
```bash
# Verify environment variables are set
printenv | grep -E "APP_|DB_|SMTP_|VAPID_"

# Check Apache is running
sudo systemctl status apache2

# Check MySQL is running
sudo systemctl status mysql

# Check SSL certificate
sudo certbot certificates

# Check disk space
df -h

# Check recent errors
sudo tail -n 50 /var/log/apache2/empower_error.log
```

**Monitor for first 24 hours:**
- Apache error log: `sudo tail -f /var/log/apache2/empower_error.log`
- PHP error log: `sudo tail -f /var/log/php8.1-fpm.log`
- Application behavior (all features working?)
- Server performance (CPU, memory, disk)

---

## 🔧 TROUBLESHOOTING

### Issue: "Database connection failed"

**Check:**
```bash
# Are environment variables set?
printenv | grep DB_

# Can PHP connect to MySQL?
php -r "new PDO('mysql:host=localhost;dbname=empower_db', 'empower_app', 'YOUR_PASSWORD');"

# Check MySQL is running
sudo systemctl status mysql
```

### Issue: "Email not sending"

**Check:**
```bash
# Are SMTP variables set?
printenv | grep SMTP_

# Test port 587 is open
telnet smtp.gmail.com 587

# Check firewall isn't blocking outbound SMTP
sudo ufw status
```

### Issue: "404 Not Found" or ".htaccess not working"

**Fix:**
```bash
# Enable mod_rewrite
sudo a2enmod rewrite

# Check AllowOverride in virtual host is set to "All"
sudo nano /etc/apache2/sites-available/empower.conf

# Restart Apache
sudo systemctl restart apache2
```

### Issue: "Permission denied" errors

**Fix:**
```bash
cd /var/www/html/empower
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 backups
```

### Issue: "Session not working across page loads"

**Check:**
```bash
# Is session directory writable?
ls -la /var/lib/php/sessions

# If not, fix permissions
sudo chmod 1733 /var/lib/php/sessions
```

---

## 📊 MONITORING & MAINTENANCE

### Weekly Tasks
- [ ] Check backup logs: `cat /var/log/empower-backup.log`
- [ ] Review error logs for unusual patterns
- [ ] Check disk space: `df -h`
- [ ] Verify SSL certificate status: `sudo certbot certificates`

### Monthly Tasks
- [ ] Review user accounts (disable inactive)
- [ ] Archive old backups to external storage
- [ ] Test backup restoration procedure
- [ ] Update system packages: `sudo apt update && sudo apt upgrade`
- [ ] Review and optimize slow database queries

### Quarterly Tasks
- [ ] Change database password
- [ ] Review access logs for suspicious activity
- [ ] Update application dependencies: `composer update`
- [ ] Perform security audit

---

## 🆘 EMERGENCY CONTACTS

**Server Issues:**
- Hosting provider support: [Your provider's number]
- Server administrator: [Your contact]

**Application Issues:**
- Developer: [Your contact]
- System admin: [Your contact]

**SSL Certificate:**
- Let's Encrypt support: https://community.letsencrypt.org/

---

## 📱 IMPORTANT URLS

**Production Site:**
- Main: https://yourdomain.com
- Admin: https://yourdomain.com/index.php?page=login
- Member Portal: https://yourdomain.com/index.php?page=portal-home

**Monitoring:**
- Server status: https://uptime.yourdomain.com (setup required)
- Error logs: SSH required

---

## ✅ POST-DEPLOYMENT CHECKLIST

After deployment is complete:

- [ ] Update DNS records to point to new server
- [ ] Notify all users of new URL
- [ ] Archive XAMPP installation (don't delete yet)
- [ ] Document any production-specific configurations
- [ ] Train administrators on production environment
- [ ] Set up monitoring/alerting (UptimeRobot, etc.)
- [ ] Change all default passwords
- [ ] Test backup restoration procedure
- [ ] Create disaster recovery documentation
- [ ] Schedule first database cleanup/optimization

---

**DEPLOYMENT COMPLETE! 🎉**

Your Empower Investment Club system is now running in production with:
- ✅ SSL/HTTPS encryption
- ✅ Secure database credentials
- ✅ Automated daily backups
- ✅ Security headers configured
- ✅ Session security enabled
- ✅ Email notifications working
- ✅ Role-based access control
- ✅ CSRF protection
- ✅ SQL injection protection
- ✅ Full audit trail

---

**Need help?** Contact your system administrator or refer to `DEPLOYMENT_READINESS_AUDIT.md` for detailed technical documentation.
