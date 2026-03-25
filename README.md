# ? Family Budget ? WordPress Plugin

> A custom WordPress plugin for comprehensive family financial management, featuring multi-family support, categories, transactions, and analytics.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## ? Table of Contents

- [About](#about)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Project Roadmap](#-project-roadmap)
- [Architecture & Security](#architecture--security)
- [License](#license)

---

## About

**Family Budget** is a robust WordPress plugin designed for personal and family expense tracking. It allows multiple families to manage incomes and expenses independently, analyze financial activity by categories and timeframes, and manage multiple accounts/wallets.

The plugin is developed with strict adherence to **WordPress Coding Standards (WPCS)**, focusing on security, data isolation between users, and optimal performance.

---

## Features

### ??????? Family Management
- Create and edit multiple independent "Families".
- Bind WordPress users to specific families.
- Complete data isolation between different family groups.

### ? Accounts & Wallets
- Unlimited accounts per family (Cash, Cards, Savings, etc.).
- Real-time balance tracking.
- Internal transfers between accounts.

### ? Transactions & Analytics
- Income and expense tracking with categorization.
- Advanced filtering by date, account, and type.
- Monthly/Quarterly/Annual reports with visual charts.
- Automated currency rate updates via NBU API integration.

### ? Security & Performance
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

## ? Installation

1. **Upload**: Upload the `family-budget` folder to the `/wp-content/plugins/` directory.
2. **Activate**: Activate the plugin through the 'Plugins' menu in WordPress.
3. **Setup**: Go to the 'Family Budget' menu in your dashboard to create your first family and accounts.

---

## ?? Project Roadmap

### Phase 1: Core Enhancement (Current Focus)
- [ ] **PHP 8.4 Support**: Refactoring legacy code to utilize union types, readonly properties, and constructor property promotion.
- [ ] **NBU API Integration**: Finalizing the automated currency rate synchronization via WP-Cron.
- [ ] **Advanced Indexing**: Implementing FULLTEXT search for transaction descriptions.

### Phase 2: User Experience & UI
- [ ] **Interactive Dashboards**: Transitioning from static tables to dynamic charts using Chart.js.
- [ ] **Mobile-First Views**: Optimizing the admin interface for smartphones.

### Phase 3: Advanced Features
- [ ] **Import/Export Engine**: Tools to import data from CSV/Excel banking exports.
- [ ] **REST API Endpoints**: Secure endpoints for future mobile app integrations.

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
```
---
Data Access Layer
The plugin implements a strict access control layer ensuring that users can only interact with data belonging to their assigned family ID.

License
This plugin is released under the GNU General Public License v2.0 or later.