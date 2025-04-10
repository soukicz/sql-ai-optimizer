CREATE TABLE IF NOT EXISTS run (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL,
    use_real_query INTEGER NOT NULL DEFAULT 0,
    use_database_access INTEGER NOT NULL DEFAULT 0,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    input TEXT,
    output TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `group` (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    run_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    FOREIGN KEY (run_id) REFERENCES run(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS query (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    digest TEXT NOT NULL,
    run_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    schema TEXT NOT NULL,
    normalized_query TEXT,
    impact_description TEXT,
    fix_input TEXT,
    fix_output TEXT,
    real_query TEXT,
    FOREIGN KEY (group_id) REFERENCES `group`(id) ON DELETE CASCADE
); 