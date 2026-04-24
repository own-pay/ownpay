<div align="center">

# 🚀 Own Pay
### **Enterprise-Grade. Self-Hosted. Open Source.**
**"Your Payment Gateway, Your Server, Your Data."**

[![Status: Active Development](https://img.shields.io/badge/Status-Active_Development-blue.svg?style=for-the-badge)](https://www.facebook.com/share/p/1HsvshfrLr/)
[![Release: Public Beta Coming Soon](https://img.shields.io/badge/Release-Public_Beta_(v0.1.0)-orange.svg?style=for-the-badge)]()
[![License: AGPL 3.0](https://img.shields.io/badge/License-AGPL_3.0-success.svg?style=for-the-badge)](https://github.com/own-pay/ownpay/blob/11e0cf5711bd63ee8857778549fe7bb8c0c1ac20/LICENSE)
[![PHP: 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg?style=for-the-badge&logo=php)]()

[Official Website: ownpay.org](https://ownpay.org)

</div>

---

## 📖 About Own Pay

**Own Pay** is a hyper-modern, self-hosted payment gateway automation platform. It is built for developers, entrepreneurs, and businesses who refuse to compromise on data sovereignty. In an era of opaque SaaS platforms, Own Pay gives you the keys to your own financial infrastructure.

> **Note** This project is a heavily refactored, modernized, and rebranded evolution fork of [**PipraPay**](https://github.com/PipraPay/PipraPay) project, now operating under a completely new architectural philosophy.

---

## 💡 The Vision: Why Own Pay?

The fintech ecosystem is often locked behind high fees and third-party servers. Own Pay breaks these barriers:

* **Data Sovereignty:** Every transaction log, customer detail, and API key stays on *your* server. No middleman.
* **Self-Hosted Independence:** Deploy on your own VPS or dedicated hardware. You own the uptime.
* **Forever Free & Open Source:** Licensed under AGPL-3.0, ensuring the core remains free for the community, forever.
* **No Hidden Fees:** Eliminate the "Platform Tax" often charged by centralized automation services.

---

## 🚧 Current Status: Alpha Testing (v0.0.3)

**Important Notice:** The source code is currently private while we undergo rigorous internal testing.
We have reached **100% Core Development**, but our internal **Alpha v0.0.3 audit** identified several critical bugs and architectural security vulnerabilities. 

**We will not push the code until it is safe for the community.** The first public code drop will happen with the **Public Beta (v0.1.0)** release. 

> **Action:** Click the **`⭐ Star`** button to get notified exactly when the Public Beta goes live!

---

## 🏗️ Technical Architecture & Stack

Own Pay does not use standard monolithic frameworks. It is built on a **Custom Enterprise PHP Framework** designed for high-performance fintech operations.

* **Service-Oriented Architecture (SOA):** Decoupled layers for Repositories, Services, and Controllers.
* **Universal Plugin System:** Extensibility is at our core. Add payment gateways, SMS providers, or new features via ZIP-based modules without touching the core.
* **Robust Hook System:** A powerful event-driven engine using the naming convention: `{domain}.{entity}.{event}`.
* **Security First:** * Strictly Typed PHP 8.2+.
    * Zero-Trust capability matrices for plugins.
    * Encrypted data-at-rest for sensitive configurations.
* **Database:** Highly optimized MySQL/MariaDB schema with tenant-scoping readiness.

---

## 🗺️ Development Roadmap

- [x] **The Foundation** - Core SOA Refactoring, DI Container, and Request Lifecycle.
- [x] **Plugin Engine** - Implementation of the Universal Plugin & Hook System.
- [x] **UI/UX Revamp** - Dynamic Checkout Theme Engine and Admin Bento UI.
- [ ] **Security Hardening (Current)** - Patching Alpha v0.0.3 vulnerabilities and final audits.
- [ ] **Public Beta Release** - Official Code Drop and `ownpay.org` launch.
- [ ] **Ecosystem Expansion** - Developer SDKs and official Documentation Portal.

---

## 🏆 Proud Sponsors & Supporters

Own Pay is powered by the community and visionary partners:

### **Strategic Partner & Infrastructure Sponsor**
[<img width="256" height="40" alt="FlexoHostHorizontalDark" src="https://github.com/user-attachments/assets/bb1c2083-f8c2-40d3-a618-a378921af8ae" />](https://flexohost.com)**[FlexoHost](https://flexohost.com)** — Officially sponsoring our Domain (`ownpay.org`) and Premium Hosting infrastructure.

---

## 🤝 Community & Contributions

We are a community-first project. You can contribute even before the code is public:

1.  **UI/UX Design:** Are you a professional designer? We are looking for an elite **Logo Design** and Brand Identity.
2.  **Sponsorship:** We are open to CDN, Cloud, or Security tool sponsorships.
3.  **Stars:** Every Star motivates our team to squash bugs faster!

---

## 🔗 Stay Connected
Follow the development journey and join the discussion:
- **Facebook Discussion:** [Join the conversation](https://www.facebook.com/share/p/1HsvshfrLr/)
- **Github Discussions:** [Discussions](https://github.com/own-pay/ownpay/discussions)

---

## 🙏 Acknowledgments & Credits

* **[Abdullah Bin Ziad](https://www.facebook.com/mdabdullahbinziad) (Founder of FlexoHost):** For his outstanding dual contribution - suggesting the name **"Own Pay"** and providing the infrastructure to bring it to life.
* **The Contributors:** To everyone who participated in the renaming polls and provided architectural feedback.

---

## 📄 License

Own Pay is licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**. This ensures that any enhancements to the core remain open-source for the benefit of the entire community.

---
<div align="center">
  <i>"Control your payments. Own your future."</i> <br>
  <b>Built by the Community, for the Community.</b>
</div>
