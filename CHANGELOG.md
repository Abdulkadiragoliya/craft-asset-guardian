# Changelog

All notable changes to **Asset Guardian** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 - 2026-10-07

### Added
- Unified Asset Health Dashboard with deterministic 100-point hygiene score.
- Comprehensive reference scanning across entries, Matrix blocks (including nested entries), CKEditor, Redactor, categories, users, and globals.
- Three-tier explainable risk assessment engine (`low`, `medium`, `high`).
- Content-hash duplicate detection (MD5) with transactional primary repointing.
- Large files inspector with multi-threshold filtering (1 MB, 5 MB, 10 MB, 50 MB, 100 MB).
- Image accessibility center with inline AJAX alt text quick-save.
- Multi-attribute Asset Explorer with server-side filtering and pagination.
- Safe Cleanup Center featuring dry-run simulations, soft delete to native Craft Trash, and audit logging.
- Scan and cleanup audit history with one-click restore capabilities.
- Executive reporting with 4 downloadable CSV datasets.
- CLI console commands (`asset-guardian/scan`, `asset-guardian/health`, `asset-guardian/cleanup`).
- 13 granular Control Panel user permissions.
- PHPUnit test suite for health scoring, risk evaluation, and settings.
