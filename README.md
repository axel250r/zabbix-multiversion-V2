# Zabbix Report Tool: PDF, Excel, SLA & Maintenance Manager

<p align="center">
  <a href="https://github.com/axel250r/zabbix-multiversion-V2/stargazers"><img alt="GitHub Stars" src="https://img.shields.io/github/stars/axel250r/zabbix-multiversion-V2?style=for-the-badge&logo=github&label=Stars"></a>
  <a href="https://paypal.me/axel250r"><img alt="Donate" src="https://img.shields.io/badge/Donate-PayPal-00457C?logo=paypal&logoColor=white&style=for-the-badge"></a>
</p>

<p align="center">
  <img alt="Zabbix" src="https://img.shields.io/badge/Zabbix-6.0%20%7C%206.4%20%7C%207.0%20%7C%207.4-D40000">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-%E2%89%A5%207.2-777BB4?logo=php&logoColor=white">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/github/license/axel250r/zabbix-multiversion-V2"></a>
  <a href="https://github.com/axel250r/zabbix-multiversion-V2/issues"><img alt="Issues" src="https://img.shields.io/github/issues/axel250r/zabbix-multiversion-V2"></a>
  <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/"><img alt="LinkedIn" src="https://img.shields.io/badge/LinkedIn-Axel%20Del%20Canto-0A66C2?logo=linkedin&logoColor=white"></a>
</p>

<p align="center">
  <strong>Created by <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/">Axel Del Canto</a></strong><br>
  <sub>Open source. Free forever. Built for the Zabbix community.</sub>
</p>

---

> **If this tool saves you time at work, a ⭐ star takes two seconds and helps other Zabbix admins find it.**

---

## What is this?

A PHP web application that installs alongside your existing Zabbix server and adds a modern reporting and maintenance interface. No modifications to Zabbix required.

**Compatible with Zabbix 6.0 · 6.4 · 7.0 · 7.4**

---

## ✨ Features

### 📄 PDF Report Generator
- 5-step wizard: select hosts → templates → items → time range → generate
- Inline graph preview with 6 chart types (line, area, bar, spline, step, scatter)
- Full progress tracking during generation

### 📊 Excel Export — 4 report types
| Report | Description |
|--------|-------------|
| General Host List | All monitored hosts with status |
| Detailed Host Inventory | OS, RAM, CPU min/avg/peak, memory, disks, uptime |
| Problem Report | Alerts and events for a selected period |
| Peaks Report | CPU and memory peak values per host |

### 📈 SLA Compliance Report
- ICMP-based availability calculated from **trigger events** (not just ping checks)
- Shows real SLA%, total downtime, and every incident with start/end/duration
- Export to HTML view or CSV
- Multi-version compatible (6.0 through 7.4)

### 🔧 Maintenance Manager
- List all maintenances with live status (Active / Scheduled / Expired)
- Create maintenances with full schedule support:
  - One-time, Daily, Weekly, Monthly
  - Monthly supports **Day of month** and **Day of week** modes (identical to Zabbix UI)
- Add hosts to existing maintenances
- Export host lists per maintenance to CSV
- Host autocomplete search

### 🖥️ Latest Data Explorer
- Browse and filter all monitored items across hosts and groups
- Real-time autocomplete for hosts and groups
- Paginated table with inline filtering
- One-click export to PDF

---

## 🎨 Interface

- Modern dark/light theme with persistent preference
- Custom background image support
- Fully responsive
- Bilingual: **English / Spanish**
- Sticky topbar with glassmorphism cards

---

## 🔒 Security

- Session-based authentication via Zabbix API
- CSRF protection on all forms
- No data stored outside your Zabbix database

---

## 📚 Installation & Documentation

Quick install on the Zabbix server (package installs use `/usr/share/zabbix`):

```bash
cd /usr/share/zabbix
git clone https://github.com/axel250r/zabbix-multiversion-V2.git zabbix-report
```

Then edit `config.php` (`ZABBIX_URL`, `ZABBIX_TZ`, API user) and open `http://your-server/zabbix/zabbix-report/`.
Run `check_requirements.php` if something does not work.

Step-by-step guides with screenshots, in English and Spanish:

| | |
|---|---|
| 🇬🇧 English | [PDF report how-to](https://github.com/axel250r/How-To/blob/main/pdf-report/HOWTO-EN.md) |
| 🇪🇸 Español | [Guía del informe PDF](https://github.com/axel250r/How-To/blob/main/pdf-report/HOWTO-ES.md) |
| Word manuals (Excel, SLA, maintenances) | [axel250r/How-To](https://github.com/axel250r/How-To) |

---

## 🤝 Contributing

Found a bug? Open an issue with:
- Your Zabbix version
- What you expected vs what happened
- Any error messages

Have an improvement? Pull requests are welcome.

**Please open an issue before submitting a large PR** so we can discuss the approach first.

---

## 💙 Support the project

This tool is free and always will be. If it saves you time at work:

**⭐ Star the repo** — it costs nothing and helps more people find it  
**☕ Buy me a coffee** — keeps me motivated to keep improving it

<p align="center">
  <a href="https://paypal.me/axel250r">
    <img src="https://img.shields.io/badge/Buy_me_a_coffee-PayPal-00457C?logo=paypal&logoColor=white&style=for-the-badge" alt="Donate via PayPal">
  </a>
</p>

---

<p align="center">
  <sub>© 2025–2026 Axel Del Canto •
  <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/">LinkedIn</a> •
  <a href="https://github.com/axel250r">GitHub</a></sub>
</p>
