# Zabbix Report Tool — PDF, Excel, SLA & Maintenance Manager

<p align="center">
  <a href="https://paypal.me/axel250r">
    <img alt="Donate" src="https://img.shields.io/badge/Donate-PayPal-00457C?logo=paypal&logoColor=white&style=for-the-badge">
  </a>
  <a href="https://github.com/axel250r/zabbix-multiversion/stargazers">
    <img alt="GitHub Stars" src="https://img.shields.io/github/stars/axel250r/zabbix-multiversion?style=social&label=Star">
  </a>
  <a href="https://komarev.com/ghpvc/?username=axel250r">
    <img alt="Views" src="https://komarev.com/ghpvc/?username=axel250r&color=brightgreen&style=flat-square">
  </a>
  <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/">
    <img alt="LinkedIn" src="https://img.shields.io/badge/LinkedIn-Axel%20Del%20Canto-0A66C2?logo=linkedin&logoColor=white">
  </a>
</p>

<p align="center">
  <strong>Created by <a href="https://www.linkedin.com/in/axel-del-canto-del-canto-4ba643186/">Axel Del Canto</a></strong><br>
  <sub>Open source. Free forever. Built for the Zabbix community.</sub>
</p>

---

> **If this tool saves you time at work, a ⭐ star takes 2 seconds and helps others find it.**  
> Hundreds of people use this tool every week. Only a handful have ever clicked star. Be one of them.

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

Step-by-step guides (English & Spanish) in the dedicated how-to repository:

👉 **[axel250r/How-To](https://github.com/axel250r/How-To)**

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
