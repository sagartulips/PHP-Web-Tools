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

1. Upload the desired PHP files to your server (e.g., inside a protected admin directory or project root).  
2. Access them via your browser (e.g., `https://example.com/db.php`).  
3. Use each tool as intended — ensure proper security and access control.

> **Note:**  
> These tools can be placed in the **root directory** of your project or in **any desired location** on your server, depending on your setup.  
> Just make sure they have access to the required files or database connections.

---

## ⚠️ Security Note

These tools provide **direct database access and file operations**, so:
- Only upload them to **secure** and **non-public** directories.
- Restrict access using server authentication (e.g., `.htaccess` or IP restrictions).
- Remove them after use.

---

## 📄 License

This project is released for **general purpose and development use**.  
It is provided *“as is”* without any warranty or guarantee of fitness for a particular purpose.  
The authors and contributors shall not be held liable for any damage, data loss, or issues arising from its use.  
Please exercise caution when deploying in production environments.
