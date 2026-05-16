# Prompt: Database Schema Generation

Create a suggested database markdown file in DBML format, put it in the `@docs/database-schema.md` file. It should follow the requirements for the `@docs/user-stories.md` and `@docs/project-description.md`. Follow best practices and typical conventions for Laravel 13 databases.

---

## Guidelines for Laravel DB

### Enums & Lookup Tables

- **Don't use Enum DB fields or string-based enum columns.**
- For any field that represents a set of predefined values (status, type, category, priority, role, etc.), **always create a lookup/auxiliary table** with a foreign key relationship.
    - Example: instead of a `status` string/enum column, create a `statuses` table with at least an `id` and `name` column, and reference it as `status_id` (foreign key) in the main table.
    - The lookup table should include `id`, `name`, and optionally `slug`, `description`, `is_active`, and timestamp columns as needed.
    - This applies to all categorical/enumerable fields: statuses, types, categories, priorities, levels, roles (domain-specific), etc.

### File/Image Uploads
- For file or image uploads, store the file path as a **string column** directly in the table (e.g., `avatar_path`, `document_path`, `attachment_path`).
- Use descriptive column names with a `_path` suffix to indicate it stores a file path.
- If a record can have multiple files, create a related table (e.g., `project_attachments`) with a `file_path` string column and a foreign key to the parent table.