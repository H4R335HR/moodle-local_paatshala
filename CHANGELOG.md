# Changelog

## 0.1.0 (2026101100) - Phase 0: skeleton

- Plugin skeleton for Moodle 3.8 (requires 2019111800).
- Admin settings `local_paatshala/enabled` (default off), `allowedcourses` and
  `allowedcategories` (comma-separated ids, `*`, or empty for none).
- Capability `local/paatshala:use` (no default archetypes).
- Journal table `local_paatshala_journal` and privacy provider.
- `local_paatshala_info`.
- GitHub Actions with moodle-plugin-ci 3.4.14: MOODLE_38_STABLE on PHP 7.1 +
  PostgreSQL and PHP 7.4 + MariaDB.
