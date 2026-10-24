---
name: database
description: Analyze and change persistence safely across SQL, NoSQL, ORMs, and query builders.
---

# Database

Discover the database engine, ORM or query builder, migrations, seeders, relationships, constraints, indexes, backup assumptions, and existing query conventions.

Do not change a schema without checking its consumers, data constraints, migration behavior, rollback strategy, and performance impact. Avoid unbounded queries, protect sensitive fields, and test empty, duplicate, missing, and large-data cases.

Design indexes from measured query patterns rather than from every filtered column. Preserve existing data, make migration and rollback behavior explicit, and separate read-only inspection from state-changing operations. For critical writes, verify transaction boundaries, concurrency behavior, foreign keys, uniqueness, and failure rollback. Never run migrations, seeds, destructive SQL, or production data operations without explicit authorization and a documented target.
