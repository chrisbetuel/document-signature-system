- [x] Locate upload endpoint and error source
- [x] Fix `includes/config.php` missing `getDB()` by adding PDO connection + constants
- [x] Fix wrong DB name causing `Unknown database`
- [ ] Import `schema.sql` into `document_signature` (create tables like `categories`, `documents`)
- [ ] Retry upload and confirm next runtime step works
- [ ] If `schema.sql` import is not feasible, add SQL-table auto-create fallback in `api/upload.php`/`includes/config.php`

