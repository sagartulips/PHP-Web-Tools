# PHP-Web-Tools

A collection of simple and useful PHP-based tools for web developers and administrators.  
These scripts can be used independently for database management, WordPress maintenance, or general server utilities.

---

## 🧰 Included Tools

### **1. db.php**
A lightweight database management tool based on **Adminer**.  
It allows you to connect and manage MySQL databases directly from the browser with a minimal interface.

### **2. unzip.php**
A custom PHP utility for handling ZIP archives.  
You can **create** or **extract** ZIP files directly from your web server — helpful for quick deployments or file management tasks.

### **3. wp-sr.php**
A custom **WordPress database Search and Replace** tool.  
Primarily designed for updating URLs or text in a WordPress database, but can also be used with any standard MySQL database.

### **4. wp-pr.php**
A **WordPress Table Prefix Replace** tool.  
Useful for changing the table prefix in a WordPress database (e.g., from `wp_` to another prefix).  
Can also be used on non-WordPress databases with similar structure.

---

## ⚙️ Usage

1. Upload the desired PHP files to your server (e.g., inside a protected admin directory).  
2. Access them via your browser (e.g., `https://example.com/db.php`).  
3. Use each tool as intended — ensure proper security and access control.

---

## ⚠️ Security Note

These tools provide **direct database access and file operations**, so:
- Only upload them to **secure** and **non-public** directories.
- Restrict access using server authentication (e.g., `.htaccess` or IP restrictions).
- Remove them after use.

---

## 📄 License

This project is released for **personal and development use**.  
Use at your own risk.

---

