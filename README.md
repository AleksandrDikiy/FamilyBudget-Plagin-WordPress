# 💰 Family Budget — WordPress Plugin

> A custom WordPress plugin for comprehensive family financial management, featuring multi-family support, categories, transactions, and analytics.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 📋 Table of Contents

- [About](#about)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Architecture & Security](#architecture--security)
- [Development Standards](#development-standards)
- [Changelog](#changelog)
- [License](#license)

---

## About

**Family Budget** is a robust WordPress plugin designed for personal and family expense tracking. It allows multiple families to manage incomes and expenses independently, analyze financial activity by categories and timeframes, and manage multiple accounts/wallets.

The plugin is developed with strict adherence to **WordPress Coding Standards (WPCS)**, focusing on security, data isolation between users, and optimal performance.

---

## Features

### 👨�👩�👧�👦 Family Management
- Create and edit multiple independent "Families".
- Bind WordPress users to specific families.
- Complete data isolation between different family groups.

### 💳 Accounts & Wallets
- Unlimited accounts per family (Cash, Cards, Savings, etc.).
- Real-time balance tracking.
- Internal transfers between accounts.

### 📊 Transactions & Analytics
- Income and expense tracking with categorization.
- Advanced filtering by date, account, and type.
- Monthly/Quarterly/Annual reports with visual charts.
- Automated currency rate updates (via NBU API integration).

### 🔐 Security & Performance
- Two-layer access verification (User Roles + Nonces).
- Full protection against CSRF and SQL injection.
- Optimized MySQL schema with custom indexing for high-speed data retrieval.

---

## Requirements

| Dependency   | Minimum Version |
|--------------|-----------------|
| WordPress    | 6.0             |
| PHP          | 8.0             |
| MySQL/MariaDB| 5.7 / 10.3      |

---

## Architecture & Security

### Secure Database Queries
All database interactions use `$wpdb->prepare()` to prevent SQL injection:

```php
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fb_transactions WHERE family_id = %d",
        $family_id
    )
);